<?php
/**
 * Unit tests for Disciple_Tools_CRM_Sync_Processor field-builder methods.
 *
 * Tests the core-field and custom-field mapping logic that converts a
 * Respond.io contact profile into the DT fields array — without touching
 * the database or running a WP-Cron batch.
 *
 * Uses a thin TestableProcessor subclass to expose the protected helpers
 * and inject a mock connector without triggering the WP-Cron registration
 * inside the real constructor.
 */

use Brain\Monkey\Functions;

// Test harness

/**
 * Thin subclass that bypasses the singleton/WP-Cron constructor and exposes
 * the two protected field-builder methods as public for direct assertion.
 */
class TestableProcessor extends Disciple_Tools_CRM_Sync_Processor {

    /**
     * Replace the real constructor (which registers a WP-Cron action) with a
     * no-op so we can instantiate the class without WordPress.
     */
    public function __construct() {
        // Intentionally empty — do not call parent constructor.
    }

    /** Allow tests to inject any connector (real or mock). */
    public function set_connector( Disciple_Tools_CRM_Sync_Abstract_Connector $connector ): void {
        $this->connector = $connector;
    }

    /** Allow tests to inject a ContactMatcher. */
    public function set_matcher( Disciple_Tools_CRM_Sync_Contact_Matcher $matcher ): void {
        $this->matcher = $matcher;
    }

    /** Allow tests to inject a FieldMapper. */
    public function set_mapper( Disciple_Tools_CRM_Sync_Field_Mapper $mapper ): void {
        $this->mapper = $mapper;
    }

    /** Allow tests to inject a MessageImporter. */
    public function set_message_importer( Disciple_Tools_CRM_Sync_Message_Importer $message_importer ): void {
        $this->message_importer = $message_importer;
    }

    /** Expose the message importer that process_batch() built, so tests can inspect its wiring. */
    public function get_message_importer(): ?Disciple_Tools_CRM_Sync_Message_Importer {
        return $this->message_importer;
    }

    /** Expose sideload() via the sideloader within the message importer for direct testing. */
    public function expose_sideload_attachment( string $url, int $post_id ): string {
        return ( new Disciple_Tools_CRM_Sync_Media_Sideloader() )->sideload( $url, $post_id );
    }

    /** Expose process_single_contact() as public for direct testing. */
    public function expose_process_single_contact( string $respond_id, string $trigger_type, bool $skip_existing = true ): WP_Error|null {
        return $this->process_single_contact( $respond_id, $trigger_type, $skip_existing );
    }

    /**
     * When set, process_single_contact() delegates to this callable instead of
     * running the real lifecycle. Signature: fn(string $respond_id): WP_Error|null
     * Used by boundary tests to inject selective per-contact failures without
     * needing to simulate full HTTP call sequences.
     */
    public $process_single_contact_fn = null;

    protected function process_single_contact( string $respond_id, string $trigger_type, bool $skip_existing = true ): WP_Error|null {
        if ( null !== $this->process_single_contact_fn ) {
            return ( $this->process_single_contact_fn )( $respond_id, $trigger_type );
        }
        return parent::process_single_contact( $respond_id, $trigger_type, $skip_existing );
    }
}

// Constructor harness

/**
 * Thin public subclass used only to call the protected parent constructor from
 * a test. Allows asserting that __construct() registers the WP-Cron action
 * without going through the singleton factory.
 */
class ProcessorWithPublicConstructor extends Disciple_Tools_CRM_Sync_Processor {
    public function __construct() {
        parent::__construct();
    }
}

// Tests

class ImportProcessorTest extends BrainMonkeyTestCase {

    private TestableProcessor $processor;
    private Disciple_Tools_CRM_Sync_Connector_Respond_IO $connector;

    protected function setUp(): void {
        parent::setUp();
        // sanitize_* pass-throughs used throughout the field builders.
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'sanitize_email' )->returnArg();
        Functions\when( 'sanitize_key' )->returnArg();

