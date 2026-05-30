<?php
/**
 * Unit tests for admin tab POST handlers.
 *
 * Covers the form-submission paths of Tab_Config, Tab_Automations, and Tab_Logs.
 * Each test simulates a POST by populating $_POST, mocking WP/DT functions via
 * Brain Monkey, calling content(), and asserting the expected side-effects
 * (update_option, delete_option, $wpdb->query, etc.).
 *
 * Output from the render pass is discarded with ob_start() / ob_end_clean()
 * so that HTML echo statements in the tab methods do not pollute test output.
 *
 * Run with: php vendor/bin/phpunit -c phpunit-unit.xml.dist --testdox
 */

use Brain\Monkey\Functions;

// Load admin tab classes
// These files are required here (after Patchwork is active) so every WP
// function they call is instrumentable by Brain Monkey.
$_tab_root = dirname( __DIR__, 2 ) . '/admin/tabs';
require_once $_tab_root . '/class-tab-config.php';
require_once $_tab_root . '/class-tab-automations.php';
require_once $_tab_root . '/class-tab-logs.php';

// Tests

class AdminTabTest extends BrainMonkeyTestCase {

    protected function setUp(): void {
        parent::setUp();

        // Functions defined in wp-function-stubs.php have sensible defaults and
        // do NOT need re-stubbing here unless a specific test overrides them.
        // Only stub functions whose default stub returns the wrong type or causes
        // a fatal when called inside content().

        // sanitize_key / sanitize_text_field / wp_unslash are defined in
        // bootstrap.php (before Patchwork) — they are NOT re-mockable.
        // Their bootstrap stubs return the input unchanged, which is fine.

        // wp_json_encode must delegate to json_encode.
        Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

        // Nonce verification: always pass in tests.
        Functions\when( 'check_admin_referer' )->justReturn( 1 );

        // Capability check: allow by default so existing tests pass.
        // Individual tests that cover the capability-denied path override this.
        Functions\when( 'current_user_can' )->justReturn( true );

        // Connector registry: return Respond_IO class via apply_filters.
        Functions\when( 'apply_filters' )->alias(
            static function ( string $hook, $value ) {
                if ( 'dt_crm_sync_connectors' === $hook ) {
                    return [ 'respond_io' => 'Disciple_Tools_CRM_Sync_Connector_Respond_IO' ];
                }
                return $value;
            }
        );

        // Reset superglobals for isolation.
        $_POST = [];
        $_GET  = [];
    }

    protected function tearDownTest(): void {
        $_POST = [];
        $_GET  = [];
    }

    /**
     * Run a tab's content() method, discarding all output.
     * Uses a try/finally to ensure the output buffer is always closed even
     * when an exception (or test error) occurs inside content().
     */
    private function run_tab_content( callable $content_fn ): void {
        ob_start();
        try {
            $content_fn();
        } finally {
            ob_end_clean();
        }
    }

// Tab_Config — settings save

    /**
     * A valid POST (with nonce) must call update_option() to persist
     * the new connector selection and purge_on_uninstall flag.
     */
    public function test_tab_config_post_saves_settings_via_update_option(): void {
        $_POST = [
            'dt_crm_sync_nonce'  => 'test_nonce',
            'save_settings'      => '1',
            'active_connector'   => 'respond_io',
            'purge_on_uninstall' => '1',
            'connectors'         => [ 'respond_io' => [] ],
        ];

        Functions\when( 'get_option' )->alias(
            static function ( string $key, $default = false ) {
                if ( 'dt_crm_sync_settings' === $key ) {
                    return [];
                }
                return $default;
            }
        );

        $saved = null;
        Functions\when( 'update_option' )->alias(
            static function ( string $key, $value ) use ( &$saved ) {
                if ( 'dt_crm_sync_settings' === $key ) {
                    $saved = $value;
                }
            }
        );

        ob_start();
        try { ( new Disciple_Tools_CRM_Sync_Tab_Config() )->content();
        } finally { ob_end_clean();
        };

        $this->assertNotNull( $saved, 'update_option("dt_crm_sync_settings") must be called on POST.' );
        $this->assertSame( 'respond_io', $saved['active_connector'] );
        $this->assertTrue( $saved['purge_on_uninstall'] );
    }

