<?php

/**
 * Base test case for integration tests.
 *
 * Extends WP_UnitTestCase directly, relying on WP Test Library's own
 * per-test database isolation rather than manual SQL transactions.
 * Manual START TRANSACTION / ROLLBACK were removed because WP_UnitTestCase
 * already handles cleanup, and raw transactions conflict with MyISAM tables
 * used in the WP test scaffolding (which ignore transactions silently).
 */
abstract class TestCase extends WP_UnitTestCase {}
