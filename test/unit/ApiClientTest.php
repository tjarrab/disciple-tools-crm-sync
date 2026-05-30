<?php
/**
 * Unit tests for Disciple_Tools_CRM_Sync_API_Client.
 *
 * Tests the HTTP response-handling logic, rate-limit / resource-pending error
 * mapping, transient-based schema caching, and cursor pagination normalisation
 * — all without making real HTTP requests.
 *
 * WP functions (wp_safe_remote_request, add_query_arg, get_transient, etc.) are
 * mocked via Brain Monkey in each test.
 */

use Brain\Monkey\Functions;

class ApiClientTest extends BrainMonkeyTestCase {

// Helpers

    /**
     * Stub the WP HTTP helper functions so request() can complete without a
     * real network call. $status_code and $body drive what the method sees.
     */
    private function stub_http_response( int $status_code, string $body, string $retry_after = '' ): void {
        Functions\when( 'add_query_arg' )->justReturn( 'https://api.test/mocked' );
        Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
        Functions\when( 'wp_safe_remote_request' )->justReturn( [ '_mocked' => true ] );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( $status_code );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( $body );
        Functions\when( 'wp_remote_retrieve_header' )->justReturn( $retry_after );
        Functions\when( 'wp_timezone_string' )->justReturn( 'UTC' );
        Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
    }

    private function make_client(): Disciple_Tools_CRM_Sync_API_Client {
        return new Disciple_Tools_CRM_Sync_API_Client( 'https://api.test', 'test_token' );
    }

// Rate-limit & resource-pending error mapping

    public function test_request_maps_429_to_rate_limited_wp_error(): void {
        $this->stub_http_response( 429, '{"message":"rate limited"}', '30' );

        $result = $this->make_client()->test_connection();

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'rate_limited', $result->get_error_code() );
        $this->assertSame( 30, $result->get_error_data()['retry_after'] );
    }

    public function test_rate_limit_defaults_retry_60(): void {

        $this->stub_http_response( 429, '{}', '0' );

        $result = $this->make_client()->test_connection();

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 60, $result->get_error_data()['retry_after'] );
    }

    public function test_request_maps_449_to_resource_pending_wp_error(): void {
        $this->stub_http_response( 449, '' );

        $result = $this->make_client()->test_connection();

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'resource_pending', $result->get_error_code() );
    }

    public function test_request_propagates_wp_error_from_http_transport(): void {
        Functions\when( 'add_query_arg' )->justReturn( 'https://api.test/mocked' );
        $transport_error = new WP_Error( 'http_request_failed', 'Could not connect' );
        Functions\when( 'wp_safe_remote_request' )->justReturn( $transport_error );

        $result = $this->make_client()->test_connection();

        $this->assertSame( $transport_error, $result );
    }

// test_connection

    public function test_connection_true_on_200(): void {
        $this->stub_http_response( 200, '{"data":[]}' );

        $result = $this->make_client()->test_connection();

        $this->assertTrue( $result );
    }

    public function test_connection_error_on_401(): void {
        $this->stub_http_response( 401, '{"message":"Unauthorized"}' );

        $result = $this->make_client()->test_connection();

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'api_error', $result->get_error_code() );
    }

// Schema transient caching

    public function test_schema_uses_cache(): void {
        $cached = [ [ 'name' => 'custom_field_1', 'type' => 'text' ] ];
        Functions\when( 'get_transient' )->justReturn( $cached );
        Functions\expect( 'wp_safe_remote_request' )->never();

        $result = $this->make_client()->get_field_schema();

        $this->assertSame( $cached, $result );
    }

    public function test_get_field_schema_stores_transient_with_12_hour_ttl_on_cache_miss(): void {
        $schema = [ [ 'name' => 'field1', 'type' => 'text' ] ];
        Functions\when( 'get_transient' )->justReturn( false );
        $this->stub_http_response( 200, json_encode( $schema ) );
        Functions\expect( 'set_transient' )
            ->once()
            ->with( 'dt_crm_sync_field_schema_respond_io', $schema, 12 * HOUR_IN_SECONDS )
            ->andReturn( true );

        $result = $this->make_client()->get_field_schema();
        $this->assertSame( $schema, $result, 'get_field_schema() must return the fetched schema on a cache miss.' );
    }

    public function test_schema_error_on_api_failure(): void {
        Functions\when( 'get_transient' )->justReturn( false );
        $this->stub_http_response( 500, '{"message":"Server Error"}' );

        $result = $this->make_client()->get_field_schema();

        $this->assertInstanceOf( WP_Error::class, $result );
    }