    /**
     * When a password field is submitted as an empty string, the existing
     * encrypted ciphertext stored in the database must be preserved unchanged.
     */
    public function test_config_tab_preserves_blank_password(): void {
        $existing_ciphertext = 'existing_encrypted_token_value';

        $_POST = [
            'dt_crm_sync_nonce' => 'test_nonce',
            'save_settings'     => '1',
            'active_connector'  => 'respond_io',
            'connectors'        => [
                'respond_io' => [
                    'api_url'            => 'https://api.test',
                    'api_token'          => '',  // intentionally blank
                    'webhook_signing_key' => '', // intentionally blank
                ],
            ],
        ];

        Functions\when( 'get_option' )->alias(
            static function ( string $key, $default = false ) use ( $existing_ciphertext ) {
                if ( 'dt_crm_sync_settings' === $key ) {
                    return [
                        'active_connector' => 'respond_io',
                        'connectors'       => [
                            'respond_io' => [
                                'api_url'             => 'https://api.test',
                                'api_token'           => $existing_ciphertext,
                                'webhook_signing_key' => $existing_ciphertext,
                            ],
                        ],
                    ];
                }
                return $default;
            }
        );

        $saved = null;
        Functions\when( 'update_option' )->alias(
            static function ( string $key, $value ) use ( &$saved ) {
                if ( 'dt_crm_sync_settings' === $key ) {
                    $saved = $value;
                }
            }
        );

        ob_start();
        try { ( new Disciple_Tools_CRM_Sync_Tab_Config() )->content();
        } finally { ob_end_clean();
        };

        $creds = $saved['connectors']['respond_io'] ?? [];
        $this->assertSame(
            $existing_ciphertext,
            $creds['api_token'],
            'Blank password field must preserve the existing encrypted ciphertext.'
        );
        $this->assertSame(
            $existing_ciphertext,
            $creds['webhook_signing_key'],
            'Blank webhook_signing_key field must preserve the existing encrypted ciphertext.'
        );
    }

    /**
     * When a non-empty API token is submitted, encrypt_value() must be called
     * and the result stored rather than the plaintext.
     */
    public function test_tab_config_encrypts_newly_submitted_password_field(): void {
        $_POST = [
            'dt_crm_sync_nonce' => 'test_nonce',
            'save_settings'     => '1',
            'active_connector'  => 'respond_io',
            'connectors'        => [
                'respond_io' => [
                    'api_url'   => 'https://api.test',
                    'api_token' => 'new_plaintext_token',
                ],
            ],
        ];

        Functions\when( 'get_option' )->alias(
            static function ( string $key, $default = false ) {
                if ( 'dt_crm_sync_settings' === $key ) {
                    return [];
                }
                if ( 'dt_crm_sync_encryption_key' === $key ) {
                    // Valid 32-byte base64 key so encrypt_value() succeeds.
                    return base64_encode( str_repeat( "\x01", 32 ) );
                }
                return $default;
            }
        );

        $saved = null;
        Functions\when( 'update_option' )->alias(
            static function ( string $key, $value ) use ( &$saved ) {
                if ( 'dt_crm_sync_settings' === $key ) {
                    $saved = $value;
                }
            }
        );

        ob_start();
        try { ( new Disciple_Tools_CRM_Sync_Tab_Config() )->content();
        } finally { ob_end_clean();
        };

        $stored_token = $saved['connectors']['respond_io']['api_token'] ?? '';
        $this->assertNotSame(
            'new_plaintext_token',
            $stored_token,
            'The stored token must not be plaintext — it must have been encrypted.'
        );
        $this->assertNotEmpty( $stored_token, 'A non-empty encrypted token must be stored.' );
    }

// Tab_Automations — create filter

