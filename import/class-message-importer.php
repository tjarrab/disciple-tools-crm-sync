<?php
/**
 * Message Importer — fetches a Respond.io contact's message history and writes
 * a full conversation log covering both sides of the exchange to DT.
 *
 * On each import the log is rebuilt and upserted (updated in place) so the note
 * stays current without accumulating duplicate entries. By default the log is
 * written as a DT comment. When a target field is configured in the Field Mapping
 * settings the log is written as plain text to that DT field instead.
 *
 * Attachment URLs are sideloaded to replace expiring CDN links with permanent
 * WordPress Media Library URLs.
 *
 * Rate-limit (429) and resource-pending (449) errors are propagated as WP_Error
 * so the calling batch processor can reschedule remaining contacts.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Disciple_Tools_CRM_Sync_Message_Importer' ) ) {
    /**
     * Builds and upserts a full conversation log for a Respond.io contact.
     *
     * @package Disciple_Tools
     */
    class Disciple_Tools_CRM_Sync_Message_Importer {

        /** Comment author label for the conversation log note. */
        private const COMMENT_AUTHOR = 'Respond.io';

        /**
         * @param Disciple_Tools_CRM_Sync_Abstract_Connector         $connector           Active CRM connector. Provides get_messages() and get_meta_key_prefix().
         * @param Disciple_Tools_CRM_Sync_Media_Sideloader           $sideloader          Downloads attachment URLs to the WP Media Library.
         * @param Disciple_Tools_CRM_Sync_Translation_Service|null   $translation_service When set, translates message text before writing the log.
         */
        public function __construct(
            private readonly Disciple_Tools_CRM_Sync_Abstract_Connector $connector,
            private readonly Disciple_Tools_CRM_Sync_Media_Sideloader $sideloader,
            private readonly ?Disciple_Tools_CRM_Sync_Translation_Service $translation_service = null
        ) {}

        /**
         * Fetch and upsert the full conversation log for a contact.
         *
         * Cursor-paginates the Respond.io message list, collects both sides of the
         * conversation, sorts by timestamp ascending, and writes the result. The
         * write target is controlled by $target_field:
         *   - null       → write as a DT comment (the default)
         *   - '__skip__' → do nothing; message import is disabled
         *   - field key  → write plain text to that DT field
         *
         * The note/field value is replaced on each import so it always reflects
         * the current message thread.
         *
         * @param string      $respond_id   Respond.io contact ID.
         * @param int         $dt_post_id   DT contact post ID.
         * @param int         $last_sync    Unix timestamp of the last sync (reserved for future early-exit optimisation). // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
         * @param string|null $target_field null = DT comment, '__skip__' = skip, field key = write to that DT field.
         * @return WP_Error|null
         */
        public function import(
            string $respond_id,
            int $dt_post_id,
            int $last_sync = 0, // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- reserved for early-exit optimisation
            ?string $target_field = null
        ): WP_Error|null {
            if ( '__skip__' === $target_field ) {
                return null;
            }

            // Admin media functions are not auto-loaded in WP-Cron context.
            if ( ! function_exists( 'media_handle_sideload' ) ) {
                require_once ABSPATH . 'wp-admin/includes/media.php';
                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/image.php';
            }

            $collected = [];
            $cursor_id = null;

            do {
                $page = $this->connector->get_messages( $respond_id, $cursor_id, 100 );

                if ( is_wp_error( $page ) ) {
                    return $page;
                }

                $messages  = $page['data'] ?? [];
                $cursor_id = ! empty( $page['cursor']['next'] ) ? (string) $page['cursor']['next'] : null;

                foreach ( $messages as $msg ) {
                    $msg_id = (string) ( $msg['messageId'] ?? '' );
                    if ( empty( $msg_id ) ) {
                        continue;
                    }

                    $timestamp = (int) ( $msg['status'][0]['timestamp'] ?? 0 );

                    // Sender label derived from traffic direction and sender source.
                    // 'outgoing' covers all agent replies, capturing both sides of the exchange.
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

                    $content = wp_kses_post( $msg['message']['text'] ?? '' );

                    if ( null !== $this->translation_service && '' !== $content ) {
                        $translated = $this->translation_service->translate( $content, $respond_id );
                        if ( $translated !== $content ) {
                            $content .= ' [Translation: ' . sanitize_text_field( $translated ) . ']';
                        }
                    }

                    // Attachment sideloading: triggered when message type is 'attachment'.
                    $msg_type  = $msg['message']['type'] ?? '';
                    $media_url = 'attachment' === $msg_type ? ( $msg['message']['url'] ?? '' ) : '';
                    if ( ! empty( $media_url ) ) {
                        $local_url = $this->sideloader->sideload( $media_url, $dt_post_id );
                        $final_url = ( ! empty( $local_url ) && $local_url !== $media_url ) ? $local_url : $media_url;
                        $filename  = sanitize_text_field( $msg['message']['filename'] ?? '' );
                        $label     = '' !== $filename ? '[Attachment: ' . $filename . ']' : '[Attachment]';
                        $content   = '' !== $content
                            ? $content . ' ' . $label
                            : $label;
                        unset( $final_url ); // URL intentionally omitted from log lines (sideloaded links expire).
                    }

                    // Never emit a blank log line — a missing text field on an agent
                    // template message shouldn't silently drop the exchange.
                    if ( '' === $content ) {
                        $content = '[Message]';
                    }

                    $collected[] = [
                        'ts'      => $timestamp,
                        'sender'  => sanitize_text_field( $sender ),
                        'content' => $content,
                    ];
                }
            } while ( ! empty( $cursor_id ) );

            if ( empty( $collected ) ) {
                return null;
            }

            // Sort ascending so the log reads in chronological order.
            usort( $collected, fn( $a, $b ) => $a['ts'] <=> $b['ts'] );

            if ( null === $target_field ) {
                $this->upsert_note( $dt_post_id, $collected );
            } else {
                $this->write_to_field( $dt_post_id, $target_field, $collected );
            }

            return null;
        }

        /**
         * Upsert the conversation log as a DT comment.
         *
         * Stores the comment ID in post meta so subsequent imports update the same
         * comment in place rather than creating a new entry each time. If the comment
         * was manually deleted a fresh one is created in its place.
         */
        private function upsert_note( int $dt_post_id, array $collected ): void {
            $html     = $this->format_html_log( $collected );
            $meta_key = $this->connector->get_meta_key_prefix() . 'message_log_comment_id';

            $comment_id = (int) get_post_meta( $dt_post_id, $meta_key, true );
            if ( $comment_id > 0 && get_comment( $comment_id ) ) {
                wp_update_comment( [
                    'comment_ID'      => $comment_id,
                    'comment_content' => $html,
                ] );
                return;
            }

            $new_id = DT_Posts::add_post_comment(
                'contacts',
                $dt_post_id,
                $html,
                'comment',
                [
                    'user_id'        => 0,
                    'comment_author' => self::COMMENT_AUTHOR,
                ],
                false, // $check_permissions — no user session in WP-Cron
                true   // $silent — suppress DT notifications on bulk import
            );

            if ( $new_id && ! is_wp_error( $new_id ) ) {
                update_post_meta( $dt_post_id, $meta_key, (int) $new_id );
            }
        }

        /**
         * Write the conversation log as plain text to a DT contact field.
         */
        private function write_to_field( int $dt_post_id, string $field_key, array $collected ): void {
            DT_Posts::update_post(
                'contacts',
                $dt_post_id,
                [ $field_key => $this->format_plain_log( $collected ) ],
                true,
                false
            );
        }

        /**
         * Format the conversation log as HTML for a DT comment.
         */
        private function format_html_log( array $collected ): string {
            $lines = [
                '<strong>' . esc_html__( 'Conversation Log (Respond.io)', 'disciple-tools-crm-sync' ) . '</strong>',
            ];
            foreach ( $collected as $entry ) {
                $time_label = $entry['ts'] > 0 ? gmdate( 'Y-m-d H:i', $entry['ts'] ) . ' UTC' : '—';
                $lines[]    = '[' . esc_html( $time_label ) . '] '
                    . '<strong>' . esc_html( $entry['sender'] ) . ':</strong> '
                    . $entry['content'];
            }
            return implode( '<br>', $lines );
        }

        /**
         * Format the conversation log as plain text for a DT field value.
         */
        private function format_plain_log( array $collected ): string {
            $lines = [];
            foreach ( $collected as $entry ) {
                $time_label = $entry['ts'] > 0 ? gmdate( 'Y-m-d H:i', $entry['ts'] ) . ' UTC' : '—';
                $lines[]    = '[' . $time_label . '] ' . $entry['sender'] . ': ' . wp_strip_all_tags( $entry['content'] );
            }
            return implode( "\n", $lines );
        }
    }
}
