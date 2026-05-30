<?php
/**
 * Abstract base class for all Brain Monkey unit tests.
 *
 * Sets up and tears down Brain Monkey (which activates the Patchwork function
 * redefiner) around every test method, and registers the Mockery PHPUnit
 * integration trait so Mockery expectations are automatically verified after
 * each test and mocks are cleanly reset.
 */

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

abstract class BrainMonkeyTestCase extends \PHPUnit\Framework\TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        // Reset $wpdb stub state and the decrypt override between every test.
        global $wpdb;
        $wpdb->insert_calls             = [];
        $wpdb->next_insert_result       = 1;
        $wpdb->next_get_var_result      = null;
        $wpdb->next_get_results_result  = [];
        $wpdb->last_query_sql           = null;
        $wpdb->last_get_var_sql         = null;
        $wpdb->next_query_result        = 0;
        DT_Posts::reset();
        Disciple_Tools_CRM_Sync::$test_decrypt_fn = null;
    }

    /**
     * Extension point for subclasses that need extra per-test cleanup.
     *
     * Override this instead of tearDown() so that Monkey\tearDown() is
     * guaranteed to run regardless of subclass implementation.
     */
    protected function tearDownTest(): void {}

    final protected function tearDown(): void {
        $this->tearDownTest();
        Monkey\tearDown();
        parent::tearDown();
    }
}