        $this->connector = new Disciple_Tools_CRM_Sync_Connector_Respond_IO( [
            'api_url'   => 'https://api.respond.io',
            'api_token' => 'token',
        ] );
        $this->processor = new TestableProcessor();
        $this->processor->set_connector( $this->connector );
        $this->processor->set_matcher( new Disciple_Tools_CRM_Sync_Contact_Matcher( $this->connector->get_meta_key_prefix() ) );
        $this->processor->set_mapper( new Disciple_Tools_CRM_Sync_Field_Mapper( $this->connector ) );
        $this->processor->set_message_importer( new Disciple_Tools_CRM_Sync_Message_Importer(
            $this->connector,
            new Disciple_Tools_CRM_Sync_Media_Sideloader()
        ) );
    }

    public function test_process_batch_missing_connector_logs_failure(): void {
        Functions\when( 'get_option' )->justReturn( [] ); // no connector configured
        $processor = new TestableProcessor();
        $processor->process_batch( [ 'ids' => [ '123' ], '_trigger' => 'manual' ] );

        global $wpdb;
        $statuses = array_column( array_column( $wpdb->insert_calls, 'data' ), 'status' );
        $this->assertContains( 'failed', $statuses );
    }

    private function stub_active_connector(): void {
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
    }

    public function test_process_batch_rate_limited_boundary_includes_triggering_mid_batch_contact(): void {
        $this->stub_active_connector();

        $processor = new TestableProcessor();
        // Contact '1' succeeds (null); contact '2' triggers rate_limited.
        $processor->process_single_contact_fn = function ( string $id ): ?WP_Error {
            return '2' === $id ? new WP_Error( 'rate_limited', 'Rate limit hit.', [ 'retry_after' => 30 ] ) : null;
        };

        $rescheduled_ids = null;
        Functions\when( 'wp_schedule_single_event' )->alias(
            function ( $time, $hook, $args ) use ( &$rescheduled_ids ) {
                if ( 'dt_crm_sync_process_batch' === $hook ) {
                    $rescheduled_ids = $args[0]['ids'];
                }
                return true;
            }
        );

        $processor->process_batch( [ 'ids' => [ '1', '2', '3' ], '_trigger' => 'manual' ] );

        $this->assertSame(
            [ '2', '3' ],
            $rescheduled_ids,
            'array_slice( $ids, $processed_count - 1 ) must include "2" (the rate-limited contact) and exclude already-processed "1".'
        );
    }

    public function test_process_batch_resource_pending_boundary_includes_triggering_mid_batch_contact(): void {
        $this->stub_active_connector();

        $processor = new TestableProcessor();
        // Contact '10' succeeds (null); contact '20' triggers resource_pending.
        $processor->process_single_contact_fn = function ( string $id ): ?WP_Error {
            return '20' === $id ? new WP_Error( 'resource_pending', 'Resource is still being created.' ) : null;
        };

        $rescheduled_ids  = null;
        $rescheduled_time = null;
        Functions\when( 'wp_schedule_single_event' )->alias(
            function ( $time, $hook, $args ) use ( &$rescheduled_ids, &$rescheduled_time ) {
                if ( 'dt_crm_sync_process_batch' === $hook ) {
                    $rescheduled_ids  = $args[0]['ids'];
                    $rescheduled_time = $time;
                }
                return true;
            }
        );

        $processor->process_batch( [ 'ids' => [ '10', '20', '30' ], '_trigger' => 'manual' ] );

        $this->assertSame(
            [ '20', '30' ],
            $rescheduled_ids,
            'array_slice( $ids, $processed_count - 1 ) must include "20" (the pending contact) and exclude already-processed "10".'
        );
        $this->assertGreaterThanOrEqual(
            time() + 179,
            $rescheduled_time,
            'Resource-pending reschedule must use a ~180 s delay.'
        );
    }

// process_batch input sanitisation (fix 3.6)

    public function test_process_batch_filters_out_non_scalar_ids(): void {
        $this->stub_active_connector();

        $processed_ids = [];
        $processor     = new TestableProcessor();
        $processor->process_single_contact_fn = function ( string $id ) use ( &$processed_ids ): ?WP_Error {
            $processed_ids[] = $id;
            return null;
        };

        Functions\when( 'wp_schedule_single_event' )->justReturn( false );

        // Pass one nested-array ID (invalid) and two scalar IDs (valid).
        $processor->process_batch( [ 'ids' => [ [ 'nested_array' ], 'id_a', 'id_b' ], '_trigger' => 'manual' ] );

        $this->assertSame(
            [ 'id_a', 'id_b' ],
            $processed_ids,
            'Non-scalar (array) IDs must be filtered out before the processing loop.'
        );
    }

    public function test_process_batch_sanitizes_trigger_type_via_sanitize_key(): void {
        $this->stub_active_connector();

        // Override the setUp() pass-through so sanitize_key applies its real logic.
        Functions\when( 'sanitize_key' )->alias(
            fn( $key ) => preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) )
        );

        $logged_triggers = [];
        $processor       = new TestableProcessor();
        $processor->process_single_contact_fn = function ( string $id, string $trigger ) use ( &$logged_triggers ): ?WP_Error {
            $logged_triggers[] = $trigger;
            return null;
        };

        Functions\when( 'wp_schedule_single_event' )->justReturn( false );

        // Uppercase letters + special chars should be stripped by sanitize_key.
        $processor->process_batch( [ 'ids' => [ 'id_1' ], '_trigger' => 'MANUAL!' ] );

        $this->assertSame(
            [ 'manual' ],
            $logged_triggers,
            'trigger_type must be passed through sanitize_key before use.'
        );
    }

