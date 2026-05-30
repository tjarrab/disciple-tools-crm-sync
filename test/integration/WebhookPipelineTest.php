<?php
/**
 * Integration tests for the webhook → batch-import pipeline.
 *
 * These tests use the live WP REST server to dispatch requests to
 * disciple-tools-crm-sync/v1/webhook, exercising HMAC verification,
 * event routing, and per-IP rate limiting.
 *
 * The webhook route is registered by Disciple_Tools_CRM_Sync_Webhook, which
 * must be instantiated manually in setUp() because the main plugin constructor
 * only boots it when the URL path contains 'webhook'.
 *
 * Run with: php vendor/bin/phpunit -c phpunit.xml.dist --testdox
 */

class WebhookPipelineTest extends TestCase {

    private const NAMESPACE = '/disciple-tools-crm-sync/v1';
    private const ROUTE     = '/webhook';

    /** Shared HMAC signing key for tests. */
    private const SIGNING_KEY = 'integration-test-signing-key';

    /** An event type that is handled by the webhook handler. */
    private const HANDLED_EVENT = 'new_contact';

    protected function setUp(): void {
        parent::setUp();
        global $wp_rest_server;

        // Ensure connector registry can return a working connector stub.
        // We store a minimal settings option so get_active_connector() resolves.
        update_option( 'dt_crm_sync_settings', [
            'active_connector' => 'respond_io',
            'connectors'       => [
                'respond_io' => [
                    'api_url'             => 'https://api.test',
                    'api_token'           => 'test_token',
                    'webhook_signing_key' => self::SIGNING_KEY,
                ],
            ],
        ] );

        // Prime the encryption key so get_active_connector() can decrypt.
        // Use a known 32-byte base64 key; we also need to store the ciphertext
        // of the signing key. Since integration tests use the real encrypt/decrypt
        // functions we store a real ciphertext here.
        $encryption_key = base64_encode( str_repeat( "\x01", 32 ) );
        update_option( 'dt_crm_sync_encryption_key', $encryption_key );

        // Encrypt the signing key so the connector can decrypt it.
        $encrypted_signing_key = Disciple_Tools_CRM_Sync::encrypt_value( self::SIGNING_KEY );
        $encrypted_token       = Disciple_Tools_CRM_Sync::encrypt_value( 'test_token' );

        update_option( 'dt_crm_sync_settings', [
            'active_connector' => 'respond_io',
            'connectors'       => [
                'respond_io' => [
                    'api_url'             => 'https://api.test',
                    'api_token'           => $encrypted_token,
                    'webhook_signing_key' => $encrypted_signing_key,
                ],
            ],
        ] );

        // Manually instantiate the webhook listener (normally done in plugin
        // constructor only when URL path contains 'webhook').
        // Reset the singleton so a fresh instance registers its route.
        $reflection = new ReflectionClass( Disciple_Tools_CRM_Sync_Webhook::class );
        $prop       = $reflection->getProperty( 'instance' );
        $prop->setAccessible( true );
        $prop->setValue( null, null );
        Disciple_Tools_CRM_Sync_Webhook::instance();

        // Bootstrap REST server and fire rest_api_init to register routes.
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
    }

    protected function tearDown(): void {
        delete_option( 'dt_crm_sync_settings' );
        delete_option( 'dt_crm_sync_encryption_key' );

        // Reset the webhook singleton.
        $reflection = new ReflectionClass( Disciple_Tools_CRM_Sync_Webhook::class );
        $prop       = $reflection->getProperty( 'instance' );
        $prop->setAccessible( true );
        $prop->setValue( null, null );

        parent::tearDown();
    }

// Helpers

    /**
     * Compute the HMAC-SHA256 signature over the raw JSON body.
     */
    private function sign( string $raw_body ): string {
        return hash_hmac( 'sha256', $raw_body, self::SIGNING_KEY );
    }

    /**
     * Dispatch a webhook POST to the REST server.
     *
     * @param array  $payload    JSON-encodeable payload.
     * @param string $signature  Value for the X-Webhook-Signature header.
     * @param string $remote_ip  Simulated remote IP address.
     */
    private function dispatch_webhook( array $payload, string $signature = '', string $remote_ip = '127.0.0.1' ): WP_REST_Response {
        $_SERVER['REMOTE_ADDR'] = $remote_ip;

        $raw_body = wp_json_encode( $payload );

        $request = new WP_REST_Request( 'POST', self::NAMESPACE . self::ROUTE );
        $request->set_body( $raw_body );
        $request->set_header( 'content-type', 'application/json' );

        if ( '' !== $signature ) {
            // WP REST normalises header names to lowercase-underscore.
            $request->set_header( 'x_webhook_signature', $signature );
        }

        return rest_get_server()->dispatch( $request );
    }

// HMAC verification