    /**
     * A valid create-filter POST must call Disciple_Tools_CRM_Sync::create_filter()
     * which in turn calls update_option() for both the envelope and the manifest.
     */
    public function test_tab_automations_create_filter_post_persists_filter(): void {
        $_POST = [
            'dt_crm_sync_automations_nonce' => 'test_nonce',
            'filter_name'                   => 'My Test Filter',
            'interval'                      => 'hourly',
            'filter_poll_time'              => '00:00',
        ];

        $options_saved = [];
        Functions\when( 'get_option' )->alias(
            static function ( string $key, $default = false ) {
                if ( 'dt_crm_sync_settings' === $key ) {
                    return [ 'active_connector' => 'respond_io' ];
                }
                if ( 'dt_crm_sync_saved_filters' === $key ) {
                    return [];
                }
                return $default;
            }
        );

        Functions\when( 'update_option' )->alias(
            static function ( string $key, $value ) use ( &$options_saved ) {
                $options_saved[ $key ] = $value;
            }
        );

        ob_start();
        try { ( new Disciple_Tools_CRM_Sync_Tab_Automations() )->content();
        } finally { ob_end_clean();
        };

        // At least one dt_crm_sync_saved_filter_* option must have been created.
        $filter_options = array_filter(
            array_keys( $options_saved ),
            static fn( $k ) => str_starts_with( $k, 'dt_crm_sync_saved_filter_' )
        );
        $this->assertNotEmpty( $filter_options, 'create_filter() must save a dt_crm_sync_saved_filter_* option.' );

        // The manifest must be updated to include the new filter ID.
        $this->assertArrayHasKey( 'dt_crm_sync_saved_filters', $options_saved );
        $this->assertNotEmpty( $options_saved['dt_crm_sync_saved_filters'] );

        // The saved envelope must contain the submitted name and interval.
        $envelope_key     = reset( $filter_options );
        $envelope         = json_decode( $options_saved[ $envelope_key ], true );
        $this->assertSame( 'My Test Filter', $envelope['name'] );
        $this->assertSame( 'hourly', $envelope['interval'] );
    }

    /**
     * When filter_name is blank, no option should be saved and no cron event scheduled.
     */
    public function test_tab_automations_create_filter_requires_nonempty_name(): void {
        $_POST = [
            'dt_crm_sync_automations_nonce' => 'test_nonce',
            'filter_name'                   => '',
            'interval'                      => 'hourly',
            'filter_poll_time'              => '00:00',
        ];

        Functions\when( 'get_option' )->justReturn( [] );
        Functions\expect( 'update_option' )->never();
        Functions\expect( 'wp_schedule_event' )->never();

        ob_start();
        try { ( new Disciple_Tools_CRM_Sync_Tab_Automations() )->content();
        } finally { ob_end_clean();
        };
    }

// Tab_Automations — delete filter

    /**
     * A valid delete-filter POST must clear the cron hook, delete the option,
     * and update the manifest.
     */
    public function test_tab_automations_delete_filter_clears_cron_and_removes_option(): void {
        $filter_id = 'filter_testdelete';

        $_POST = [
            'dt_crm_sync_delete_nonce' => 'test_nonce',
            'filter_id'                => $filter_id,
        ];

        Functions\when( 'get_option' )->alias(
            static function ( string $key, $default = false ) use ( $filter_id ) {
                if ( 'dt_crm_sync_saved_filters' === $key ) {
                    return [ $filter_id, 'filter_other' ];
                }
                return $default;
            }
        );

        $cleared_hooks  = [];
        $deleted_opts   = [];
        $manifest_saved = null;

        Functions\when( 'wp_clear_scheduled_hook' )->alias(
            static function ( string $hook ) use ( &$cleared_hooks ): int|false {
                $cleared_hooks[] = $hook;
                return false;
            }
        );
        Functions\when( 'delete_option' )->alias(
            static function ( string $key ) use ( &$deleted_opts ) {
                $deleted_opts[] = $key;
            }
        );
        Functions\when( 'update_option' )->alias(
            static function ( string $key, $value ) use ( &$manifest_saved ) {
                if ( 'dt_crm_sync_saved_filters' === $key ) {
                    $manifest_saved = $value;
                }
            }
        );

        ob_start();
        try { ( new Disciple_Tools_CRM_Sync_Tab_Automations() )->content();
        } finally { ob_end_clean();
        };

        $this->assertContains(
            'dt_crm_sync_poll',
            $cleared_hooks,
            'wp_clear_scheduled_hook must be called for dt_crm_sync_poll.'
        );
        $this->assertContains(
            'dt_crm_sync_saved_filter_' . $filter_id,
            $deleted_opts,
            'The filter option must be deleted.'
        );
        $this->assertNotNull( $manifest_saved, 'Manifest must be updated after deletion.' );
        $this->assertNotContains(
            $filter_id,
            $manifest_saved,
            'Deleted filter ID must not remain in manifest.'
        );
        $this->assertContains(
            'filter_other',
            $manifest_saved,
            'Other filter IDs must be preserved in the manifest.'
        );
    }

