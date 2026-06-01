<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Disciple_Tools_CRM_Sync_Translation_Logger' ) ) {
    /**
     * Writes translation results to the dt_crm_sync_translation_logs table.
     *
     * @package Disciple_Tools
     */
    class Disciple_Tools_CRM_Sync_Translation_Logger {

        /**
         * Write a single row to the translation log table.
         *
         * @param string      $respond_id       Respond.io contact ID.
         * @param int|null    $http_status      HTTP status code from the AI provider (null = no API call made).
         * @param string|null $response_preview First 20 characters of the raw API response body.
         * @param string      $status           Outcome: 'success' | 'failed' | 'rate_limited'
         * @param string|null $error_message    Optional error detail.
         */
        public static function write(
            string $respond_id,
            ?int $http_status,
            ?string $response_preview,
            string $status,
            ?string $error_message = null
        ): bool {
            global $wpdb;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional direct insert for append-only translation log; no caching layer is appropriate here.
            return false !== $wpdb->insert(
                $wpdb->prefix . 'dt_crm_sync_translation_logs',
                [
                    'created_at'       => gmdate( 'Y-m-d H:i:s' ),
                    'respond_id'       => $respond_id,
                    'http_status'      => $http_status,
                    'response_preview' => $response_preview !== null ? substr( $response_preview, 0, 20 ) : null,
                    'status'           => $status,
                    'error_message'    => $error_message,
                ],
                [
                    '%s', // created_at
                    '%s', // respond_id
                    '%d', // http_status — $wpdb inserts SQL NULL for PHP null (WP 6.0+)
                    '%s', // response_preview
                    '%s', // status
                    '%s', // error_message
                ]
            );
        }

        /**
         * Create the translation logs table using dbDelta.
         *
         * Safe to call on every activation — dbDelta is idempotent.
         * Caller is responsible for requiring wp-admin/includes/upgrade.php first.
         */
        public static function create_table(): void {
            global $wpdb;

            $table   = $wpdb->prefix . 'dt_crm_sync_translation_logs';
            $charset = $wpdb->get_charset_collate();

            // Do /not/ use DEFAULT CURRENT_TIMESTAMP — dbDelta regex can mishandle it.
            $sql = "CREATE TABLE $table (
            id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at       DATETIME        NOT NULL,
            respond_id       VARCHAR(64)     NOT NULL DEFAULT '',
            http_status      SMALLINT UNSIGNED        DEFAULT NULL,
            response_preview VARCHAR(20)              DEFAULT NULL,
            status           VARCHAR(16)     NOT NULL DEFAULT '',
            error_message    TEXT                     DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY idx_status     (status),
            KEY idx_respond_id (respond_id),
            KEY idx_created_at (created_at)
        ) $charset;";

            dbDelta( $sql );
        }
    }
}
