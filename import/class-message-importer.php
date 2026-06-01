<?php
/**
 * Message Importer — cursor-paginates and imports CRM message history as DT comments.
 *
 * Each message is deduplicated by its CRM message ID stored as comment meta.
 * Attachment URLs are sideloaded via MediaSideloader to replace expiring CDN links.
 * Rate-limit (429) and resource-pending (449) errors are propagated as WP_Error
 * so the calling batch processor can reschedule the remaining contacts.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Disciple_Tools_CRM_Sync_Message_Importer' ) ) {
    /**
     * Imports a Respond.io contact's message history into DT as comments.
     *
     * @package Disciple_Tools
     */
    class Disciple_Tools_CRM_Sync_Message_Importer {

        /**
         * @param Disciple_Tools_CRM_Sync_Abstract_Connector         $connector          Used for get_messages() and get_meta_key_prefix().
         * @param Disciple_Tools_CRM_Sync_Media_Sideloader           $sideloader         Used to download attachment URLs.
         * @param Disciple_Tools_CRM_Sync_Translation_Service|null   $translation_service Optional; when set, translates message text before writing the comment.
         */
        public function __construct(
            private readonly Disciple_Tools_CRM_Sync_Abstract_Connector $connector,
            private readonly Disciple_Tools_CRM_Sync_Media_Sideloader $sideloader,
            private readonly ?Disciple_Tools_CRM_Sync_Translation_Service $translation_service = null
        ) {}

        /**
         * Cursor-paginate the Respond.io message list and import new messages as DT comments.
         *
         * Images and other attachments are sideloaded into the WP Media Library so that
         * expiring Respond.io CDN URLs are replaced with permanent local URLs.
         *
         * Returns WP_Error on 429 / 449 so process_batch() can reschedule remaining contacts.
         *
         * @param string $respond_id  Respond.io contact ID.
         * @param int    $dt_post_id  DT contact post ID.
         * @param int    $last_sync   Unix timestamp of the last successful sync (reserved for
         *                            early-exit optimisation once message sort order is confirmed).
         * @return WP_Error|null
         */
        public function import( // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $last_sync reserved for early-exit optimisation (see commented block below).
            string $respond_id,
            int $dt_post_id,
            int $last_sync = 0
        ): WP_Error|null {
            // These admin functions are not auto-loaded in WP-Cron context.
            // Static flag ensures require_once is only evaluated once per PHP process,
            // regardless of how many contacts are in the batch.
            if ( ! function_exists( 'media_handle_sideload' ) ) {
                require_once ABSPATH . 'wp-admin/includes/media.php';
                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/image.php';
            }

            $cursor_id = null;

            do {
                $page = $this->connector->get_messages( $respond_id, $cursor_id, 100 );

                if ( is_wp_error( $page ) ) {
                    // Propagate 429 / 449 for batch-level rescheduling.
                    return $page;
                }

                $messages = $page['data'] ?? [];
                $cursor_id = ! empty( $page['cursor']['next'] ) ? (string) $page['cursor']['next'] : null;

                // Pre-fetch already-imported message IDs for this page in a single batch query.
                $page_msg_ids = array_values( array_filter(
                    array_map( static fn( $m ) => (string) ( $m['messageId'] ?? '' ), $messages ),
                    static fn( $id ) => '' !== $id
                ) );
                $already_imported = [];
                if ( ! empty( $page_msg_ids ) ) {
                    global $wpdb;
                    $meta_key     = $this->connector->get_meta_key_prefix() . 'message_id';
                    $placeholders = implode( ', ', array_fill( 0, count( $page_msg_ids ), '%s' ) );
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- single batch query replaces O(n) per-message queries.
                    $existing_rows    = $wpdb->get_results(
                        $wpdb->prepare(
                            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$wpdb->commentmeta}/{$wpdb->comments} are WP table names; {$placeholders} is composed of %s literals built from array_fill() and is fully prepared.
                            "SELECT cm.meta_value FROM {$wpdb->commentmeta} cm INNER JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id WHERE cm.meta_key = %s AND c.comment_post_ID = %d AND cm.meta_value IN ({$placeholders})",
                            ...array_merge( [ $meta_key, $dt_post_id ], $page_msg_ids )
                        )
                    );
                    $already_imported = array_flip( array_column( (array) $existing_rows, 'meta_value' ) );
                }

                foreach ( $messages as $msg ) {
                    // messageId is the Respond.io v2 API key used for message deduplication.
                    $msg_id = (string) ( $msg['messageId'] ?? '' );
                    if ( empty( $msg_id ) ) {
                        continue;
                    }

                    // status[0].timestamp is a Unix epoch integer per the Respond.io v2 API.
                    $timestamp = (int) ( $msg['status'][0]['timestamp'] ?? 0 );

                    // Early-exit optimisation disabled — re-enable once sort order of /message/list is confirmed against a live account.
                    // if ( $last_sync > 0 && $timestamp > 0 && $timestamp <= $last_sync ) { // phpcs:ignore Squiz.PHP.CommentedOutCode.Found -- Re-enable once message list sort order is confirmed against a live account.
                    //     $cursor_id = null; // stop the outer do/while
                    //     break;
                    // }

                    // Idempotency: skip if already imported (pre-fetched per page above).
                    if ( isset( $already_imported[ $msg_id ] ) ) {
                        continue;
                    }

                    // Sender label derived from traffic direction and sender source.
                    // traffic: 'incoming' (contact → agent), 'outgoing' (agent → contact), 'internal' (agent note).
                    $traffic = $msg['traffic'] ?? '';
                    $source  = $msg['sender']['source'] ?? '';
                    if ( 'internal' === $traffic ) {
                        $sender = 'Internal Note';
                    } elseif ( 'outgoing' === $traffic || 'user' === $source ) {
                        $sender = 'Agent';
                    } elseif ( 'incoming' === $traffic || 'contact' === $source ) {
                        $sender = 'Contact';
                    } else {
                        $sender = 'Respond.io';
                    }
                    $sender = sanitize_text_field( $sender );

                    // Message body is nested under 'message.text' (confirmed from API docs).
                    $content = wp_kses_post( $msg['message']['text'] ?? '' );

                    // Translate when enabled. Best-effort: on any failure the service
                    // returns the original text and logs the error, so the comment is
                    // never empty and the import is never blocked.
                    if ( null !== $this->translation_service && '' !== $content ) {
                        $translated = $this->translation_service->translate( $content, $respond_id );
                        if ( $translated !== $content ) {
                            $content .= '<br><em>[Translation: ' . esc_html( $translated ) . ']</em>';
                        }
                    }

                    // Attachment sideloading: only triggered when message type is 'attachment'.
                    // URL, mimeType, and filename are nested under the 'message' object.
                    $attachment_html = '';
                    $msg_type        = $msg['message']['type'] ?? '';
                    $media_url       = 'attachment' === $msg_type ? ( $msg['message']['url'] ?? '' ) : '';
                    if ( ! empty( $media_url ) ) {
                        $local_url = $this->sideloader->sideload( $media_url, $dt_post_id );
                        if ( ! empty( $local_url ) && $local_url !== $media_url ) {
                            $attachment_html = ' <a href="' . esc_url( $local_url ) . '">[attachment]</a>';
                        } else {
                            // Sideload failed — fall back to original URL as a plain link.
                            $attachment_html = ' <a href="' . esc_url( $media_url ) . '">[attachment]</a>';
                        }
                    }

                    $html = '<p><strong>' . esc_html( $sender ) . ':</strong> '
                            . $content
                            . $attachment_html
                            . '</p>';

                    // comment_date stores site-local time; comment_date_gmt stores UTC.
                    // gmdate() must not be used for comment_date — it would shift all
                    // historical timestamps by the site's UTC offset.
                    $comment_date     = $timestamp > 0
                        ? wp_date( 'Y-m-d H:i:s', $timestamp )
                        : current_time( 'mysql' );
                    $comment_date_gmt = $timestamp > 0
                        ? gmdate( 'Y-m-d H:i:s', $timestamp )
                        : current_time( 'mysql', true );

                    // 7-parameter signature: post_type, post_id, content, type, args,
                    // check_permissions (false = WP-Cron safe), silent.
                    $comment_id = DT_Posts::add_post_comment(
                        'contacts',
                        $dt_post_id,
                        $html,
                        'comment',
                        [
                            'user_id'          => 0,
                            'comment_author'   => $sender,
                            'comment_date'     => $comment_date,
                            'comment_date_gmt' => $comment_date_gmt,
                        ],
                        false, // $check_permissions — no user session in WP-Cron
                        true   // $silent — suppress DT notifications on bulk import
                    );

                    if ( $comment_id && ! is_wp_error( $comment_id ) ) {
                        add_comment_meta( $comment_id, $this->connector->get_meta_key_prefix() . 'message_id', $msg_id, true );
                    }
                }
            } while ( ! empty( $cursor_id ) );

            return null;
        }
    }
}