    /**
     * A request with a valid HMAC signature and a handled event type must
     * return 200 and schedule a batch import.
     */
    public function test_webhook_valid_hmac_queues_batch(): void {
        $payload   = [ 'event' => self::HANDLED_EVENT, 'contact' => [ 'id' => 9999 ] ];
        $raw_body  = wp_json_encode( $payload );
        $signature = $this->sign( $raw_body );

        // Capture scheduled events.
        $scheduled = [];
        add_filter( 'pre_schedule_event', static function ( $pre, $event ) use ( &$scheduled ) {
            $scheduled[] = $event;
            return true; // Prevent actual DB write in integration test.
        }, 10, 2 );

        $response = $this->dispatch_webhook( $payload, $signature );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( 'queued', $data['status'] );

        // At least one batch import event must have been scheduled.
        $batch_events = array_filter(
            $scheduled,
            static fn( $e ) => 'dt_crm_sync_process_batch' === $e->hook
        );
        $this->assertNotEmpty( $batch_events, 'A dt_crm_sync_process_batch event must be scheduled.' );

        // The scheduled batch must include the contact ID.
        $batch = reset( $batch_events );
        $args  = $batch->args[0] ?? [];
        $this->assertContains( 9999, $args['ids'] );
    }

    /**
     * A request with a missing signature header must return 401.
     */
    public function test_webhook_missing_signature_401(): void {
        $payload  = [ 'event' => self::HANDLED_EVENT, 'contact' => [ 'id' => 1 ] ];
        $response = $this->dispatch_webhook( $payload, '' );

        $this->assertSame( 401, $response->get_status() );
    }

    /**
     * A request with an invalid (tampered) HMAC signature must return 401.
     */
    public function test_webhook_invalid_hmac_401(): void {
        $payload  = [ 'event' => self::HANDLED_EVENT, 'contact' => [ 'id' => 1 ] ];
        $response = $this->dispatch_webhook( $payload, 'invalid_signature_value' );

        $this->assertSame( 401, $response->get_status() );
    }

// Event routing

    /**
     * An unhandled event type must return 200 with status=ignored.
     */
    public function test_webhook_unhandled_event_ignored(): void {
        $payload   = [ 'event' => 'unrecognized_event_type', 'contact' => [ 'id' => 1 ] ];
        $raw_body  = wp_json_encode( $payload );
        $signature = $this->sign( $raw_body );

        $response = $this->dispatch_webhook( $payload, $signature );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( 'ignored', $data['status'] );
        $this->assertSame( 'unhandled_event', $data['reason'] );
    }

    /**
     * A payload with no contact ID must return 200 with status=ignored.
     */
    public function test_webhook_missing_contact_id_ignored(): void {
        $payload   = [ 'event' => self::HANDLED_EVENT, 'contact' => [] ];
        $raw_body  = wp_json_encode( $payload );
        $signature = $this->sign( $raw_body );

        $response = $this->dispatch_webhook( $payload, $signature );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 'ignored', $response->get_data()['status'] );
        $this->assertSame( 'no_contact_id', $response->get_data()['reason'] );
    }

// Rate limiting

    /**
     * The 31st verified request from the same IP within the rate-limit window
     * must return 429 with a Retry-After header.
     */
    public function test_rate_limit_enforced_after_30_requests(): void {
        $rate_limit_ip = '10.0.0.1';
        $payload       = [ 'event' => self::HANDLED_EVENT, 'contact' => [ 'id' => 1 ] ];
        $raw_body      = wp_json_encode( $payload );
        $signature     = $this->sign( $raw_body );

        // Pre-populate the rate-limit transient to 30 (already at limit).
        $rl_key = 'dt_crm_sync_wh_rl_' . wp_hash( $rate_limit_ip );
        set_transient( $rl_key, [ 'count' => 30, 'timeout' => time() + 60 ], 60 );

        $response = $this->dispatch_webhook( $payload, $signature, $rate_limit_ip );

        $this->assertSame( 429, $response->get_status() );
        $headers = $response->get_headers();
        $this->assertArrayHasKey( 'Retry-After', $headers );

        // Clean up transient.
        delete_transient( $rl_key );
    }

    /**
     * The first 30 requests from the same IP must succeed (not rate-limited).
     */
    public function test_first_30_requests_are_not_rate_limited(): void {
        $rate_limit_ip = '10.0.0.2';
        $payload       = [ 'event' => self::HANDLED_EVENT, 'contact' => [ 'id' => 2 ] ];
        $raw_body      = wp_json_encode( $payload );
        $signature     = $this->sign( $raw_body );

        // Pre-populate counter to 29 (one below the limit).
        $rl_key = 'dt_crm_sync_wh_rl_' . wp_hash( $rate_limit_ip );
        set_transient( $rl_key, 29, 60 );

        // Add a filter to suppress actual cron scheduling.
        add_filter( 'pre_schedule_event', '__return_true', 10, 2 );

        $response = $this->dispatch_webhook( $payload, $signature, $rate_limit_ip );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 'queued', $response->get_data()['status'] );

        delete_transient( $rl_key );
    }
}
