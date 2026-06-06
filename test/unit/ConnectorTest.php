<?php
/**
 * Unit tests for Disciple_Tools_CRM_Sync_Connector_Respond_IO.
 *
 * Tests webhook signature verification, header name, and meta key prefix.
 * The HMAC-SHA256 logic uses only native PHP functions (hash_hmac, hash_equals)
 * so these tests need no Brain Monkey function mocking.
 */

use Brain\Monkey\Functions;

class ConnectorTest extends BrainMonkeyTestCase {

// Webhook signature verification

    public function test_webhook_valid_signature(): void {
        $signing_key = 'super_secret_key';
        $payload     = '{"event":"contact_updated","contactId":42}';
        $signature   = hash_hmac( 'sha256', $payload, $signing_key );

        $connector = new Disciple_Tools_CRM_Sync_Connector_Respond_IO( [
            'api_url'            => 'https://api.respond.io',
            'api_token'          => 'token',
            'webhook_signing_key' => $signing_key,
        ] );

        $this->assertTrue( $connector->verify_webhook( $payload, $signature ) );
    }

    public function test_webhook_tampered_payload(): void {
        $signing_key = 'super_secret_key';
        $original    = '{"event":"contact_updated","contactId":42}';
        $tampered    = '{"event":"contact_updated","contactId":99}';
        $signature   = hash_hmac( 'sha256', $original, $signing_key );

        $connector = new Disciple_Tools_CRM_Sync_Connector_Respond_IO( [
            'api_url'            => 'https://api.respond.io',
            'api_token'          => 'token',
            'webhook_signing_key' => $signing_key,
        ] );

        $this->assertFalse( $connector->verify_webhook( $tampered, $signature ) );
    }

    public function test_webhook_empty_signature(): void {
        $connector = new Disciple_Tools_CRM_Sync_Connector_Respond_IO( [
            'api_url'            => 'https://api.respond.io',
            'api_token'          => 'token',
            'webhook_signing_key' => 'key',
        ] );

        $this->assertFalse( $connector->verify_webhook( 'payload', '' ) );
    }

    public function test_webhook_missing_signing_key(): void {
        // No webhook_signing_key in credentials.
        $connector = new Disciple_Tools_CRM_Sync_Connector_Respond_IO( [
            'api_url'   => 'https://api.respond.io',
            'api_token' => 'token',
        ] );

        $this->assertFalse( $connector->verify_webhook( 'payload', 'some_signature' ) );
    }

    public function test_webhook_empty_signing_key(): void {
        // Key exists in credentials but is an empty string — a distinct misconfiguration
        // path from a completely missing key (tested in the test above).
        $connector = new Disciple_Tools_CRM_Sync_Connector_Respond_IO( [
            'api_url'            => 'https://api.respond.io',
            'api_token'          => 'token',
            'webhook_signing_key' => '',
        ] );

        $this->assertFalse( $connector->verify_webhook( 'payload', 'some_signature' ) );
    }

// Webhook header name

    public function test_webhook_header_normalisation(): void {
        $connector = new Disciple_Tools_CRM_Sync_Connector_Respond_IO( [] );

        $this->assertSame( 'x_webhook_signature', $connector->get_webhook_header() );
    }

// Post meta key prefix

    public function test_webhook_meta_prefix(): void {
        $connector = new Disciple_Tools_CRM_Sync_Connector_Respond_IO( [] );

        $this->assertSame( '_respond_io_', $connector->get_meta_key_prefix() );
    }

// Identity

    public function test_get_slug(): void {
        $connector = new Disciple_Tools_CRM_Sync_Connector_Respond_IO( [] );

        $this->assertSame( 'respond_io', $connector->get_slug() );
    }

    public function test_get_label(): void {
        $connector = new Disciple_Tools_CRM_Sync_Connector_Respond_IO( [] );

        $this->assertSame( 'Respond.io', $connector->get_label() );
    }

// get_contacts() filter translation

