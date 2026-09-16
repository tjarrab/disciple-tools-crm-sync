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

    /** Allow tests to inject an ActivityFeedWriter. */
    public function set_activity_feed_writer( Disciple_Tools_CRM_Sync_Activity_Feed_Writer $writer ): void {
        $this->activity_feed_writer = $writer;
    }

    /** Expose the message importer that process_batch() built, so tests can inspect its wiring. */
    public function get_message_importer(): ?Disciple_Tools_CRM_Sync_Message_Importer {
        return $this->message_importer;
    }

    /** Sideloader instance kept between expose_sideload_attachment() and expose_sideload_last_error(). */
    private Disciple_Tools_CRM_Sync_Media_Sideloader $last_sideloader;

    /** Runs sideload() and retains the sideloader so last_error can be read afterward. */
    public function expose_sideload_attachment( string $url, int $post_id ): string {
        $this->last_sideloader = new Disciple_Tools_CRM_Sync_Media_Sideloader();
        return $this->last_sideloader->sideload( $url, $post_id );
    }

    /** Returns the failure reason set by the most recent expose_sideload_attachment() call. */
    public function expose_sideload_last_error(): ?string {
        return $this->last_sideloader->get_last_error();
    }

    /** Expose process_single_contact() as public for direct testing. */
    public function expose_process_single_contact( string $respond_id, string $trigger_type, bool $skip_existing = true ): WP_Error|bool {
        return $this->process_single_contact( $respond_id, $trigger_type, $skip_existing );
    }

    /**
     * When set, process_single_contact() delegates to this callable instead of
     * running the real lifecycle. Signature: fn(string $respond_id): WP_Error|null
     * Used by boundary tests to inject selective per-contact failures without
     * needing to simulate full HTTP call sequences.
     */
    public $process_single_contact_fn = null;

    protected function process_single_contact( string $respond_id, string $trigger_type, bool $skip_existing = true ): WP_Error|bool {
        if ( null !== $this->process_single_contact_fn ) {
            // Callbacks return WP_Error|null; null means success — coerce to true
            // to match the parent's WP_Error|bool contract.
            return ( $this->process_single_contact_fn )( $respond_id, $trigger_type ) ?? true;
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
        $this->assertStringStartsWith( 'ssrf_blocked:', $this->processor->expose_sideload_last_error() );
    }

    public function test_sideload_attachment_routes_image_url_to_media_sideload_image(): void {
        Functions\when( 'apply_filters' )->alias(
            fn( $hook, $value ) => 'dt_crm_sync_sideload_allowed_hosts' === $hook
                ? [ 'cdn.respond.io', 'storage.respond.io' ]
                : $value
        );
        Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
        Functions\when( 'get_posts' )->justReturn( [] );
        Functions\when( 'media_sideload_image' )->justReturn( 9 );
        Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://local.test/wp-content/uploads/img.jpg' );

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
        Functions\when( 'get_posts' )->justReturn( [] );
        Functions\when( 'media_sideload_image' )->justReturn( new WP_Error( 'sideload_failed', 'Could not sideload' ) );

        $url    = 'https://cdn.respond.io/img.png';
        $result = $this->processor->expose_sideload_attachment( $url, 1 );

        $this->assertSame( $url, $result, 'Original URL must be returned when media_sideload_image fails.' );
        $this->assertStringStartsWith( 'image_wp_error:', $this->processor->expose_sideload_last_error() );
    }

    public function test_sideload_download_url_failure_sets_last_error(): void {
        Functions\when( 'apply_filters' )->alias(
            fn( $hook, $value ) => 'dt_crm_sync_sideload_allowed_hosts' === $hook
                ? [ 'cdn.respond.io', 'storage.respond.io' ]
                : $value
        );
        Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
        Functions\when( 'get_posts' )->justReturn( [] );
        Functions\when( 'download_url' )->justReturn( new WP_Error( 'http_request_failed', 'Connection timed out' ) );

        $url    = 'https://cdn.respond.io/doc.pdf';
        $result = $this->processor->expose_sideload_attachment( $url, 1 );

        $this->assertSame( $url, $result, 'Original URL must be returned when download_url fails.' );
        $this->assertStringStartsWith( 'download_failed:', $this->processor->expose_sideload_last_error() );
    }

    public function test_sideload_handle_sideload_failure_sets_last_error(): void {
        Functions\when( 'apply_filters' )->alias(
            fn( $hook, $value ) => 'dt_crm_sync_sideload_allowed_hosts' === $hook
                ? [ 'cdn.respond.io', 'storage.respond.io' ]
                : $value
        );
        Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
        Functions\when( 'get_posts' )->justReturn( [] );
        Functions\when( 'download_url' )->justReturn( '/tmp/crm-sync-tmp.pdf' );
        Functions\when( 'media_handle_sideload' )->justReturn( new WP_Error( 'upload_error', 'File type not permitted' ) );

        $url    = 'https://cdn.respond.io/doc.pdf';
        $result = $this->processor->expose_sideload_attachment( $url, 1 );

        $this->assertSame( $url, $result, 'Original URL must be returned when media_handle_sideload fails.' );
        $this->assertStringStartsWith( 'attach_wp_error:', $this->processor->expose_sideload_last_error() );
    }

    public function test_sideload_attachment_routes_non_image_through_download_url(): void {
        Functions\when( 'apply_filters' )->alias(
            fn( $hook, $value ) => 'dt_crm_sync_sideload_allowed_hosts' === $hook
                ? [ 'cdn.respond.io', 'storage.respond.io' ]
                : $value
        );
        Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
        Functions\when( 'get_posts' )->justReturn( [] );
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

// Per-contact locking (concurrent batch protection)

    /**
     * Two batches racing to import the same contact must not both create a DT
     * post — the loser of the lock is skipped, not treated as a failure.
     */
    public function test_process_single_contact_skips_when_locked_by_concurrent_import(): void {
        Functions\when( 'add_option' )->justReturn( false );
        Functions\when( 'get_option' )->alias(
            fn( $key, $default = false ) => str_starts_with( (string) $key, 'dt_crm_sync_contact_lock_' ) ? time() : $default
        );

        $result = $this->processor->expose_process_single_contact( '42', 'manual' );

        $this->assertTrue( $result );

        global $wpdb;
        $skipped = array_filter(
            $wpdb->insert_calls,
            fn( $c ) => 'skipped' === ( $c['data']['status'] ?? '' ) && str_contains( $c['data']['message'] ?? '', 'locked' )
        );
        $this->assertNotEmpty( $skipped, 'A skipped log entry must be written when the contact lock is held by a concurrent run.' );
    }

    /**
     * A lock left behind by a crashed process must not block imports forever —
     * once it's older than the batch execution ceiling it gets reclaimed.
     */
    public function test_process_single_contact_reclaims_a_stale_lock_and_proceeds(): void {
        Functions\when( 'get_posts' )->justReturn( [] );
        Functions\when( 'wp_safe_remote_request' )->justReturn( [ '_mocked' => true ] );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 429 );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{}' );
        Functions\when( 'wp_remote_retrieve_header' )->justReturn( '60' );

        $add_option_calls = 0;
        Functions\when( 'add_option' )->alias( function () use ( &$add_option_calls ) {
            ++$add_option_calls;
            return $add_option_calls > 1;
        } );
        Functions\when( 'get_option' )->alias(
            fn( $key, $default = false ) => str_starts_with( (string) $key, 'dt_crm_sync_contact_lock_' ) ? time() - 400 : $default
        );

        $result = $this->processor->expose_process_single_contact( '42', 'manual' );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'rate_limited', $result->get_error_code(), 'A stale lock must be reclaimed so processing still proceeds.' );
        $this->assertSame( 2, $add_option_calls, 'The stale lock must be reclaimed with exactly one retry.' );
    }

    /**
     * Two different Respond.io contacts that share a phone number and are
     * processed concurrently must not both create a DT post — the phone lock
     * catches what the connector-ID lock alone would miss.
     */
    public function test_process_single_contact_skips_when_phone_lock_is_held(): void {
        Functions\when( 'get_posts' )->justReturn( [] );
        Functions\when( 'wp_safe_remote_request' )->justReturn( [ '_mocked' => true ] );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"firstName":"Test","phone":"+15555550100","email":"t@example.com"}' );
        Functions\when( 'get_option' )->justReturn( [] );

        // The 1st add_option() call is the pre-merge-ID lock (must succeed so the
        // flow reaches phone extraction); the 2nd is the phone lock — simulate it
        // already being held by a concurrent run on a different Respond.io ID.
        $call = 0;
        Functions\when( 'add_option' )->alias( function () use ( &$call ) {
            ++$call;
            return 1 === $call;
        } );

        $result = $this->processor->expose_process_single_contact( '42', 'scheduled' );

        $this->assertTrue( $result );

        global $wpdb;
        $skipped = array_filter(
            $wpdb->insert_calls,
            fn( $c ) => 'skipped' === ( $c['data']['status'] ?? '' ) && str_contains( $c['data']['message'] ?? '', 'phone' )
        );
        $this->assertNotEmpty( $skipped, 'A phone lock contention must be logged and the contact skipped.' );
        $this->assertEmpty( DT_Posts::$create_post_calls, 'No DT post should be created while the phone lock is held elsewhere.' );
    }

    /**
     * Two different Respond.io contacts that share an email address (no shared
     * phone, no merge) and are processed concurrently must not both create a DT
     * post — find_by_phone_or_email() falls back to email, so email needs the
     * same protection the phone lock already gets.
     */
    public function test_process_single_contact_skips_when_email_lock_is_held(): void {
        Functions\when( 'get_posts' )->justReturn( [] );
        Functions\when( 'wp_safe_remote_request' )->justReturn( [ '_mocked' => true ] );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"firstName":"Test","email":"shared@example.com"}' );
        Functions\when( 'get_option' )->justReturn( [] );

        // The 1st add_option() call is the pre-merge-ID lock (must succeed so the
        // flow reaches email extraction; no phone in this profile, so the email
        // lock is the 2nd call) — simulate it already held by a concurrent run.
        $call = 0;
        Functions\when( 'add_option' )->alias( function () use ( &$call ) {
            ++$call;
            return 1 === $call;
        } );

        $result = $this->processor->expose_process_single_contact( '42', 'scheduled' );

        $this->assertTrue( $result );

        global $wpdb;
        $skipped = array_filter(
            $wpdb->insert_calls,
            fn( $c ) => 'skipped' === ( $c['data']['status'] ?? '' ) && str_contains( $c['data']['message'] ?? '', 'email' )
        );
        $this->assertNotEmpty( $skipped, 'An email lock contention must be logged and the contact skipped.' );
        $this->assertEmpty( DT_Posts::$create_post_calls, 'No DT post should be created while the email lock is held elsewhere.' );
    }

    /**
     * The email lock key must be case-insensitive, matching the case-insensitive
     * LIKE comparison find_by_phone_or_email() relies on for its email match.
     */
    public function test_process_single_contact_email_lock_key_is_case_insensitive(): void {
        Functions\when( 'get_posts' )->justReturn( [] );
        Functions\when( 'wp_safe_remote_request' )->justReturn( [ '_mocked' => true ] );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
        Functions\when( 'get_option' )->justReturn( [] );
        Functions\when( 'get_post_meta' )->justReturn( '' );
        Functions\when( 'add_post_meta' )->justReturn( true );
        Functions\when( 'update_post_meta' )->justReturn( true );

        $mock_writer = $this->createMock( Disciple_Tools_CRM_Sync_Activity_Feed_Writer::class );
        $this->processor->set_activity_feed_writer( $mock_writer );
        $mock_importer = $this->createMock( Disciple_Tools_CRM_Sync_Message_Importer::class );
        $mock_importer->method( 'import' )->willReturn( null );
        $this->processor->set_message_importer( $mock_importer );

        $captured_option_names = [];
        Functions\when( 'add_option' )->alias( function ( $option ) use ( &$captured_option_names ) {
            $captured_option_names[] = $option;
            return true;
        } );

        // Position [0] is the pre-merge-ID lock, [1] is the email lock (no phone present).
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"firstName":"Test","email":"Shared@Example.com"}' );
        $this->processor->expose_process_single_contact( '42', 'scheduled' );
        $mixed_case_lock = $captured_option_names[1] ?? null;

        $captured_option_names = [];
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"firstName":"Test","email":"shared@example.com"}' );
        $this->processor->expose_process_single_contact( '43', 'scheduled' );
        $lower_case_lock = $captured_option_names[1] ?? null;

        $this->assertNotNull( $mixed_case_lock, 'An email lock must be acquired when an email is present.' );
        $this->assertSame(
            $mixed_case_lock,
            $lower_case_lock,
            'A mixed-case email and its lowercase form must resolve to the same email lock.'
        );
    }

    /**
     * Two racing contacts whose phone numbers differ only by a country code (one
     * carries it, the other doesn't) must contend for the *same* lock, or the
     * country-code variant would sail past the lock entirely and duplicate.
     */
    public function test_process_single_contact_phone_lock_key_is_consistent_across_formats(): void {
        Functions\when( 'get_posts' )->justReturn( [] );
        Functions\when( 'wp_safe_remote_request' )->justReturn( [ '_mocked' => true ] );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
        // A default region (US: +1, 10-digit local) so the country-code and bare
        // forms of the same number canonicalize to a single lock key.
        Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
            if ( 'dt_crm_sync_settings' === $key ) {
                return [ 'default_country_code' => '1', 'national_number_length' => 10 ];
            }
            return $default;
        } );
        Functions\when( 'get_post_meta' )->justReturn( '' );
        Functions\when( 'add_post_meta' )->justReturn( true );
        Functions\when( 'update_post_meta' )->justReturn( true );

        $mock_writer = $this->createMock( Disciple_Tools_CRM_Sync_Activity_Feed_Writer::class );
        $this->processor->set_activity_feed_writer( $mock_writer );
        $mock_importer = $this->createMock( Disciple_Tools_CRM_Sync_Message_Importer::class );
        $mock_importer->method( 'import' )->willReturn( null );
        $this->processor->set_message_importer( $mock_importer );

        $captured_option_names = [];
        Functions\when( 'add_option' )->alias( function ( $option ) use ( &$captured_option_names ) {
            $captured_option_names[] = $option;
            return true;
        } );

        // Position [0] is the pre-merge-ID lock, [1] is the phone lock.
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"firstName":"Test","phone":"+1 (555) 555-0100","email":"t@example.com"}' );
        $this->processor->expose_process_single_contact( '42', 'scheduled' );
        $formatted_phone_lock = $captured_option_names[1] ?? null;

        $captured_option_names = [];
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"firstName":"Test","phone":"5555550100","email":"t@example.com"}' );
        $this->processor->expose_process_single_contact( '43', 'scheduled' );
        $bare_phone_lock = $captured_option_names[1] ?? null;

        $this->assertNotNull( $formatted_phone_lock, 'A phone lock must be acquired when a phone number is present.' );
        $this->assertSame(
            $formatted_phone_lock,
            $bare_phone_lock,
            'A country-code-formatted number and its bare form must resolve to the same phone lock.'
        );
    }

    /**
     * Two pre-merge Respond.io IDs that both canonicalize to the same contact
     * (a Respond.io-side merge) must not both create a DT post under that
     * canonical ID — the connector-ID lock alone doesn't cover this, since each
     * one is locked on its own pre-merge ID, not the shared canonical one.
     */
    public function test_process_single_contact_skips_when_canonical_merge_lock_is_held(): void {
        Functions\when( 'get_posts' )->justReturn( [] );
        Functions\when( 'wp_safe_remote_request' )->justReturn( [ '_mocked' => true ] );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
        // No phone/email in the profile so the only locks in play are the
        // pre-merge-ID lock and the canonical-ID lock this test is targeting.
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"id":"canonical_id","firstName":"Test"}' );
        Functions\when( 'get_option' )->justReturn( [] );

        // The 1st add_option() call is the pre-merge-ID lock (must succeed); the
        // 2nd is the canonical-ID lock — simulate it already being held by a
        // concurrent run processing a different pre-merge ID that merged the same way.
        $call = 0;
        Functions\when( 'add_option' )->alias( function () use ( &$call ) {
            ++$call;
            return 1 === $call;
        } );

        $result = $this->processor->expose_process_single_contact( 'stale_id', 'scheduled' );

        $this->assertTrue( $result );

        global $wpdb;
        $skipped = array_filter(
            $wpdb->insert_calls,
            fn( $c ) => 'skipped' === ( $c['data']['status'] ?? '' ) && str_contains( $c['data']['message'] ?? '', 'merge' )
        );
        $this->assertNotEmpty( $skipped, 'A canonical-ID lock contention must be logged and the contact skipped.' );
        $this->assertEmpty( DT_Posts::$create_post_calls, 'No DT post should be created while the canonical lock is held elsewhere.' );
    }

