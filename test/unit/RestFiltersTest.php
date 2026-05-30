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
}