    /**
     * Attempting to delete a filter_id that is not in the manifest must call wp_die().
     */
    public function test_tab_automations_delete_filter_dies_for_unknown_filter_id(): void {
        $_POST = [
            'dt_crm_sync_delete_nonce' => 'test_nonce',
            'filter_id'                => 'filter_nonexistent',
        ];

        Functions\when( 'get_option' )->justReturn( [ 'filter_legitimate' ] );
        Functions\expect( 'wp_die' )->once();

        ob_start();
        try {
            ( new Disciple_Tools_CRM_Sync_Tab_Automations() )->content();
        } catch ( \Throwable $e ) {
            // wp_die() is mocked as a no-op; if it throws for any reason, swallow it.
        }
        ob_end_clean();
    }

// Tab_Logs — clear logs

    /**
     * A valid clear-logs POST must execute a DELETE query against the logs table.
     */
    public function test_tab_logs_clear_logs_post_executes_delete_query(): void {
        global $wpdb;

        $_POST = [
            'dt_crm_sync_clear_logs_nonce' => 'test_nonce',
            'action'                       => 'clear_logs',
        ];
        $_GET  = [];

        $wpdb->last_query_sql = null;

        Functions\when( 'get_option' )->justReturn( [] );

        ob_start();
        try { ( new Disciple_Tools_CRM_Sync_Tab_Logs() )->content();
        } finally { ob_end_clean();
        };

        $this->assertNotNull(
            $wpdb->last_query_sql,
            '$wpdb->query() must be called to delete log entries.'
        );
        $this->assertStringContainsString(
            'DELETE',
            strtoupper( $wpdb->last_query_sql ),
            'The executed query must be a DELETE statement.'
        );
        $this->assertStringContainsString(
            'dt_crm_sync_logs',
            $wpdb->last_query_sql,
            'The DELETE query must target the dt_crm_sync_logs table.'
        );
    }

// Nonce failure paths

    /**
     * When check_admin_referer() dies (nonce invalid), Tab_Config must not
     * persist anything — no update_option, no wpdb write.
     */
    public function test_config_tab_aborts_invalid_nonce(): void {
        $_POST['dt_crm_sync_nonce'] = 'bad_nonce';

        Functions\when( 'check_admin_referer' )->alias(
            fn() => throw new \RuntimeException( 'Nonce check failed.' )
        );

        $this->expectException( \RuntimeException::class );
        ( new Disciple_Tools_CRM_Sync_Tab_Config() )->content();
    }

    /**
     * When check_admin_referer() dies on a create-filter POST, Tab_Automations
     * must not schedule any event or persist any option.
     */
    public function test_automations_tab_aborts_invalid_nonce(): void {
        $_POST['dt_crm_sync_automations_nonce'] = 'bad_nonce';

        Functions\when( 'check_admin_referer' )->alias(
            fn() => throw new \RuntimeException( 'Nonce check failed.' )
        );

        $this->expectException( \RuntimeException::class );
        ( new Disciple_Tools_CRM_Sync_Tab_Automations() )->content();
    }

