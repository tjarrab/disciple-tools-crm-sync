<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Disciple_Tools_CRM_Sync_Email_Digest' ) ) {
    /**
     * Builds and sends the daily "contacts imported" email digest.
     *
     * Invoked by WP-Cron via the 'dt_crm_sync_email_digest' hook, scheduled/cleared
     * by Disciple_Tools_CRM_Sync::reschedule_email_digest().
     *
     * @package Disciple_Tools
     */
    class Disciple_Tools_CRM_Sync_Email_Digest {

        /** Statuses that represent a real write to a DT contact. */
        private const IMPORTED_STATUSES = [ 'success', 'merged' ];

        /** Upper bound on contacts listed in a single email -- keeps a large backlog from producing an oversized message. */
        private const MAX_CONTACTS_LISTED = 200;

        /**
         * WP-Cron callback. Builds the digest for the window since the last send
         * and emails it to the configured recipients.
         */
        public static function send(): void {
            $settings = get_option( 'dt_crm_sync_email_notifier_settings', [] );
            $settings = is_array( $settings ) ? $settings : [];

            if ( empty( $settings['enabled'] ) || empty( $settings['recipients'] ) ) {
                return;
            }

            $recipients = array_filter( (array) $settings['recipients'], 'is_email' );
            if ( empty( $recipients ) ) {
                return;
            }

            // Guards against an overlapping WP-Cron trigger sending the same digest twice.
            $lock_key = 'dt_crm_sync_email_digest_lock';
            if ( false !== get_transient( $lock_key ) ) {
                return;
            }
            set_transient( $lock_key, time(), 5 * MINUTE_IN_SECONDS );

            try {
                $last_sent = (int) get_option( 'dt_crm_sync_email_digest_last_sent', 0 );
                if ( 0 === $last_sent ) {
                    $last_sent = time() - DAY_IN_SECONDS;
                }
                $now = time();

                $rows = self::get_imported_contacts( $last_sent, $now );
                $body = self::build_body( $rows, $last_sent, $now );

                $sent = wp_mail(
                    $recipients,
                    self::subject(),
                    $body,
                    [ 'Content-Type: text/html; charset=UTF-8' ]
                );

                Disciple_Tools_CRM_Sync_Logger::write(
                    'scheduled',
                    'email_digest',
                    null,
                    $sent ? 'success' : 'failed',
                    sprintf(
                        'Digest sent to %d recipient(s), reporting %d contact(s).',
                        count( $recipients ),
                        count( $rows )
                    )
                );

                // Always advance the marker, even on failure -- a stuck mail transport
                // must not cause the reported window to keep growing on every run. A
                // missing digest email is the signal for the admin to go investigate.
                update_option( 'dt_crm_sync_email_digest_last_sent', $now );
            } finally {
                delete_transient( $lock_key );
            }
        }

        /**
         * Fetch the most recent log row per contact that was successfully
         * imported or merged within the given time window.
         *
         * @param int $from Unix timestamp, exclusive-ish lower bound.
         * @param int $to   Unix timestamp, upper bound.
         * @return array<int, object> Rows keyed by nothing in particular, deduped by dt_post_id.
         */
        private static function get_imported_contacts( int $from, int $to ): array {
            global $wpdb;

            $table       = $wpdb->prefix . 'dt_crm_sync_logs';
            $placeholders = implode( ',', array_fill( 0, count( self::IMPORTED_STATUSES ), '%s' ) );

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- $table is a trusted constant from $wpdb->prefix; the log table has no caching layer.
            $results = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM `{$table}` WHERE `created_at` >= %s AND `created_at` < %s AND `status` IN ({$placeholders}) AND `dt_post_id` IS NOT NULL ORDER BY `created_at` ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is a fixed count of %s built from a private constant, not user input.
                ...array_merge( [ gmdate( 'Y-m-d H:i:s', $from ), gmdate( 'Y-m-d H:i:s', $to ) ], self::IMPORTED_STATUSES )
            ) );

            // Keep only the latest row per contact -- a contact can be touched more than once in a window.
            $by_post_id = [];
            foreach ( $results as $row ) {
                $by_post_id[ (int) $row->dt_post_id ] = $row;
            }

            return array_values( $by_post_id );
        }

        /**
         * Render the HTML email body.
         *
         * @param array<int, object> $rows      Deduped log rows for the window.
         * @param int                $from      Unix timestamp, window start.
         * @param int                $to        Unix timestamp, window end.
         * @return string
         */
        private static function build_body( array $rows, int $from, int $to ): string {
            $body  = '<p>' . sprintf(
                /* translators: 1: window start date/time, 2: window end date/time */
                esc_html__( 'CRM Sync activity from %1$s to %2$s:', 'disciple-tools-crm-sync' ),
                esc_html( wp_date( 'Y-m-d H:i', $from ) ),
                esc_html( wp_date( 'Y-m-d H:i', $to ) )
            ) . '</p>';

            if ( empty( $rows ) ) {
                $body .= '<p>' . esc_html__( 'No contacts imported today.', 'disciple-tools-crm-sync' ) . '</p>';
                return $body;
            }

            $body .= '<ul>';
            foreach ( array_slice( $rows, 0, self::MAX_CONTACTS_LISTED ) as $row ) {
                $dt_post_id = (int) $row->dt_post_id;
                $link       = get_permalink( $dt_post_id );
                $label      = sprintf(
                    /* translators: %d: DT contact post ID */
                    esc_html__( 'Contact #%d', 'disciple-tools-crm-sync' ),
                    $dt_post_id
                );
                $body      .= $link
                    ? '<li><a href="' . esc_url( $link ) . '">' . $label . '</a></li>'
                    : '<li>' . $label . '</li>';
            }
            $body .= '</ul>';

            $remaining = count( $rows ) - self::MAX_CONTACTS_LISTED;
            if ( $remaining > 0 ) {
                $body .= '<p>' . sprintf(
                    /* translators: %d: number of additional imported contacts not shown in this email */
                    esc_html__( '+ %d more — see the Logs tab for the full list.', 'disciple-tools-crm-sync' ),
                    $remaining
                ) . '</p>';
            }

            return $body;
        }

        /** Build the email subject line. */
        private static function subject(): string {
            return sprintf(
                /* translators: %s: site name */
                __( '[%s] CRM Sync — Daily Import Summary', 'disciple-tools-crm-sync' ),
                get_bloginfo( 'name' )
            );
        }
    }
}
