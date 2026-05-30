<?php

class CleanupTest extends TestCase {

    /**
     * Verify that plugin activation created the sync-log table.
     * This replaces the previous test_dummy() placeholder and provides a real
     * assertion: if the table is absent, activation or the schema migration failed.
     *
     * @return void
     */
    public function test_logs_table_exists(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'dt_crm_sync_logs';
        $result = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $this->assertSame( $table, $result, "Expected table {$table} to exist after plugin activation." );
    }

    /**
     * Drop all DT-specific database tables created during the test run.
     *
     * @return void
     */
    public static function tearDownAfterClass(): void {
        global $wpdb;
        $wpdb->query( "DROP TABLE IF EXISTS $wpdb->dt_activity_log" );
        $wpdb->query( "DROP TABLE IF EXISTS $wpdb->dt_location_grid" );
        $wpdb->query( "DROP TABLE IF EXISTS $wpdb->dt_notifications" );
        $wpdb->query( "DROP TABLE IF EXISTS $wpdb->dt_reports" );
        $wpdb->query( "DROP TABLE IF EXISTS $wpdb->dt_reportmeta" );
        $wpdb->query( "DROP TABLE IF EXISTS $wpdb->dt_share" );
        $wpdb->query( "DROP TABLE IF EXISTS $wpdb->dt_post_user_meta" );
    }
}
