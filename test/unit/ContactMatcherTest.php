<?php
/**
 * Unit tests for Disciple_Tools_CRM_Sync_Contact_Matcher.
 *
 * Covers both duplicate-detection strategies:
 *   - Fast path: indexed meta lookup via find_by_connector_id()
 *   - Slow path: serialized LIKE query via find_by_phone_or_email()
 */

use Brain\Monkey\Functions;

class ContactMatcherTest extends BrainMonkeyTestCase {

    private Disciple_Tools_CRM_Sync_Contact_Matcher $matcher;

    protected function setUp(): void {
        parent::setUp();
        Functions\when( 'sanitize_text_field' )->returnArg();
        $this->matcher = new Disciple_Tools_CRM_Sync_Contact_Matcher( '_respond_io_' );
    }

// find_by_connector_id

    public function test_find_by_connector_id_success(): void {
        Functions\when( 'get_posts' )->justReturn( [ 7 ] );

        $result = $this->matcher->find_by_connector_id( '123' );

        $this->assertSame( 7, $result );
    }

    public function test_find_by_connector_id_not_found(): void {
        Functions\when( 'get_posts' )->justReturn( [] );

        $result = $this->matcher->find_by_connector_id( '999' );

        $this->assertFalse( $result );
    }

// find_by_phone_or_email

    public function test_find_by_phone_success(): void {
        global $wpdb;
        $wpdb->next_get_var_result = 42;

        $result = $this->matcher->find_by_phone_or_email( '+15555550100', '' );

        $this->assertSame( 42, $result );
    }

    public function test_find_by_email_success(): void {
        global $wpdb;
        $wpdb->next_get_var_result = 55;

        $result = $this->matcher->find_by_phone_or_email( '', 'jane@example.com' );

        $this->assertSame( 55, $result );
    }

    public function test_find_by_phone_skips_phone_query_and_falls_through_to_email(): void {
        global $wpdb;
        $wpdb->next_get_var_result = 99;

        $result = $this->matcher->find_by_phone_or_email( '', 'fallback@example.com' );

        $this->assertSame( 99, $result );
        // The only query issued must be for contact_email — the phone query must be
        // skipped entirely when the phone argument is empty.
        $this->assertStringContainsString(
            'contact_email',
            (string) $wpdb->last_get_var_sql,
            'Email LIKE query must be issued when phone is empty.'
        );
        $this->assertStringNotContainsString(
            'contact_phone',
            (string) $wpdb->last_get_var_sql,
            'Phone LIKE query must not be issued when phone is empty.'
        );
    }

    public function test_find_by_phone_or_email_empty(): void {
        $result = $this->matcher->find_by_phone_or_email( '', '' );

        $this->assertFalse( $result );
    }

// SQL wildcard escaping

    public function test_find_by_phone_escapes_sql_wildcard_characters_via_esc_like(): void {
        global $wpdb;
        $phone                    = '50%_test';
        $wpdb->next_get_var_result = 77;

        $result = $this->matcher->find_by_phone_or_email( $phone, '' );

        $this->assertSame( 77, $result );

        $expected_in_sql = addslashes( $wpdb->esc_like( $phone ) );
        $this->assertStringContainsString(
            $expected_in_sql,
            $wpdb->last_get_var_sql,
            'SQL wildcard characters in the phone number must be escaped by esc_like() before the LIKE query.'
        );
    }
}
