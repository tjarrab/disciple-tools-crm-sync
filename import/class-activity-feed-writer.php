<?php
/**
 * Activity Feed Writer — upserts a consolidated "Respond.io Notes" comment
 * on a DT contact's activity feed.
 *
 * Fields mapped to '__activity_feed__' in the admin field-mapping UI are
 * collected by the import processor and passed here as a label => value array.
 * All values are formatted into a single HTML comment. On re-import the
 * comment is updated in place (content only — the original timestamp is
 * preserved so the note stays in its chronological position in the feed).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Disciple_Tools_CRM_Sync_Activity_Feed_Writer' ) ) {
    /**
     * Inserts or updates a single consolidated note comment on a DT contact.
     *
     * @package Disciple_Tools
     */
    class Disciple_Tools_CRM_Sync_Activity_Feed_Writer {

        /** Comment author label for the consolidated activity-feed note. */
        private const COMMENT_AUTHOR = 'Respond.io';

        /**
         * Insert or update the consolidated activity-feed note comment.
         *
         * On first import: creates the comment and stores its WP comment ID in
         * post meta so subsequent imports can find and update it in place.
         * On subsequent imports: updates the comment content only — the date/time
         * is intentionally preserved so the note stays in its original position.
         *
         * @param int    $dt_post_id  DT contact post ID.
         * @param array  $fields      [ 'Field Name' => 'sanitized value', ... ]
         * @param string $meta_prefix Connector meta key prefix, e.g. '_respond_io_'.
         */
        public function upsert( int $dt_post_id, array $fields, string $meta_prefix ): void {
            if ( empty( $fields ) ) {
                return;
            }

            $html     = $this->format_comment( $fields );
            $meta_key = $meta_prefix . 'notes_comment_id';

            $comment_id = (int) get_post_meta( $dt_post_id, $meta_key, true );

            if ( $comment_id > 0 && get_comment( $comment_id ) ) {
                // Comment already exists — update content only; date/time is untouched
                // so the note stays in its original chronological position in the feed.
                wp_update_comment(
                    [
                        'comment_ID'      => $comment_id,
                        'comment_content' => $html,
                    ]
                );
                return;
            }

            // First import, or comment was manually deleted — insert a fresh one.
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
         * Format the consolidated comment HTML body.
         *
         * Renders a bold heading followed by one labelled line per field.
         *
         * @param array $fields [ 'Field Name' => 'value', ... ]
         * @return string HTML comment content.
         */
        private function format_comment( array $fields ): string {
            $lines = [
                '<strong>' . esc_html__( 'Respond.io Notes', 'disciple-tools-crm-sync' ) . '</strong>',
            ];
            foreach ( $fields as $label => $value ) {
                $lines[] = '<strong>' . esc_html( $label ) . ':</strong> ' . esc_html( (string) $value );
            }
            return implode( '<br>', $lines );
        }
    }
}