// sideload_attachment

    public function test_sideload_attachment_unchanged_on_empty(): void {
        $result = $this->processor->expose_sideload_attachment( '', 1 );
        $this->assertSame( '', $result );
    }

    public function test_sideload_attachment_rejects_non_allowlisted_host(): void {
        Functions\when( 'apply_filters' )->alias(
            fn( $hook, $value ) => 'dt_crm_sync_sideload_allowed_hosts' === $hook
                ? [ 'cdn.respond.io', 'storage.respond.io' ]
                : $value
        );
        Functions\when( 'wp_parse_url' )->alias( 'parse_url' );

        $url    = 'https://evil.example.com/file.jpg';
        $result = $this->processor->expose_sideload_attachment( $url, 1 );

        $this->assertSame( $url, $result, 'Non-allowlisted host should be returned unchanged.' );
    }

    public function test_sideload_attachment_routes_image_url_to_media_sideload_image(): void {
        Functions\when( 'apply_filters' )->alias(
            fn( $hook, $value ) => 'dt_crm_sync_sideload_allowed_hosts' === $hook
                ? [ 'cdn.respond.io', 'storage.respond.io' ]
                : $value
        );
        Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
        Functions\when( 'media_sideload_image' )->justReturn( 'https://local.test/wp-content/uploads/img.jpg' );

        $result = $this->processor->expose_sideload_attachment( 'https://cdn.respond.io/img.jpg', 1 );

        $this->assertSame( 'https://local.test/wp-content/uploads/img.jpg', $result );
    }

    public function test_sideload_attachment_fallback_on_failure(): void {
        Functions\when( 'apply_filters' )->alias(
            fn( $hook, $value ) => 'dt_crm_sync_sideload_allowed_hosts' === $hook
                ? [ 'cdn.respond.io', 'storage.respond.io' ]
                : $value
        );
        Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
        Functions\when( 'media_sideload_image' )->justReturn( new WP_Error( 'sideload_failed', 'Could not sideload' ) );

        $url    = 'https://cdn.respond.io/img.png';
        $result = $this->processor->expose_sideload_attachment( $url, 1 );

        $this->assertSame( $url, $result, 'Original URL must be returned when media_sideload_image fails.' );
    }

    public function test_sideload_attachment_routes_non_image_through_download_url(): void {
        Functions\when( 'apply_filters' )->alias(
            fn( $hook, $value ) => 'dt_crm_sync_sideload_allowed_hosts' === $hook
                ? [ 'cdn.respond.io', 'storage.respond.io' ]
                : $value
        );
        Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
        Functions\when( 'download_url' )->justReturn( '/nonexistent/tmp/file.pdf' );
        Functions\when( 'media_handle_sideload' )->justReturn( 9 );
        Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://local.test/wp-content/uploads/file.pdf' );

        $result = $this->processor->expose_sideload_attachment( 'https://cdn.respond.io/doc.pdf', 1 );

        $this->assertSame( 'https://local.test/wp-content/uploads/file.pdf', $result );
    }

// process_single_contact (via expose)

    public function test_process_contact_rate_limit_429(): void {
        Functions\when( 'get_posts' )->justReturn( [] );
        Functions\when( 'wp_safe_remote_request' )->justReturn( [ '_mocked' => true ] );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 429 );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{}' );
        Functions\when( 'wp_remote_retrieve_header' )->justReturn( '60' );

        $result = $this->processor->expose_process_single_contact( '42', 'manual' );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'rate_limited', $result->get_error_code() );
    }

    public function test_process_contact_dt_write_failure(): void {
        Functions\when( 'get_posts' )->justReturn( [] );
        Functions\when( 'wp_safe_remote_request' )->justReturn( [ '_mocked' => true ] );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"firstName":"Test","email":"t@example.com"}' );
        Functions\when( 'get_option' )->justReturn( [] );

        DT_Posts::$create_post_result = new WP_Error( 'db_error', 'Cannot create post.' );

        $result = $this->processor->expose_process_single_contact( '42', 'manual' );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'dt_write_failed', $result->get_error_code() );
    }

