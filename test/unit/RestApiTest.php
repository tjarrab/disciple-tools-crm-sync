<?php
/**
 * Unit tests for the REST API controllers.
 *
 * Tests permission checking, schema caching/refresh, and the detect_schema_drift()
 * helper — all without touching the database or making real HTTP calls.
 *
 * Uses a TestableConfigController subclass to bypass the abstract register_routes()
 * requirement and to expose the protected detect_schema_drift() method for direct
 * assertion.
 */

use Brain\Monkey\Functions;

// Test harness

/**
 * Concrete subclass of the Config controller that exposes detect_schema_drift()
 * as public for direct testing without needing to register any REST routes.
 */
class TestableConfigController extends Disciple_Tools_CRM_Sync_REST_Config {

    /** Expose protected detect_schema_drift() as public for direct testing. */
    public function expose_detect_schema_drift( array $schema ): void {
        $this->detect_schema_drift( $schema );
    }
}

// Tests

class RestApiTest extends BrainMonkeyTestCase {

    private TestableConfigController $config;

    protected function setUp(): void {
        parent::setUp();
        Functions\when( 'sanitize_key' )->returnArg();
        $this->config = new TestableConfigController();
    }

// has_permission

    public function test_permission_true_with_capability(): void {
        Functions\when( 'current_user_can' )->justReturn( true );

        $this->assertTrue( $this->config->has_permission() );
    }

    public function test_permission_false_without_capability(): void {
        Functions\when( 'current_user_can' )->justReturn( false );

        $result = $this->config->has_permission();
        $this->assertInstanceOf( WP_Error::class, $result );
    }

// handle_get_schema — cache hit

    public function test_schema_cached_without_api(): void {
        $cached = [ [ 'name' => 'field1', 'type' => 'text' ] ];
        Functions\when( 'get_transient' )->justReturn( $cached );
        Functions\expect( 'wp_safe_remote_request' )->never();

        $response = $this->config->handle_get_schema( new WP_REST_Request( 'GET', '' ) );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( $cached, $response->get_data() );
    }

// handle_get_schema — cache miss, no connector

    public function test_schema_400_missing_connector(): void {
        Functions\when( 'get_transient' )->justReturn( false );
        Functions\when( 'get_option' )->justReturn( [] ); // empty settings → no connector
        Functions\when( 'apply_filters' )->alias(
            fn( $hook, $value ) => 'dt_crm_sync_connectors' === $hook ? [] : $value
        );

        $response = $this->config->handle_get_schema( new WP_REST_Request( 'GET', '' ) );

        $this->assertSame( 400, $response->get_status() );
    }

// handle_get_schema — cache miss, valid connector

    public function test_schema_200_cache_miss(): void {
        $schema = [ [ 'name' => 'custom_score', 'type' => 'number' ] ];
        Functions\when( 'get_transient' )->justReturn( false );
        Functions\when( 'set_transient' )->justReturn( true );
        Functions\when( 'get_option' )->alias( function ( $key, $default = false ) use ( $schema ) {
            if ( 'dt_crm_sync_settings' === $key ) {
                return [
                    'active_connector' => 'respond_io',
                    'connectors'       => [ 'respond_io' => [ 'api_url' => 'https://api.test', 'api_token' => 'tok' ] ],
                ];
            }
            return $default;
        } );
        Functions\when( 'apply_filters' )->alias(
            fn( $hook, $value ) => 'dt_crm_sync_connectors' === $hook
                ? [ 'respond_io' => 'Disciple_Tools_CRM_Sync_Connector_Respond_IO' ]
                : $value
        );
        Functions\when( 'add_query_arg' )->justReturn( 'https://api.test/mocked' );
        Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
        Functions\when( 'wp_safe_remote_request' )->justReturn( [ '_mocked' => true ] );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( json_encode( $schema ) );

        $response = $this->config->handle_get_schema( new WP_REST_Request( 'GET', '' ) );

        $this->assertSame( 200, $response->get_status() );
    }

// handle_get_schema — forced refresh