// skip_existing vs. incomplete history import

    /**
     * A contact that already exists AND finished importing its message history
     * is skipped, as before.
     */
    public function test_process_single_contact_skips_when_history_already_synced(): void {
        Functions\when( 'get_posts' )->justReturn( [ 10 ] );
        Functions\when( 'get_post_meta' )->justReturn( '1' );

        $result = $this->processor->expose_process_single_contact( '42', 'manual' );

        $this->assertTrue( $result );

        global $wpdb;
        $skipped = array_filter(
            $wpdb->insert_calls,
            fn( $c ) => 'skipped' === ( $c['data']['status'] ?? '' ) && 'skip_existing' === ( $c['data']['message'] ?? '' )
        );
        $this->assertNotEmpty( $skipped, 'Existing contact with a completed history sync must be skipped.' );
    }

    /**
     * A contact that exists but never finished importing message history (e.g. a
     * prior run was cut off mid-batch) must not be skipped — it needs a chance to
     * complete instead of being stuck without a conversation log forever.
     */
    public function test_process_single_contact_does_not_skip_when_history_not_yet_synced(): void {
        Functions\when( 'get_posts' )->justReturn( [ 10 ] );
        Functions\when( 'get_post_meta' )->justReturn( '' );
        Functions\when( 'wp_safe_remote_request' )->justReturn( [ '_mocked' => true ] );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 429 );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{}' );
        Functions\when( 'wp_remote_retrieve_header' )->justReturn( '60' );

        $result = $this->processor->expose_process_single_contact( '42', 'manual' );

        $this->assertInstanceOf(
            WP_Error::class,
            $result,
            'A contact whose history import never completed must not be skipped by skip_existing.'
        );
        $this->assertSame( 'rate_limited', $result->get_error_code() );
    }

    /**
     * After a message import succeeds, the history_synced flag must be written
     * so future polls know this contact is safe to skip.
     */
    public function test_process_single_contact_marks_history_synced_after_successful_message_import(): void {
        Functions\when( 'get_posts' )->justReturn( [] );
        Functions\when( 'wp_safe_remote_request' )->justReturn( [ '_mocked' => true ] );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"firstName":"Test","email":"t@example.com"}' );
        Functions\when( 'get_option' )->justReturn( [] );
        Functions\when( 'get_post_meta' )->justReturn( '' );
        Functions\when( 'add_post_meta' )->justReturn( true );

        $updated_meta = [];
        Functions\when( 'update_post_meta' )->alias( function ( $post_id, $key, $value ) use ( &$updated_meta ) {
            $updated_meta[] = [ 'post_id' => $post_id, 'key' => $key, 'value' => $value ];
            return true;
        } );

        $mock_writer = $this->createMock( Disciple_Tools_CRM_Sync_Activity_Feed_Writer::class );
        $this->processor->set_activity_feed_writer( $mock_writer );

        $mock_importer = $this->createMock( Disciple_Tools_CRM_Sync_Message_Importer::class );
        $mock_importer->method( 'import' )->willReturn( null );
        $this->processor->set_message_importer( $mock_importer );

        $result = $this->processor->expose_process_single_contact( '42', 'manual' );

        $this->assertTrue( $result );

        $history_meta = array_values( array_filter( $updated_meta, fn( $m ) => str_contains( $m['key'], 'history_synced' ) ) );
        $this->assertNotEmpty( $history_meta, 'history_synced meta must be written after a successful message import.' );
        $this->assertSame( '1', $history_meta[0]['value'] );
    }

    /**
     * A contact matched only by a shared phone number — one this sync never
     * created (it carries no connector-ID meta) — must be left completely
     * untouched when "update existing contacts" is off. Its name and every other
     * field stay exactly as the operator left them.
     */
    public function test_process_single_contact_skips_existing_matched_by_phone(): void {
        global $wpdb;
        Functions\when( 'get_posts' )->justReturn( [] );
        Functions\when( 'get_option' )->justReturn( [] );
        Functions\when( 'wp_safe_remote_request' )->justReturn( [ '_mocked' => true ] );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"firstName":"Incoming Name","phone":"+15555550100"}' );

        // No connector-ID match, but the phone lookup finds an existing DT post.
        $wpdb->next_get_var_result = 55;

        // A foreign contact must not have its conversation log written either.
        $mock_importer = $this->createMock( Disciple_Tools_CRM_Sync_Message_Importer::class );
        $mock_importer->expects( $this->never() )->method( 'import' );
        $this->processor->set_message_importer( $mock_importer );

        $result = $this->processor->expose_process_single_contact( '42', 'scheduled' );

        $this->assertTrue( $result );
        $this->assertEmpty( DT_Posts::$update_post_calls, 'An existing contact matched by phone must not be updated when skip_existing is on.' );
        $this->assertEmpty( DT_Posts::$create_post_calls, 'No duplicate contact may be created for a phone match.' );

        $skipped = array_filter(
            $wpdb->insert_calls,
            fn( $c ) => 'skipped' === ( $c['data']['status'] ?? '' ) && str_contains( $c['data']['message'] ?? '', 'phone/email' )
        );
        $this->assertNotEmpty( $skipped, 'The phone/email match must be logged as skipped for skip_existing.' );
    }

    /**
     * A contact this sync previously created (connector-ID match) whose message
     * history never finished importing is allowed to finish it even with
     * skip_existing on — but the name and other fields must not be rewritten
     * while that catch-up runs.
     */
    public function test_process_single_contact_completes_history_without_rewriting_fields(): void {
        global $wpdb;
        Functions\when( 'get_posts' )->justReturn( [ 10 ] );
        Functions\when( 'get_option' )->justReturn( [] );
        Functions\when( 'wp_safe_remote_request' )->justReturn( [ '_mocked' => true ] );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"firstName":"Incoming Name","email":"t@example.com"}' );
        // history_synced meta is unset — a prior run was cut off mid-import.
        Functions\when( 'get_post_meta' )->justReturn( '' );

        $updated_meta = [];
        Functions\when( 'update_post_meta' )->alias( function ( $post_id, $key, $value ) use ( &$updated_meta ) {
            $updated_meta[] = [ 'key' => $key, 'value' => $value ];
            return true;
        } );

        $mock_writer = $this->createMock( Disciple_Tools_CRM_Sync_Activity_Feed_Writer::class );
        $mock_writer->expects( $this->never() )->method( 'upsert' );
        $this->processor->set_activity_feed_writer( $mock_writer );

        $mock_importer = $this->createMock( Disciple_Tools_CRM_Sync_Message_Importer::class );
        $mock_importer->expects( $this->once() )->method( 'import' )->willReturn( null );
        $this->processor->set_message_importer( $mock_importer );

        $result = $this->processor->expose_process_single_contact( '42', 'scheduled' );

        $this->assertTrue( $result );
        $this->assertEmpty( DT_Posts::$update_post_calls, 'Fields must not be rewritten while completing an interrupted history import.' );
        $this->assertEmpty( DT_Posts::$create_post_calls );

        $history_meta = array_filter( $updated_meta, fn( $m ) => str_contains( $m['key'], 'history_synced' ) );
        $this->assertNotEmpty( $history_meta, 'history_synced must be written once the interrupted import completes.' );
    }

    /**
     * With "update existing contacts" enabled, an existing contact IS updated —
     * the new skip guard must not over-reach and block a deliberate update.
     */
    public function test_process_single_contact_updates_existing_when_update_enabled(): void {
        Functions\when( 'get_posts' )->justReturn( [ 10 ] );
        Functions\when( 'get_option' )->justReturn( [] );
        Functions\when( 'wp_safe_remote_request' )->justReturn( [ '_mocked' => true ] );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"firstName":"Incoming Name","email":"t@example.com"}' );
        Functions\when( 'get_post_meta' )->justReturn( '' );
        Functions\when( 'add_post_meta' )->justReturn( true );

        $mock_writer = $this->createMock( Disciple_Tools_CRM_Sync_Activity_Feed_Writer::class );
        $this->processor->set_activity_feed_writer( $mock_writer );
        $mock_importer = $this->createMock( Disciple_Tools_CRM_Sync_Message_Importer::class );
        $mock_importer->method( 'import' )->willReturn( null );
        $this->processor->set_message_importer( $mock_importer );

        $result = $this->processor->expose_process_single_contact( '42', 'scheduled', false );

        $this->assertTrue( $result );
        $this->assertNotEmpty( DT_Posts::$update_post_calls, 'An existing contact must be updated when update-existing is enabled.' );
        $this->assertSame( 10, end( DT_Posts::$update_post_calls )['id'] ?? null );
    }

    /**
     * A CRM-side merge that adopts an already-existing canonical contact must not
     * rewrite that contact's fields when skip_existing is on. The merge routing
     * (and completing an unfinished history import) is still allowed.
     */
    public function test_process_single_contact_merge_does_not_rewrite_existing_fields_when_skip_existing(): void {
        global $wpdb;
        // 1st get_posts call: pre-merge-ID lookup -> not found. 2nd: canonical-ID lookup -> found (77).
        $get_posts_call = 0;
        Functions\when( 'get_posts' )->alias( function () use ( &$get_posts_call ) {
            $get_posts_call++;
            return 1 === $get_posts_call ? [] : [ 77 ];
        } );
        Functions\when( 'get_option' )->justReturn( [] );
        Functions\when( 'wp_safe_remote_request' )->justReturn( [ '_mocked' => true ] );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
        // No phone/email, so the only match is the canonical post via the merge branch.
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"id":"canonical_id","firstName":"Incoming Name"}' );
        // history_synced unset — the canonical post's import never finished.
        Functions\when( 'get_post_meta' )->justReturn( '' );

        $mock_writer = $this->createMock( Disciple_Tools_CRM_Sync_Activity_Feed_Writer::class );
        $mock_writer->expects( $this->never() )->method( 'upsert' );
        $this->processor->set_activity_feed_writer( $mock_writer );

        $mock_importer = $this->createMock( Disciple_Tools_CRM_Sync_Message_Importer::class );
        $mock_importer->expects( $this->once() )->method( 'import' )->willReturn( null );
        $this->processor->set_message_importer( $mock_importer );

        $result = $this->processor->expose_process_single_contact( 'stale_id', 'scheduled' );

        $this->assertTrue( $result );
        $this->assertEmpty( DT_Posts::$update_post_calls, 'A merged canonical contact must not have its fields rewritten under skip_existing.' );
        $this->assertEmpty( DT_Posts::$create_post_calls );

        $merged = array_filter(
            $wpdb->insert_calls,
            fn( $c ) => 'merged' === ( $c['data']['status'] ?? '' ) && str_contains( $c['data']['message'] ?? '', 'canonical ID only' )
        );
        $this->assertNotEmpty( $merged, 'The canonical-ID adoption must still be logged as a merge.' );
    }

