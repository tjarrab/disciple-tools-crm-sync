<?php
/**
 * Integration tests for Disciple_Tools_CRM_Sync_REST.
 *
 * All tests use the WP REST Server to dispatch requests against the registered
 * routes, exercising authentication, input validation, and option persistence
 * in a real WordPress environment with a live database.
 *
 * Run with: php vendor/bin/phpunit -c phpunit.xml.dist --testdox
 */

class RestApiTest extends TestCase {

    private const NS = '/disciple-tools-crm-sync/v1';

    /** @var int Administrator user ID used by tests that need a valid session. */
    private int $admin_id;

    protected function setUp(): void {
        parent::setUp();
        global $wp_rest_server;

        // Bootstrap the REST server and register all plugin routes.
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        // Create a DT-capable administrator for permission-gated tests.
        $this->admin_id = $this->factory->user->create( [ 'role' => 'administrator' ] );
    }

// Helpers

    private function dispatch( string $method, string $route, array $body = [], bool $authenticated = true ): WP_REST_Response {
        if ( $authenticated ) {
            wp_set_current_user( $this->admin_id );
        } else {
            wp_set_current_user( 0 );
        }

        $request = new WP_REST_Request( $method, self::NS . $route );
        if ( ! empty( $body ) ) {
            $request->set_body( wp_json_encode( $body ) );
            $request->set_header( 'content-type', 'application/json' );
        }

        return rest_get_server()->dispatch( $request );
    }

// Route registration

    public function test_all_expected_routes_are_registered(): void {
        $routes = rest_get_server()->get_routes();

        $this->assertArrayHasKey( self::NS . '/test-connection', $routes );
        $this->assertArrayHasKey( self::NS . '/schema', $routes );
        $this->assertArrayHasKey( self::NS . '/dt-fields', $routes );
        $this->assertArrayHasKey( self::NS . '/contacts', $routes );
        $this->assertArrayHasKey( self::NS . '/import', $routes );
        $this->assertArrayHasKey( self::NS . '/saved-filters', $routes );
        $this->assertArrayHasKey( self::NS . '/translation/models-cache', $routes );
        $this->assertArrayHasKey( self::NS . '/translation/test', $routes );
    }

// Authentication

    public function test_connection_403_unauthenticated(): void {
        $response = $this->dispatch( 'POST', '/test-connection', [], false );
        $this->assertSame( 403, $response->get_status() );
    }

    public function test_schema_403_unauthenticated(): void {
        $response = $this->dispatch( 'GET', '/schema', [], false );
        $this->assertSame( 403, $response->get_status() );
    }

    public function test_saved_filters_403_unauthenticated(): void {
        $response = $this->dispatch( 'GET', '/saved-filters', [], false );
        $this->assertSame( 403, $response->get_status() );
    }

// test-connection

    public function test_connection_200_no_connector(): void {
        // No settings option stored → get_active_connector() returns WP_Error
        // → handler returns HTTP 400 with success=false.
        $response = $this->dispatch( 'POST', '/test-connection' );
        $data     = $response->get_data();

        $this->assertSame( 400, $response->get_status() );
        $this->assertFalse( $data['success'] );
    }

// saved-filters: create

    public function test_create_filter_400_missing_name(): void {
        $response = $this->dispatch( 'POST', '/saved-filters', [
            'interval' => 'hourly',
        ] );

        $this->assertSame( 400, $response->get_status() );
    }

    public function test_create_filter_400_invalid_interval(): void {
        $response = $this->dispatch( 'POST', '/saved-filters', [
            'name'     => 'My Filter',
            'interval' => 'every_minute', // not a valid interval
        ] );

        $this->assertSame( 400, $response->get_status() );
    }

    public function test_create_filter_201_success(): void {
        $response = $this->dispatch( 'POST', '/saved-filters', [
            'name'          => 'Test Filter',
            'interval'      => 'hourly',
            'filter_params' => [ 'search' => '', 'tag' => '' ],
        ] );

        $this->assertSame( 201, $response->get_status() );
        $data = $response->get_data();
        $this->assertArrayHasKey( 'id', $data );

        $filter_id = $data['id'];
        $stored    = get_option( 'dt_crm_sync_saved_filter_' . $filter_id );
        $this->assertNotEmpty( $stored );

        $envelope = json_decode( $stored, true );
        $this->assertSame( 'Test Filter', $envelope['name'] );
        $this->assertSame( 'hourly', $envelope['interval'] );
        $this->assertArrayHasKey( 'filter_params', $envelope );
        $this->assertSame( [ 'search' => '', 'tag' => '' ], $envelope['filter_params'] );
        $this->assertArrayHasKey( 'poll_time', $envelope );
        $this->assertSame( '00:00', $envelope['poll_time'] );
        $this->assertArrayHasKey( 'connector_slug', $envelope );
    }

    public function test_create_filter_adds_id_to_manifest(): void {
        $response = $this->dispatch( 'POST', '/saved-filters', [
            'name'     => 'Manifest Filter',
            'interval' => 'daily',
        ] );

        $data      = $response->get_data();
        $filter_id = $data['id'];
        $manifest  = get_option( 'dt_crm_sync_saved_filters', [] );

        $this->assertContains( $filter_id, $manifest );
    }

// saved-filters: list

    public function test_list_filters_empty(): void {
        // Ensure manifest is empty for this test.
        delete_option( 'dt_crm_sync_saved_filters' );

        $response = $this->dispatch( 'GET', '/saved-filters' );
        $data     = $response->get_data();

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( [], $data['filters'] );
    }

