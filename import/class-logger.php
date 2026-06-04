<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Disciple_Tools_CRM_Sync_Logger' ) ) {
    /**
     * Writes import results to the dt_crm_sync_logs table.
     *
     * @package Disciple_Tools
     */
    class Disciple_Tools_CRM_Sync_Logger {

        /**
         * Write a single row to the sync log table.
         *
         * @param string   $trigger     Origin of the import: 'manual' | 'scheduled' | 'webhook'
         * @param string   $contact_id  The connector's contact ID.
         * @param int|null $dt_post_id  The DT contact post ID, or null if creation failed.
         * @param string   $status      Outcome: 'success' | 'failed' | 'merged' | 'skipped'
         * @param string   $message     Optional detail (error message, merge source ID, etc.)
         */
        public static function write(
            string $trigger,
            string $contact_id,
            ?int $dt_post_id,
            string $status,
            string $message
        ): bool {
            global $wpdb;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional direct insert for append-only sync log; no caching layer is appropriate here.
            return false !== $wpdb->insert(
                $wpdb->prefix . 'dt_crm_sync_logs',
                [
                    'created_at'   => gmdate( 'Y-m-d H:i:s' ),
                    'trigger_type' => $trigger,
                    'contact_id'   => $contact_id,
                    'dt_post_id'   => $dt_post_id,
                    'status'       => $status,
                    'message'      => $message,
                ],
                [
                    '%s', // created_at
                    '%s', // trigger_type
                    '%s', // contact_id
                    '%d', // dt_post_id — $wpdb inserts SQL NULL for PHP null (WP 6.0+); format specifier is bypassed
                    '%s', // status
                    '%s', // message
                ]
            );
        }
    }
}
