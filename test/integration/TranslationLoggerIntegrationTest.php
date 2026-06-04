<?php
/**
 * Integration tests for Disciple_Tools_CRM_Sync_Translation_Logger.
 *
 * Requires a live WordPress database with the dt_crm_sync_translation_logs
 * table created by the plugin's activation hook. All inserts are rolled back
 * by WP_UnitTestCase between tests so the table stays clean.
 *
 * Run with: php vendor/bin/phpunit -c phpunit.xml.dist --testdox
 */

class TranslationLoggerIntegrationTest extends TestCase {

// Helpers

    /** Return the most-recently inserted translation log row. */
    private function last_log_row(): ?stdClass {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Test utility; caching would mask between-test state.
        return $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM `%1s` ORDER BY id DESC LIMIT 1',
                $wpdb->prefix . 'dt_crm_sync_translation_logs'
            )
        );
    }

    /** Count rows currently in the translation log table. */
    private function log_row_count(): int {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Test utility.
        return (int) $wpdb->get_var(
            $wpdb->prepare( 'SELECT COUNT(*) FROM `%1s`', $wpdb->prefix . 'dt_crm_sync_translation_logs' )
        );
    }

// Table existence

    public function test_translation_logs_table_exists_after_activation(): void {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Test assertion.
        $table = $wpdb->get_var(
            $wpdb->prepare(
                'SHOW TABLES LIKE %s',
                $wpdb->esc_like( $wpdb->prefix . 'dt_crm_sync_translation_logs' ) . '%'
            )
        );

        $this->assertNotEmpty( $table, 'dt_crm_sync_translation_logs table should exist after plugin activation.' );
    }

// Column values and data types

    public function test_write_stores_all_attributes_correctly(): void {
        Disciple_Tools_CRM_Sync_Translation_Logger::write( 'rid_123', 200, '{"candidates":[{"c', 'success', null );
        $row = $this->last_log_row();

        $this->assertSame( 'rid_123', $row->contact_id );
        $this->assertSame( 200, (int) $row->http_status );
        $this->assertSame( '{"candidates":[{"c', $row->response_preview );
        $this->assertSame( 'success', $row->status );
        $this->assertNull( $row->error_message );
    }

    public function test_write_stores_null_http_status_when_no_api_call_made(): void {
        Disciple_Tools_CRM_Sync_Translation_Logger::write( 'rid_456', null, null, 'rate_limited', 'Daily limit reached' );
        $row = $this->last_log_row();

        $this->assertNull( $row->http_status, 'http_status should be NULL when no API call was made (rate-limited).' );
        $this->assertSame( 'rate_limited', $row->status );
        $this->assertSame( 'Daily limit reached', $row->error_message );
    }

    public function test_write_stores_null_response_preview_on_failure(): void {
        Disciple_Tools_CRM_Sync_Translation_Logger::write( 'rid_789', 401, null, 'failed', 'API key not valid' );
        $row = $this->last_log_row();

        $this->assertNull( $row->response_preview );
        $this->assertSame( 'failed', $row->status );
        $this->assertSame( 'API key not valid', $row->error_message );
    }

// Response preview truncation

    public function test_write_truncates_response_preview_to_20_characters(): void {
        $long_preview = 'This is a very long response body that exceeds 20 characters';
        Disciple_Tools_CRM_Sync_Translation_Logger::write( 'rid_truncate', 200, $long_preview, 'success', null );
        $row = $this->last_log_row();

        $this->assertSame( 20, strlen( $row->response_preview ), 'response_preview should be truncated to 20 characters.' );
        $this->assertSame( 'This is a very long ', $row->response_preview );
    }

    public function test_write_does_not_pad_short_response_preview(): void {
        $short_preview = 'OK';
        Disciple_Tools_CRM_Sync_Translation_Logger::write( 'rid_short', 200, $short_preview, 'success', null );
        $row = $this->last_log_row();

        $this->assertSame( 'OK', $row->response_preview );
        $this->assertSame( 2, strlen( $row->response_preview ) );
    }

// All valid status values

    /** @dataProvider status_values_provider */
    public function test_write_accepts_all_valid_status_values( string $status ): void {
        Disciple_Tools_CRM_Sync_Translation_Logger::write( 'rid_status_test', 200, null, $status, null );
        $row = $this->last_log_row();

        $this->assertSame( $status, $row->status );
    }

    public static function status_values_provider(): array {
        return [
            'success'       => [ 'success' ],
            'failed'        => [ 'failed' ],
            'rate_limited'  => [ 'rate_limited' ],
        ];
    }

// Multiple writes

    public function test_each_write_call_inserts_a_new_row(): void {
        $before = $this->log_row_count();

        Disciple_Tools_CRM_Sync_Translation_Logger::write( 'rid_a', 200, 'resp_a', 'success', null );
        Disciple_Tools_CRM_Sync_Translation_Logger::write( 'rid_b', 200, 'resp_b', 'success', null );
        Disciple_Tools_CRM_Sync_Translation_Logger::write( 'rid_c', 200, 'resp_c', 'success', null );

        $after = $this->log_row_count();
        $this->assertSame( 3, $after - $before, 'Three write() calls should insert exactly 3 rows.' );
    }

// Timestamp verification

    public function test_write_stores_created_at_timestamp(): void {
        $before = gmdate( 'Y-m-d H:i:s' );
        sleep( 1 ); // Ensure timestamp difference is detectable.
        Disciple_Tools_CRM_Sync_Translation_Logger::write( 'rid_time', 200, null, 'success', null );
        sleep( 1 );
        $after = gmdate( 'Y-m-d H:i:s' );

        $row = $this->last_log_row();
        $this->assertNotEmpty( $row->created_at );
        $this->assertGreaterThanOrEqual( $before, $row->created_at );
        $this->assertLessThanOrEqual( $after, $row->created_at );
    }

// Index verification (via EXPLAIN)

    public function test_status_index_exists(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'dt_crm_sync_translation_logs';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test assertion for index existence.
        $indexes = $wpdb->get_results( "SHOW INDEX FROM `{$table}` WHERE Key_name = 'idx_status'" );

        $this->assertNotEmpty( $indexes, 'Index idx_status should exist on dt_crm_sync_translation_logs table.' );
    }

    public function test_contact_id_index_exists(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'dt_crm_sync_translation_logs';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test assertion for index existence.
        $indexes = $wpdb->get_results( "SHOW INDEX FROM `{$table}` WHERE Key_name = 'idx_contact_id'" );

        $this->assertNotEmpty( $indexes, 'Index idx_contact_id should exist on dt_crm_sync_translation_logs table.' );
    }

    public function test_created_at_index_exists(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'dt_crm_sync_translation_logs';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test assertion for index existence.
        $indexes = $wpdb->get_results( "SHOW INDEX FROM `{$table}` WHERE Key_name = 'idx_created_at'" );

        $this->assertNotEmpty( $indexes, 'Index idx_created_at should exist on dt_crm_sync_translation_logs table.' );
    }
}