    public function test_schema_refresh_429_cooldown(): void {
        Functions\when( 'get_transient' )->alias( function ( $key ) {
            // Schema transient: not cached. Refresh-lock transient: active.
            return 'dt_crm_sync_schema_refresh_lock' === $key ? 1 : false;
        } );
        Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
            if ( 'dt_crm_sync_settings' === $key ) {
                return [
                    'active_connector' => 'respond_io',
                    'connectors'       => [ 'respond_io' => [ 'api_url' => 'https://api.test', 'api_token' => 'tok' ] ],
                ];
            }
            return $default;
        } );
        Functions\when( 'apply_filters' )->alias(
            fn( $hook, $value ) => 'dt_crm_sync_connectors' === $hook
                ? [ 'respond_io' => 'Disciple_Tools_CRM_Sync_Connector_Respond_IO' ]
                : $value
        );

        $request  = new WP_REST_Request( 'GET', '', [ 'refresh' => '1' ] );
        $response = $this->config->handle_get_schema( $request );

        $this->assertSame( 429, $response->get_status() );
    }

// detect_schema_drift

    public function test_detect_schema_drift_flags_missing_mapping_field_as_broken(): void {
        Functions\when( 'get_option' )->justReturn( [
            'missing_field' => [ 'dt_key' => 'dt_x', 'dt_type' => 'text' ],
        ] );

        $saved = null;
        Functions\when( 'update_option' )->alias( function ( $key, $value ) use ( &$saved ) {
            $saved = $value;
        } );

        // Schema does NOT contain 'missing_field'.
        $this->config->expose_detect_schema_drift( [ [ 'name' => 'other_field' ] ] );

        $this->assertTrue( $saved['missing_field']['broken'] ?? false,
        'A mapping entry whose field is absent from the live schema should be flagged broken.' );
    }

    public function test_schema_drift_clears_stale_flag(): void {
        Functions\when( 'get_option' )->justReturn( [
            'existing_field' => [ 'dt_key' => 'dt_x', 'dt_type' => 'text', 'broken' => true ],
        ] );

        $saved = null;
        Functions\when( 'update_option' )->alias( function ( $key, $value ) use ( &$saved ) {
            $saved = $value;
        } );

        // Schema now contains 'existing_field' — broken flag should be cleared.
        $this->config->expose_detect_schema_drift( [ [ 'name' => 'existing_field' ] ] );

        $this->assertArrayNotHasKey( 'broken', $saved['existing_field'] ?? [],
        'Stale broken flag should be removed when the field reappears in the live schema.' );
    }

    public function test_schema_drift_skips_empty_mapping(): void {
        Functions\when( 'get_option' )->justReturn( [] );
        Functions\expect( 'update_option' )->never();

        $this->config->expose_detect_schema_drift( [ [ 'name' => 'field1' ] ] );
    }

    public function test_schema_drift_skips_no_changes(): void {
        // Field is in both mapping and schema, no broken flag → no change.
        Functions\when( 'get_option' )->justReturn( [
            'stable_field' => [ 'dt_key' => 'dt_y', 'dt_type' => 'text' ],
        ] );
        Functions\expect( 'update_option' )->never();

        $this->config->expose_detect_schema_drift( [ [ 'name' => 'stable_field' ] ] );
    }

    public function test_detect_schema_drift_handles_data_wrapper_schema_shape(): void {
        // Schema wrapped under a 'data' key (live API response envelope).
        Functions\when( 'get_option' )->justReturn( [
            'wrapped_field' => [ 'dt_key' => 'dt_z', 'dt_type' => 'text' ],
        ] );
        Functions\expect( 'update_option' )->never();

        // Pass schema in { data: [ ... ] } envelope — detect_schema_drift() should
        // unwrap the 'data' key and find 'wrapped_field' present, so no change.
        $this->config->expose_detect_schema_drift( [ 'data' => [ [ 'name' => 'wrapped_field' ] ] ] );
    }
}
