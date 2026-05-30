<?php
/**
 * Integration tests for Disciple_Tools_CRM_Sync_Logger.
 *
 * Requires a live WordPress database with the dt_crm_sync_logs table created
 * by the plugin's activation hook.  All inserts are rolled back by
 * WP_UnitTestCase between tests so the table stays clean.
 *
 * Run with: php vendor/bin/phpunit -c phpunit.xml.dist --testdox
 */

class LoggerTest extends TestCase {

// Helpers

    /** Return the most-recently inserted log row. */
    private function last_log_row(): ?stdClass {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Test utility; caching would mask between-test state.
        return $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM `%1s` ORDER BY id DESC LIMIT 1',
                $wpdb->prefix . 'dt_crm_sync_logs'
            )
        );
    }

    /** Count rows currently in the log table. */
    private function log_row_count(): int {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Test utility.
        return (int) $wpdb->get_var(
            $wpdb->prepare( 'SELECT COUNT(*) FROM `%1s`', $wpdb->prefix . 'dt_crm_sync_logs' )
        );
    }

// Table existence

    public function test_log_table_exists_after_activation(): void {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Test assertion.
        $table = $wpdb->get_var(
            $wpdb->prepare(
                'SHOW TABLES LIKE %s',
                $wpdb->esc_like( $wpdb->prefix . 'dt_crm_sync_logs' ) . '%'
            )
        );

        $this->assertNotEmpty( $table, 'dt_crm_sync_logs table should exist after plugin activation.' );
    }

// Column values

    public function test_write_stores_all_attributes_correctly(): void {
        Disciple_Tools_CRM_Sync_Logger::write( 'manual', 'r_123', 42, 'merged', 'All good' );
        $row = $this->last_log_row();

        $this->assertSame( 'manual', $row->trigger_type );
        $this->assertSame( 'r_123', $row->respond_id );
        $this->assertSame( 42, (int) $row->dt_post_id );
        $this->assertSame( 'merged', $row->status );
        $this->assertSame( 'All good', $row->message );
    }

    public function test_write_stores_null_dt_post_id_for_failed_import(): void {
        Disciple_Tools_CRM_Sync_Logger::write( 'webhook', 'r_003', null, 'failed', 'API error' );
        $row = $this->last_log_row();

        $this->assertNull( $row->dt_post_id );
    }

// All valid status values

    /** @dataProvider status_values_provider */
    public function test_write_accepts_all_valid_status_values( string $status ): void {
        Disciple_Tools_CRM_Sync_Logger::write( 'manual', 'r_status_test', null, $status, '' );
        $row = $this->last_log_row();

        $this->assertSame( $status, $row->status );
    }

    public static function status_values_provider(): array {
        return [
            'success' => [ 'success' ],
            'failed'  => [ 'failed' ],
            'merged'  => [ 'merged' ],
            'skipped' => [ 'skipped' ],
        ];
    }

// Multiple writes

    public function test_each_write_call_inserts_a_new_row(): void {
        $before = $this->log_row_count();

        Disciple_Tools_CRM_Sync_Logger::write( 'manual', 'r_a', 1, 'success', '' );
        Disciple_Tools_CRM_Sync_Logger::write( 'manual', 'r_b', 2, 'success', '' );
        Disciple_Tools_CRM_Sync_Logger::write( 'manual', 'r_c', 3, 'success', '' );

        $this->assertSame( $before + 3, $this->log_row_count() );
    }

// Cleanup

    public static function tearDownAfterClass(): void {
        global $wpdb;
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}dt_crm_sync_logs" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    }

// Timestamp

    public function test_write_stores_created_at_timestamp(): void {
        $before = gmdate( 'Y-m-d H:i:s', time() - 2 );

        Disciple_Tools_CRM_Sync_Logger::write( 'manual', 'r_ts', 1, 'success', '' );
        $row = $this->last_log_row();

        $this->assertGreaterThanOrEqual( $before, $row->created_at );
    }
}