    /**
     * When check_admin_referer() dies on a clear-logs POST, Tab_Logs must not
     * execute any database query.
     */
    public function test_logs_tab_aborts_invalid_nonce(): void {
        $_POST['dt_crm_sync_clear_logs_nonce'] = 'bad_nonce';

        Functions\when( 'check_admin_referer' )->alias(
            fn() => throw new \RuntimeException( 'Nonce check failed.' )
        );

        $this->expectException( \RuntimeException::class );
        ( new Disciple_Tools_CRM_Sync_Tab_Logs() )->content();
    }

// Capability (manage_dt) failure paths

    /**
     * When current_user_can('manage_dt') returns false, Tab_Config must call
     * wp_die() and must not persist any settings.
     */
    public function test_config_tab_aborts_unauthorized(): void {
        $_POST['dt_crm_sync_nonce'] = 'valid_nonce';

        Functions\when( 'current_user_can' )->justReturn( false );
        Functions\when( 'wp_die' )->alias(
            fn() => throw new \RuntimeException( 'wp_die called — permission denied.' )
        );

        $this->expectException( \RuntimeException::class );
        ( new Disciple_Tools_CRM_Sync_Tab_Config() )->content();
    }

    /**
     * When current_user_can('manage_dt') returns false on a create-filter POST,
     * Tab_Automations must call wp_die() and must not save any option.
     */
    public function test_automations_tab_aborts_unauthorized(): void {
        $_POST['dt_crm_sync_automations_nonce'] = 'valid_nonce';

        Functions\when( 'current_user_can' )->justReturn( false );
        Functions\when( 'wp_die' )->alias(
            fn() => throw new \RuntimeException( 'wp_die called — permission denied.' )
        );

        $this->expectException( \RuntimeException::class );
        ( new Disciple_Tools_CRM_Sync_Tab_Automations() )->content();
    }

    /**
     * When current_user_can('manage_dt') returns false on a clear-logs POST,
     * Tab_Logs must call wp_die() and must not execute any query.
     */
    public function test_logs_tab_aborts_unauthorized(): void {
        $_POST['dt_crm_sync_clear_logs_nonce'] = 'valid_nonce';

        Functions\when( 'current_user_can' )->justReturn( false );
        Functions\when( 'wp_die' )->alias(
            fn() => throw new \RuntimeException( 'wp_die called — permission denied.' )
        );

        $this->expectException( \RuntimeException::class );
        ( new Disciple_Tools_CRM_Sync_Tab_Logs() )->content();
    }

// class-tab-automations.php : next-poll legacy hook fallback (fix 3.1)

    public function test_automations_tab_legacy_hook_fallback(): void {
        Functions\when( 'get_option' )->alias(
            function ( $key, $default = false ) {
                if ( 'dt_crm_sync_saved_filters' === $key ) {
                    return [ 'testfilter' ];
                }
                if ( 'dt_crm_sync_saved_filter_testfilter' === $key ) {
                    return json_encode( [ 'name' => 'My Filter', 'interval' => 'hourly', 'filter_params' => [ 'search' => '' ] ] );
                }
                return $default;
            }
        );

        // New unified hook returns false; legacy per-filter hook has a timestamp.
        Functions\when( 'wp_next_scheduled' )->alias(
            function ( $hook, $args = [] ) {
                if ( 'dt_crm_sync_poll' === $hook ) {
                    return false;
                }
                if ( 'dt_crm_sync_poll_testfilter' === $hook ) {
                    return 1748246400;
                }
                return false;
            }
        );

        // Return a predictable formatted date for any wp_date() call.
        Functions\when( 'wp_date' )->justReturn( '2025-05-26 00:00' );

        $html = '';
        ob_start();
        try {
            ( new Disciple_Tools_CRM_Sync_Tab_Automations() )->content();
        } finally {
            $html = ob_get_clean() ?: '';
        }

        $this->assertStringContainsString(
            '2025-05-26 00:00',
            $html,
            'The legacy hook timestamp must be rendered when the primary hook returns false.'
        );
        $this->assertStringNotContainsString(
            'Not scheduled',
            $html,
            'The "Not scheduled" placeholder must not appear when a legacy hook timestamp exists.'
        );
    }
}