// Cursor pagination normalisation

    public function test_get_contacts_extracts_next_cursor_id_from_pagination_next_url(): void {
        $next_url      = 'https://api.respond.io/v2/contact/list?cursorId=99999&limit=50';
        $response_body = json_encode( [
            'items'      => [ [ 'id' => 1 ], [ 'id' => 2 ] ],
            'pagination' => [ 'next' => $next_url ],
        ] );

        Functions\when( 'wp_timezone_string' )->justReturn( 'UTC' );
        Functions\when( 'add_query_arg' )->justReturn( 'https://api.test/v2/contact/list?limit=50' );
        Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
        Functions\when( 'wp_safe_remote_request' )->justReturn( [ '_mocked' => true ] );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( $response_body );
        Functions\when( 'wp_parse_url' )->alias( 'parse_url' );

        $result = $this->make_client()->get_contacts( [ 'timezone' => 'UTC' ] );

        $this->assertSame( 99999, $result['cursor']['next'] );
        $this->assertCount( 2, $result['data'] );
    }

    public function test_contacts_pagination_null_without_next(): void {
        $response_body = json_encode( [
            'items'      => [ [ 'id' => 5 ] ],
            'pagination' => [],
        ] );

        Functions\when( 'wp_timezone_string' )->justReturn( 'UTC' );
        Functions\when( 'add_query_arg' )->justReturn( 'https://api.test/v2/contact/list?limit=50' );
        Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
        Functions\when( 'wp_safe_remote_request' )->justReturn( [ '_mocked' => true ] );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( $response_body );

        $result = $this->make_client()->get_contacts( [ 'timezone' => 'UTC' ] );

        $this->assertNull( $result['cursor']['next'] );
    }

    public function test_contacts_pagination_null_missing_cursor(): void {
        // pagination.next URL exists but contains no cursorId query parameter.
        $next_url      = 'https://api.respond.io/v2/contact/list?limit=50';
        $response_body = json_encode( [
            'items'      => [ [ 'id' => 10 ] ],
            'pagination' => [ 'next' => $next_url ],
        ] );

        Functions\when( 'wp_timezone_string' )->justReturn( 'UTC' );
        Functions\when( 'add_query_arg' )->justReturn( 'https://api.test/v2/contact/list?limit=50' );
        Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
        Functions\when( 'wp_safe_remote_request' )->justReturn( [ '_mocked' => true ] );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( $response_body );
        Functions\when( 'wp_parse_url' )->alias( 'parse_url' );

        $result = $this->make_client()->get_contacts( [ 'timezone' => 'UTC' ] );

        $this->assertNull( $result['cursor']['next'] );
    }

    public function test_contacts_pagination_null_non_numeric_cursor(): void {

        $next_url      = 'https://api.respond.io/v2/contact/list?cursorId=abc&limit=50';
        $response_body = json_encode( [
            'items'      => [ [ 'id' => 11 ] ],
            'pagination' => [ 'next' => $next_url ],
        ] );

        Functions\when( 'wp_timezone_string' )->justReturn( 'UTC' );
        Functions\when( 'add_query_arg' )->justReturn( 'https://api.test/v2/contact/list?limit=50' );
        Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
        Functions\when( 'wp_safe_remote_request' )->justReturn( [ '_mocked' => true ] );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( $response_body );
        Functions\when( 'wp_parse_url' )->alias( 'parse_url' );

        $result = $this->make_client()->get_contacts( [ 'timezone' => 'UTC' ] );

        $this->assertNull( $result['cursor']['next'] );
    }

    public function test_contacts_pagination_null_no_query(): void {
        // pagination.next URL has no '?' at all — wp_parse_url() returns null for the query component.
        $next_url      = 'https://api.respond.io/v2/contact/list';
        $response_body = json_encode( [
            'items'      => [ [ 'id' => 12 ] ],
            'pagination' => [ 'next' => $next_url ],
        ] );

        Functions\when( 'wp_timezone_string' )->justReturn( 'UTC' );
        Functions\when( 'add_query_arg' )->justReturn( 'https://api.test/v2/contact/list?limit=50' );
        Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
        Functions\when( 'wp_safe_remote_request' )->justReturn( [ '_mocked' => true ] );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( $response_body );
        Functions\when( 'wp_parse_url' )->alias( 'parse_url' );

        $result = $this->make_client()->get_contacts( [ 'timezone' => 'UTC' ] );

        $this->assertNull( $result['cursor']['next'] );
    }