    /**
     * Build a connector and capture the outbound POST body sent to the API.
     *
     * @param array  $filter_params filter params passed to get_contacts()
     * @param string $tz_string     return value for wp_timezone_string()
     * @return array decoded $body from the captured wp_safe_remote_request() call
     */
    private function call_get_contacts_capture_body(
        array $filter_params,
        string $tz_string = 'UTC'
    ): array {
        Functions\when( 'wp_timezone_string' )->justReturn( $tz_string );
        Functions\when( 'add_query_arg' )->justReturn( 'https://api.test/mocked' );
        Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn(
            json_encode( [ 'items' => [], 'pagination' => [] ] )
        );

        $captured_body = [];
        Functions\when( 'wp_safe_remote_request' )->alias(
            function ( $url, $args ) use ( &$captured_body ) {
                $captured_body = json_decode( $args['body'] ?? '{}', true ) ?? [];
                return [ '_mocked' => true ];
            }
        );

        $connector = new Disciple_Tools_CRM_Sync_Connector_Respond_IO( [
            'api_url'   => 'https://api.test',
            'api_token' => 'token',
        ] );
        $connector->get_contacts( $filter_params );

        return $captured_body;
    }

    public function test_get_contacts_with_no_tag_produces_empty_and_condition_array(): void {
        $body = $this->call_get_contacts_capture_body( [ 'search' => '', 'tag' => '' ] );

        $this->assertSame( [], $body['filter']['$and'] ?? [], '$and should be empty when no tag filter is set.' );
    }

    public function test_get_contacts_tag_filter_builds_has_any_of_condition(): void {
        $body       = $this->call_get_contacts_capture_body( [ 'tag' => 'vip' ] );
        $conditions = $body['filter']['$and'] ?? [];

        $this->assertCount( 1, $conditions );
        $this->assertSame( 'contactTag', $conditions[0]['category'] );
        $this->assertSame( 'hasAnyOf', $conditions[0]['operator'] );
        $this->assertSame( [ 'vip' ], $conditions[0]['value'] );
    }

// Lifecycle filter condition

    public function test_get_contacts_lifecycle_filter_builds_is_equal_to_condition(): void {
        $body       = $this->call_get_contacts_capture_body( [ 'lifecycle' => 'F2F Ready' ] );
        $conditions = $body['filter']['$and'] ?? [];

        $this->assertCount( 1, $conditions );
        $this->assertSame( 'lifecycle', $conditions[0]['category'] );
        $this->assertNull( $conditions[0]['field'] );
        $this->assertSame( 'isEqualTo', $conditions[0]['operator'] );
        // Value must be a plain string, not an array (unlike the tag condition).
        $this->assertSame( 'F2F Ready', $conditions[0]['value'] );
    }

    public function test_get_contacts_empty_lifecycle_produces_no_lifecycle_condition(): void {
        $body       = $this->call_get_contacts_capture_body( [ 'lifecycle' => '' ] );
        $conditions = $body['filter']['$and'] ?? [];

        $this->assertSame( [], $conditions );
    }

    public function test_get_contacts_tag_filter_unchanged_when_lifecycle_absent(): void {
        $body       = $this->call_get_contacts_capture_body( [ 'tag' => 'vip', 'lifecycle' => '' ] );
        $conditions = $body['filter']['$and'] ?? [];

        $this->assertCount( 1, $conditions );
        $this->assertSame( 'contactTag', $conditions[0]['category'] );
    }

    public function test_get_contacts_lifecycle_filter_unchanged_when_tag_absent(): void {
        $body       = $this->call_get_contacts_capture_body( [ 'tag' => '', 'lifecycle' => 'F2F Ready' ] );
        $conditions = $body['filter']['$and'] ?? [];

        $this->assertCount( 1, $conditions );
        $this->assertSame( 'lifecycle', $conditions[0]['category'] );
    }

    public function test_get_contacts_normalises_utc_offset_timezone_string_to_iana_name(): void {
        $iana_tz = new class() {
            public function getName(): string { return 'Asia/Kolkata'; }
        };
        Functions\when( 'wp_timezone' )->justReturn( $iana_tz );

        $body = $this->call_get_contacts_capture_body( [], '+05:30' );

        $this->assertSame( 'Asia/Kolkata', $body['timezone'] ?? '' );
    }

