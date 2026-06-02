<?php
/**
 * Unit tests for the REST Filters controller — fix 3.20 (recursive sanitization).
 *
 * @package Disciple_Tools_CRM_Sync
 */

use Brain\Monkey\Functions;

// RestFiltersTest

class RestFiltersTest extends BrainMonkeyTestCase {

// handle_create_filter — nested array filter_params (fix 3.20)

    public function test_create_filter_safe_nested_arrays(): void {
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
        Functions\when( 'wp_next_scheduled' )->justReturn( false );
        Functions\when( 'wp_schedule_event' )->justReturn( true );
        Functions\when( 'wp_timezone' )->justReturn(
            new class() {
                public function getName(): string { return 'UTC'; }
            }
        );
        Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
            if ( 'dt_crm_sync_settings' === $key ) {
                return [ 'active_connector' => 'respond_io' ];
            }
            if ( 'dt_crm_sync_saved_filters' === $key ) {
                return [];
            }
            return $default;
        } );
        Functions\when( 'update_option' )->justReturn( true );

        $request = new WP_REST_Request( 'POST', '', [
            'name'         => 'Test Filter',
            'interval'     => 'hourly',
            'filter_params' => [
                'tag'    => [ 'vip', 'hot-lead' ],  // nested array — was crashing before fix
                'search' => 'Smith',
            ],
        ] );

        $controller = new Disciple_Tools_CRM_Sync_REST_Filters();
        $response   = $controller->handle_create_filter( $request );

        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $this->assertSame( 201, $response->get_status(), 'Nested array filter_params must not cause a TypeError.' );
    }

// handle_purge_all_filters

    public function test_purge_all_filters_clears_manifest_and_returns_count(): void {
        $manifest = [ 'filter_abc', 'filter_xyz' ];

        Functions\when( 'sanitize_key' )->returnArg();
        Functions\when( 'get_option' )->alias( function ( $key, $default = false ) use ( $manifest ) {
            if ( 'dt_crm_sync_saved_filters' === $key ) {
                return $manifest;
            }
            return $default;
        } );

        // Both filters must have their poll hook and filter option cleared.
        Functions\expect( 'wp_clear_scheduled_hook' )
            ->times( 5 ) // dt_crm_sync_poll x2, legacy poll_{id} x2, dt_crm_sync_process_batch x1
            ->andReturn( false );
        Functions\when( 'wp_next_scheduled' )->justReturn( false );
        Functions\expect( 'delete_option' )
            ->times( 2 )
            ->andReturn( true );
        Functions\expect( 'update_option' )
            ->once()
            ->with( 'dt_crm_sync_saved_filters', [] )
            ->andReturn( true );

        $controller = new Disciple_Tools_CRM_Sync_REST_Filters();
        $response   = $controller->handle_purge_all_filters();

        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( 'purged', $data['status'] );
        $this->assertSame( 2, $data['filters_cleared'] );
    }

    public function test_purge_all_removes_orphaned_event_not_in_manifest(): void {
        // Manifest is empty — the filter was already removed — but there's still
        // a scheduled cron event sitting in the cron array for it.
        $cron_array = [
            1748871600 => [
                'dt_crm_sync_poll' => [
                    md5( serialize( [ 'filter_orphan' ] ) ) => [ // phpcs:ignore
                        'schedule' => 'daily',
                        'args'     => [ 'filter_orphan' ],
                        'interval' => DAY_IN_SECONDS,
                    ],
                ],
            ],
        ];

        Functions\when( 'sanitize_key' )->returnArg();
        Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
            if ( 'dt_crm_sync_saved_filters' === $key ) {
                return []; // nothing in manifest
            }
            return $default;
        } );
        Functions\when( '_get_cron_array' )->justReturn( $cron_array );

        // Should still clear the poll hook, the legacy variant, and the batch hook.
        Functions\expect( 'wp_clear_scheduled_hook' )
            ->times( 3 ) // dt_crm_sync_poll, legacy poll_filter_orphan, dt_crm_sync_process_batch
            ->andReturn( false );
        Functions\when( 'wp_next_scheduled' )->justReturn( false );
        Functions\expect( 'delete_option' )
            ->once()
            ->andReturn( true );
        Functions\expect( 'update_option' )
            ->once()
            ->with( 'dt_crm_sync_saved_filters', [] )
            ->andReturn( true );

        $controller = new Disciple_Tools_CRM_Sync_REST_Filters();
        $response   = $controller->handle_purge_all_filters();

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( 'purged', $data['status'] );
        $this->assertSame( 1, $data['filters_cleared'], 'Orphaned events must be counted even when not in the manifest.' );
    }
}