// get_contact

    public function test_contact_decoded_on_200(): void {
        $this->stub_http_response( 200, json_encode( [ 'id' => 42, 'firstName' => 'Jane' ] ) );

        $result = $this->make_client()->get_contact( '42' );

        $this->assertIsArray( $result );
        $this->assertSame( 42, $result['id'] );
        $this->assertSame( 'Jane', $result['firstName'] );
    }

    public function test_contact_error_on_404(): void {
        $this->stub_http_response( 404, '{"message":"Not Found"}' );

        $result = $this->make_client()->get_contact( '99' );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'api_error', $result->get_error_code() );
    }

    public function test_contact_error_on_500(): void {
        $this->stub_http_response( 500, '{"message":"Internal Server Error"}' );

        $result = $this->make_client()->get_contact( '1' );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'api_error', $result->get_error_code() );
    }

// get_message_list

    public function test_messages_data_null_cursor(): void {
        $body = json_encode( [
            'items'      => [ [ 'messageId' => 'm1', 'traffic' => 'incoming' ] ],
            'pagination' => [],
        ] );
        $this->stub_http_response( 200, $body );

        $result = $this->make_client()->get_message_list( '42' );

        $this->assertCount( 1, $result['data'] );
        $this->assertNull( $result['cursor']['next'] );
    }

    public function test_get_message_list_extracts_cursor_id_from_pagination_next_url(): void {
        $body = json_encode( [
            'items'      => [ [ 'messageId' => 'm1' ] ],
            'pagination' => [ 'next' => 'https://api.test/v2/contact/id:42/message/list?cursorId=77&limit=100' ],
        ] );
        Functions\when( 'add_query_arg' )->justReturn( 'https://api.test/mocked' );
        Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
        Functions\when( 'wp_safe_remote_request' )->justReturn( [ '_mocked' => true ] );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( $body );
        Functions\when( 'wp_parse_url' )->alias( 'parse_url' );

        $result = $this->make_client()->get_message_list( '42' );

        $this->assertSame( 77, $result['cursor']['next'] );
    }

    public function test_messages_error_on_rate_limit(): void {
        $this->stub_http_response( 429, '{}', '45' );

        $result = $this->make_client()->get_message_list( '42' );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'rate_limited', $result->get_error_code() );
    }

// HTTP edge cases

    public function test_request_error_on_5xx(): void {
        $this->stub_http_response( 503, 'Service Unavailable' );

        $result = $this->make_client()->test_connection();

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertStringContainsString( '503', $result->get_error_message() );
    }

    public function test_rate_limit_retry_capped_3600(): void {
        $this->stub_http_response( 429, '{}', '9999' );

        $result = $this->make_client()->test_connection();

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 3600, $result->get_error_data()['retry_after'] );
    }

    public function test_request_error_on_invalid_json(): void {
        // A non-empty, non-JSON body on an otherwise-successful 200 response must
        // return WP_Error('invalid_response').
        $this->stub_http_response( 200, '<html>Service Unavailable</html>' );

        $result = $this->make_client()->get_contacts( [ 'timezone' => 'UTC' ] );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'invalid_response', $result->get_error_code() );
    }
}