    public function test_contacts_iana_timezone(): void {
        $body = $this->call_get_contacts_capture_body( [], 'America/New_York' );

        $this->assertSame( 'America/New_York', $body['timezone'] ?? '' );
    }

// get_client() misconfiguration returns WP_Error

    public function test_connection_error_missing_credentials(): void {
        $connector = new Disciple_Tools_CRM_Sync_Connector_Respond_IO( [] );

        $result = $connector->test_connection();

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'connector_misconfigured', $result->get_error_code() );
    }

// Schema transient key

// conversation_status filter conditions

    public function test_get_contacts_closed_status_builds_is_equal_to_condition(): void {
        $body       = $this->call_get_contacts_capture_body( [ 'conversation_status' => 'closed' ] );
        $conditions = $body['filter']['$and'] ?? [];

        $this->assertCount( 1, $conditions );
        $this->assertSame( 'contactField', $conditions[0]['category'] );
        $this->assertSame( 'status', $conditions[0]['field'] );
        $this->assertSame( 'isEqualTo', $conditions[0]['operator'] );
        $this->assertSame( 'closed', $conditions[0]['value'] );
    }

    public function test_get_contacts_open_or_snoozed_builds_or_condition(): void {
        // The Respond.io API accepts a nested $or inside $and for matching two values.
        // hasAnyOf is NOT supported for contactField status — the API returns 400.
        $body       = $this->call_get_contacts_capture_body( [ 'conversation_status' => 'open_or_snoozed' ] );
        $conditions = $body['filter']['$and'] ?? [];

        $this->assertCount( 1, $conditions );
        $this->assertArrayHasKey( '$or', $conditions[0], 'open_or_snoozed must produce a nested $or condition.' );

        $or_clauses = $conditions[0]['$or'];
        $this->assertCount( 2, $or_clauses );

        $values = array_column( $or_clauses, 'value' );
        $this->assertContains( 'open', $values );
        $this->assertContains( 'snoozed', $values );

        foreach ( $or_clauses as $clause ) {
            $this->assertSame( 'contactField', $clause['category'] );
            $this->assertSame( 'status', $clause['field'] );
            $this->assertSame( 'isEqualTo', $clause['operator'] );
        }
    }

    public function test_get_contacts_empty_status_produces_no_status_condition(): void {
        $body       = $this->call_get_contacts_capture_body( [ 'conversation_status' => '' ] );
        $conditions = $body['filter']['$and'] ?? [];

        $this->assertSame( [], $conditions, 'An empty conversation_status should add no condition.' );
    }

    public function test_get_contacts_lifecycle_and_status_both_present_adds_two_and_conditions(): void {
        $body       = $this->call_get_contacts_capture_body( [
            'lifecycle'           => 'F2F Ready',
            'conversation_status' => 'open_or_snoozed',
        ] );
        $conditions = $body['filter']['$and'] ?? [];

        $this->assertCount( 2, $conditions, 'Lifecycle and conversation_status should each add one entry to $and.' );

        $categories = array_map( fn( $c ) => array_key_first( $c ) === '$or' ? '$or' : $c['category'], $conditions );
        $this->assertContains( 'lifecycle', $categories );
        $this->assertContains( '$or', $categories );
    }

// Schema transient key

    public function test_schema_transient_key_respond_io(): void {
        $connector = new Disciple_Tools_CRM_Sync_Connector_Respond_IO( [] );

        $this->assertSame( 'dt_crm_sync_field_schema_respond_io', $connector->get_schema_transient_key() );
    }

    public function test_schema_transient_key_metricool(): void {
        $connector = new Disciple_Tools_CRM_Sync_Connector_Metricool( [] );

        $this->assertSame( 'dt_crm_sync_field_schema_metricool', $connector->get_schema_transient_key() );
    }
}
