<?php
/**
 * Unit tests for the uninstall routine.
 *
 * Exercises uninstall.php without a real database. The file is require'd
 * directly after the constant guard is satisfied and all WP functions are
 * stubbed via Brain Monkey, so the tests run in the same isolated environment
 * as the rest of the unit suite.
 *
 * Run with: php vendor/bin/phpunit -c phpunit-unit.xml.dist --testdox
 */

use Brain\Monkey\Functions;

// The constant guard at the top of uninstall.php prevents execution outside
// of a real uninstall context. Define it here so the file can be require'd.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    define( 'WP_UNINSTALL_PLUGIN', 'disciple-tools-crm-sync/disciple-tools-crm-sync.php' );
}

class UninstallTest extends BrainMonkeyTestCase {

    /** Keys captured by the delete_post_meta_by_key() stub during a test run. */
    private array $deleted_post_meta_keys = [];

    protected function setUp(): void {
        parent::setUp();
        $this->deleted_post_meta_keys = [];
        $this->stub_shared_functions();
    }

    /**
     * Register stubs that every test in this class needs.
     * Tests that need different get_option() behaviour override it after
     * calling parent::setUp().
     */
    private function stub_shared_functions(): void {
        Functions\when( 'wp_clear_scheduled_hook' )->justReturn( 0 );
        Functions\when( 'delete_option' )->justReturn( true );
        Functions\when( 'delete_transient' )->justReturn( true );

        // Capture every delete_post_meta_by_key() call so tests can inspect
        // which keys were actually cleaned up.
        Functions\when( 'delete_post_meta_by_key' )->alias(
            function ( string $key ): void {
                $this->deleted_post_meta_keys[] = $key;
            }
        );
    }

// Purge enabled

    /**
     * All five Respond.io post meta keys must be removed when purge is active.
     * The full list is what the settings UI describes to the admin — missing a
     * key means orphaned rows stay behind after the plugin is deleted.
     */
    public function test_purge_removes_all_respond_io_post_meta_keys(): void {
        $this->stub_get_option_with_purge();
        $this->run_uninstall();

        $expected = [
            '_respond_io_id',
            '_respond_io_merged_ids',
            '_respond_io_last_sync',
            '_respond_io_notes_comment_id',
            '_respond_io_message_log_comment_id',
        ];

        foreach ( $expected as $key ) {
            $this->assertContains(
                $key,
                $this->deleted_post_meta_keys,
                "delete_post_meta_by_key( '{$key}' ) must be called when purge_on_uninstall is set."
            );
        }
    }

    /**
     * All four Metricool post meta keys must be removed when purge is active.
     */
    public function test_purge_removes_all_metricool_post_meta_keys(): void {
        $this->stub_get_option_with_purge();
        $this->run_uninstall();

        $expected = [
            '_metricool_id',
            '_metricool_merged_ids',
            '_metricool_last_sync',
            '_metricool_notes_comment_id',
            '_metricool_message_log_comment_id',
        ];

        foreach ( $expected as $key ) {
            $this->assertContains(
                $key,
                $this->deleted_post_meta_keys,
                "delete_post_meta_by_key( '{$key}' ) must be called when purge_on_uninstall is set."
            );
        }
    }

// Purge disabled

    /**
     * When purge_on_uninstall is not set, no contact metadata should be touched.
     * Options, transients, and the log tables are still cleaned up, but post
     * meta rows belong to the site owner and must be left intact.
     */
    public function test_no_purge_leaves_post_meta_untouched(): void {
        Functions\when( 'get_option' )->alias(
            static function ( string $key, $default = [] ) {
                if ( 'dt_crm_sync_saved_filters' === $key ) {
                    return [];
                }
                if ( 'dt_crm_sync_settings' === $key ) {
                    return []; // purge_on_uninstall absent
                }
                return $default;
            }
        );

        $this->run_uninstall();

        $this->assertEmpty(
            $this->deleted_post_meta_keys,
            'delete_post_meta_by_key() must not be called when purge_on_uninstall is not set.'
        );
    }

// Helpers

    /**
     * Stub get_option() to return a settings array with purge_on_uninstall active.
     */
    private function stub_get_option_with_purge(): void {
        Functions\when( 'get_option' )->alias(
            static function ( string $key, $default = [] ) {
                if ( 'dt_crm_sync_saved_filters' === $key ) {
                    return [];
                }
                if ( 'dt_crm_sync_settings' === $key ) {
                    return [ 'purge_on_uninstall' => true ];
                }
                return $default;
            }
        );
    }

    /**
     * Execute the uninstall routine.
     *
     * uninstall.php is require_once'd once per process, so it only runs on the
     * first call. Subsequent calls are no-ops (PHP caches the include). Each
     * test class instance gets a fresh Brain Monkey environment but shares the
     * same PHP process, so we use require (not require_once) to force execution
     * on every call while the function stubs are live.
     *
     * The global $wpdb stub already handles query() and prepare() calls made
     * inside the file, so no additional wiring is needed.
     */
    private function run_uninstall(): void {
        // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable -- test-only require
        require dirname( __DIR__, 2 ) . '/uninstall.php';
    }
}