// Translation service wiring

    public function test_process_batch_logs_failure_when_decrypt_fails(): void {
        // decrypt_value() returns false by default (test stub, $test_decrypt_fn = null).
        // Settings look fully configured, but the key can't be decrypted — silently broken
        // before this fix. Verify the import log now contains a 'failed' row.
        Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
            if ( 'dt_crm_sync_settings' === $key ) {
                return [
                    'active_connector' => 'respond_io',
                    'connectors'       => [ 'respond_io' => [ 'api_url' => 'https://api.test', 'api_token' => 'tok' ] ],
                ];
            }
            if ( 'dt_crm_sync_translation_settings' === $key ) {
                return [ 'enabled' => true, 'api_key' => 'encrypted_blob', 'model' => 'gemini-pro', 'daily_limit' => 100 ];
            }
            return $default;
        } );
        Functions\when( 'apply_filters' )->alias(
            fn( $hook, $value ) => 'dt_crm_sync_connectors' === $hook
                ? [ 'respond_io' => 'Disciple_Tools_CRM_Sync_Connector_Respond_IO' ]
                : $value
        );
        Functions\when( 'wp_schedule_single_event' )->justReturn( false );

        $processor = new TestableProcessor();
        $processor->process_single_contact_fn = fn( string $id, string $trigger ): ?WP_Error => null;
        $processor->process_batch( [ 'ids' => [ '1' ], '_trigger' => 'manual' ] );

        global $wpdb;
        $decrypt_failure_logged = false;
        foreach ( $wpdb->insert_calls as $call ) {
            if (
                'wp_dt_crm_sync_logs' === $call['table'] &&
                'failed' === ( $call['data']['status'] ?? '' ) &&
                str_contains( $call['data']['message'] ?? '', 'decrypted' )
            ) {
                $decrypt_failure_logged = true;
                break;
            }
        }

        $this->assertTrue(
            $decrypt_failure_logged,
            'Expected a failed log entry when the translation API key cannot be decrypted.'
        );
    }

    public function test_process_batch_creates_translation_service_when_settings_correct(): void {
        // When all settings are valid and the key decrypts, the message importer must
        // receive a non-null translation service so translate() actually runs.
        Disciple_Tools_CRM_Sync::$test_decrypt_fn = fn( string $v ): string => 'real_api_key';

        Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
            if ( 'dt_crm_sync_settings' === $key ) {
                return [
                    'active_connector' => 'respond_io',
                    'connectors'       => [ 'respond_io' => [ 'api_url' => 'https://api.test', 'api_token' => 'tok' ] ],
                ];
            }
            if ( 'dt_crm_sync_translation_settings' === $key ) {
                return [ 'enabled' => true, 'api_key' => 'encrypted_blob', 'model' => 'gemini-pro', 'daily_limit' => 100 ];
            }
            return $default;
        } );
        Functions\when( 'apply_filters' )->alias(
            fn( $hook, $value ) => 'dt_crm_sync_connectors' === $hook
                ? [ 'respond_io' => 'Disciple_Tools_CRM_Sync_Connector_Respond_IO' ]
                : $value
        );
        Functions\when( 'wp_schedule_single_event' )->justReturn( false );

        $processor = new TestableProcessor();
        $processor->process_single_contact_fn = fn( string $id, string $trigger ): ?WP_Error => null;
        $processor->process_batch( [ 'ids' => [ '1' ], '_trigger' => 'manual' ] );

        $importer = $processor->get_message_importer();
        $this->assertNotNull( $importer, 'process_batch() must create a message importer.' );

        $ref = new \ReflectionProperty( Disciple_Tools_CRM_Sync_Message_Importer::class, 'translation_service' );
        $ref->setAccessible( true );
        $this->assertNotNull(
            $ref->getValue( $importer ),
            'Message importer must be wired with a non-null translation service when settings are valid.'
        );
    }
}