// Merged contact handling

    /**
     * When both the stale post and the canonical post already exist in DT, the
     * connector-ID meta on the abandoned post must be removed so future polls
     * don't keep routing to it. A 'merged' log entry must also be written.
     */
    public function test_process_contact_merge_canonical_exists_clears_stale_meta_and_logs(): void {
        // First get_posts call → stale post (10). Second → canonical post (20).
        $get_posts_call = 0;
        Functions\when( 'get_posts' )->alias( function () use ( &$get_posts_call ) {
            $get_posts_call++;
            return 1 === $get_posts_call ? [ 10 ] : [ 20 ];
        } );

        // Profile returns a different ID — signals a CRM-side merge.
        Functions\when( 'wp_safe_remote_request' )->justReturn( [ '_mocked' => true ] );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"id":"canonical_id","firstName":"Test","email":"t@example.com"}' );
        Functions\when( 'get_option' )->justReturn( [] );

        // get_post_meta: return the canonical ID so the post-write meta guard
        // sees it as already written and skips add_post_meta.
        Functions\when( 'get_post_meta' )->justReturn( 'canonical_id' );

        $deleted_meta = [];
        Functions\when( 'delete_post_meta' )->alias( function ( $post_id, $key ) use ( &$deleted_meta ) {
            $deleted_meta[] = [ 'post_id' => $post_id, 'key' => $key ];
            return true;
        } );

        $mock_writer = $this->createMock( Disciple_Tools_CRM_Sync_Activity_Feed_Writer::class );
        $this->processor->set_activity_feed_writer( $mock_writer );

        $mock_importer = $this->createMock( Disciple_Tools_CRM_Sync_Message_Importer::class );
        $mock_importer->method( 'import' )->willReturn( null );
        $this->processor->set_message_importer( $mock_importer );

        $this->processor->expose_process_single_contact( 'stale_id', 'scheduled', false );

        // Stale post's connector meta must have been deleted.
        $this->assertNotEmpty( $deleted_meta, 'delete_post_meta() must be called to clear the stale connector ID.' );
        $this->assertSame( 10, $deleted_meta[0]['post_id'], 'The stale post (10) must be dereferenced, not the canonical.' );
        $this->assertStringContainsString( 'id', $deleted_meta[0]['key'], 'The connector-ID meta key must be deleted.' );

        // A 'merged' log entry must have been written.
        global $wpdb;
        $merged_rows = array_filter(
            $wpdb->insert_calls,
            fn( $c ) => ( $c['data']['status'] ?? '' ) === 'merged'
        );
        $this->assertNotEmpty( $merged_rows, 'A merged log entry must be written.' );
        $merged = array_values( $merged_rows )[0]['data'];
        $this->assertStringContainsString( 'stale_id', $merged['message'], 'Merge log must reference the absorbed (stale) contact ID.' );
    }

    /**
     * When only the stale post exists (canonical not yet in DT), the meta is
     * repointed to the new ID on the same post — no post is abandoned so
     * delete_post_meta() must not be called. A 'merged' log entry must still fire.
     */
    public function test_process_contact_merge_no_canonical_post_logs_merge(): void {
        // First get_posts call → stale post (10). Second → no canonical post.
        $get_posts_call = 0;
        Functions\when( 'get_posts' )->alias( function () use ( &$get_posts_call ) {
            $get_posts_call++;
            return 1 === $get_posts_call ? [ 10 ] : [];
        } );

        Functions\when( 'wp_safe_remote_request' )->justReturn( [ '_mocked' => true ] );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"id":"canonical_id","firstName":"Test","email":"t@example.com"}' );
        Functions\when( 'get_option' )->justReturn( [] );

        Functions\when( 'get_post_meta' )->justReturn( 'canonical_id' );

        $delete_called = false;
        Functions\when( 'delete_post_meta' )->alias( function () use ( &$delete_called ) {
            $delete_called = true;
            return true;
        } );

        $mock_writer = $this->createMock( Disciple_Tools_CRM_Sync_Activity_Feed_Writer::class );
        $this->processor->set_activity_feed_writer( $mock_writer );

        $mock_importer = $this->createMock( Disciple_Tools_CRM_Sync_Message_Importer::class );
        $mock_importer->method( 'import' )->willReturn( null );
        $this->processor->set_message_importer( $mock_importer );

        $this->processor->expose_process_single_contact( 'stale_id', 'scheduled', false );

        $this->assertFalse( $delete_called, 'delete_post_meta() must not be called when only one post exists.' );

        global $wpdb;
        $merged_rows = array_filter(
            $wpdb->insert_calls,
            fn( $c ) => ( $c['data']['status'] ?? '' ) === 'merged'
        );
        $this->assertNotEmpty( $merged_rows, 'A merged log entry must be written even when no canonical post existed.' );
        $merged = array_values( $merged_rows )[0]['data'];
        $this->assertStringContainsString( 'stale_id', $merged['message'], 'Merge log must reference the absorbed (stale) contact ID.' );
    }

    /**
     * When a merge is detected but this pre-merge ID has no phone/email of its
     * own to match an existing post (e.g. a bare social-media channel), and the
     * canonical post already exists from some other channel, the existing post
     * must be adopted — not left to fall through into creating a duplicate.
     */
    public function test_process_contact_merge_adopts_existing_canonical_post_when_id_has_no_other_match(): void {
        // 1st get_posts call: pre-merge-ID lookup -> not found. 2nd: canonical-ID lookup -> found (77).
        $get_posts_call = 0;
        Functions\when( 'get_posts' )->alias( function () use ( &$get_posts_call ) {
            $get_posts_call++;
            return 1 === $get_posts_call ? [] : [ 77 ];
        } );

        Functions\when( 'wp_safe_remote_request' )->justReturn( [ '_mocked' => true ] );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
        // No phone/email in the profile — nothing for the fallback lookup to match.
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"id":"canonical_id","firstName":"Test"}' );
        Functions\when( 'get_option' )->justReturn( [] );
        Functions\when( 'get_post_meta' )->justReturn( 'canonical_id' );
        Functions\when( 'update_post_meta' )->justReturn( true );

        $mock_writer = $this->createMock( Disciple_Tools_CRM_Sync_Activity_Feed_Writer::class );
        $this->processor->set_activity_feed_writer( $mock_writer );
        $mock_importer = $this->createMock( Disciple_Tools_CRM_Sync_Message_Importer::class );
        $mock_importer->method( 'import' )->willReturn( null );
        $this->processor->set_message_importer( $mock_importer );

        $this->processor->expose_process_single_contact( 'channel_only_id', 'scheduled', false );

        $this->assertEmpty( DT_Posts::$create_post_calls, 'The existing canonical post must be updated, not duplicated.' );
        $this->assertNotEmpty( DT_Posts::$update_post_calls, 'The existing canonical post must be updated.' );
        $this->assertSame( 77, end( DT_Posts::$update_post_calls )['id'] ?? null );

        global $wpdb;
        $merged_rows = array_filter(
            $wpdb->insert_calls,
            fn( $c ) => ( $c['data']['status'] ?? '' ) === 'merged' && str_contains( $c['data']['message'] ?? '', 'canonical ID only' )
        );
        $this->assertNotEmpty( $merged_rows, 'A merged log entry describing the canonical-only match must be written.' );
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