    public function test_list_filters_success(): void {
        // Create a filter directly via the static helper (bypasses REST layer).
        $filter_id = Disciple_Tools_CRM_Sync::create_filter( 'Direct Filter', 'hourly', [], '00:00' );

        $response = $this->dispatch( 'GET', '/saved-filters' );
        $data     = $response->get_data();

        $ids = array_column( $data['filters'], 'id' );
        $this->assertContains( $filter_id, $ids );
    }

// saved-filters: delete

    public function test_delete_filter_removes_it_from_manifest_and_option(): void {
        $filter_id = Disciple_Tools_CRM_Sync::create_filter( 'To Delete', 'hourly', [] );

        $response = $this->dispatch( 'DELETE', '/saved-filters/' . $filter_id );
        $this->assertSame( 200, $response->get_status() );

        // The option and manifest entry should both be gone.
        $manifest = get_option( 'dt_crm_sync_saved_filters', [] );
        $this->assertNotContains( $filter_id, $manifest );
        $this->assertFalse( get_option( 'dt_crm_sync_saved_filter_' . $filter_id, false ) );
    }

// import

    public function test_import_400_missing_ids(): void {
        $response = $this->dispatch( 'POST', '/import', [] );

        $this->assertSame( 400, $response->get_status() );
    }

    public function test_import_queued_status(): void {
        // 60 IDs → 3 batches of 25, 25, 10.
        $ids      = range( 1, 60 );
        $response = $this->dispatch( 'POST', '/import', [ 'ids' => $ids ] );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( 'queued', $data['status'] );
        $this->assertSame( 3, $data['batches'] );

        // Verify WP-Cron has exactly 3 batch events registered.
        $cron        = _get_cron_array();
        $batch_count = 0;
        foreach ( $cron as $hooks ) {
            if ( isset( $hooks['dt_crm_sync_process_batch'] ) ) {
                $batch_count += count( $hooks['dt_crm_sync_process_batch'] );
            }
        }
        $this->assertSame( 3, $batch_count, 'Exactly 3 WP-Cron batch events should be registered.' );
    }

    public function test_import_400_exceeds_limit(): void {
        $ids      = range( 1, 501 );
        $response = $this->dispatch( 'POST', '/import', [ 'ids' => $ids ] );

        $this->assertSame( 400, $response->get_status() );
    }

// Permission-denied (403) for write / authenticated-only endpoints

    public function test_create_filter_403_unauthenticated(): void {
        $response = $this->dispatch( 'POST', '/saved-filters', [ 'name' => 'x', 'interval' => 'hourly' ], false );
        $this->assertSame( 403, $response->get_status() );
    }

    public function test_delete_filter_403_unauthenticated(): void {
        $response = $this->dispatch( 'DELETE', '/saved-filters/fake_id', [], false );
        $this->assertSame( 403, $response->get_status() );
    }

    public function test_import_403_unauthenticated(): void {
        $response = $this->dispatch( 'POST', '/import', [ 'ids' => [ 1 ] ], false );
        $this->assertSame( 403, $response->get_status() );
    }

    public function test_dt_fields_403_unauthenticated(): void {
        $response = $this->dispatch( 'GET', '/dt-fields', [], false );
        $this->assertSame( 403, $response->get_status() );
    }

    public function test_contacts_403_unauthenticated(): void {
        $response = $this->dispatch( 'GET', '/contacts', [], false );
        $this->assertSame( 403, $response->get_status() );
    }

// translation: models-cache

    public function test_translation_models_cache_403_unauthenticated(): void {
        $response = $this->dispatch( 'DELETE', '/translation/models-cache', [], false );
        $this->assertSame( 403, $response->get_status() );
    }

    public function test_translation_models_cache_delete_success(): void {
        // Pre-populate the transient so deletion has something to remove.
        set_transient( 'dt_crm_sync_gemini_models', [ 'models/gemini-1.5-flash' ], 3600 );

        $response = $this->dispatch( 'DELETE', '/translation/models-cache' );
        $this->assertSame( 200, $response->get_status() );

        $data = $response->get_data();
        $this->assertTrue( $data['success'] );

        // Verify transient was deleted.
        $this->assertFalse( get_transient( 'dt_crm_sync_gemini_models' ), 'Transient should be deleted after successful DELETE request.' );
    }

// translation: test

    public function test_translation_test_403_unauthenticated(): void {
        $response = $this->dispatch( 'POST', '/translation/test', [], false );
        $this->assertSame( 403, $response->get_status() );
    }

    public function test_translation_test_400_missing_api_key(): void {
        // No settings saved — API key is missing.
        delete_option( 'dt_crm_sync_translation_settings' );

        $response = $this->dispatch( 'POST', '/translation/test' );
        $this->assertSame( 400, $response->get_status() );

        $data = $response->get_data();
        $this->assertFalse( $data['success'] );
        $this->assertStringContainsString( 'API key', $data['message'] );
    }

    public function test_translation_test_400_missing_model(): void {
        // Save API key but no model selected.
        $encrypted_key = Disciple_Tools_CRM_Sync::encrypt_value( 'test_key_12345' );
        update_option( 'dt_crm_sync_translation_settings', [
            'enabled' => true,
            'api_key' => $encrypted_key,
            'model'   => '', // no model selected
        ] );

        $response = $this->dispatch( 'POST', '/translation/test' );
        $this->assertSame( 400, $response->get_status() );

        $data = $response->get_data();
        $this->assertFalse( $data['success'] );
        $this->assertStringContainsString( 'model', $data['message'] );
    }
}
