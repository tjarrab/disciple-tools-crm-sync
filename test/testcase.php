<?php

/**
 * Base test case for integration tests.
 *
 * Extends WP_UnitTestCase directly, relying on WP Test Library's own
 * per-test database isolation rather than manual SQL transactions.
 */
abstract class TestCase extends WP_UnitTestCase {}
