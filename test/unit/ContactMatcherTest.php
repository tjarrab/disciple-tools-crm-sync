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

        $this->assertNull( $result );
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

        $this->assertNull( $result );
    }

// Normalized phone fallback (formatting differences)

    /**
     * import-processor.php's phone lock reuses this helper so the lock key and
     * the matcher's own comparison always agree on what "the same number" means.
     */
    public function test_normalize_phone_digits_strips_non_digit_characters(): void {
        $this->assertSame(
            '15555550100',
            Disciple_Tools_CRM_Sync_Contact_Matcher::normalize_phone_digits( '+1 (555) 555-0100' )
        );
    }

    /**
     * The lock key must be as broad as find_by_normalized_phone()'s own equivalence
     * class, or two racing contacts whose numbers differ only by a country code
     * would contend for different locks and could still both create a duplicate.
     */
    public function test_phone_lock_key_is_the_same_for_a_number_with_or_without_country_code(): void {
        $this->assertSame(
            Disciple_Tools_CRM_Sync_Contact_Matcher::phone_lock_key( '5555550100' ),
            Disciple_Tools_CRM_Sync_Contact_Matcher::phone_lock_key( '+1 (555) 555-0100' )
        );
    }

    public function test_phone_lock_key_returns_empty_string_for_numbers_too_short_to_compare_safely(): void {
        $this->assertSame( '', Disciple_Tools_CRM_Sync_Contact_Matcher::phone_lock_key( '12345' ) );
    }

    /**
     * A phone stored without formatting (as Respond.io would have sent it on an
     * earlier sync) must still match a differently-formatted incoming value —
     * this is the fallback that fires when the exact-substring check misses.
     */
    public function test_find_by_phone_matches_differently_formatted_stored_value(): void {
        global $wpdb;
        $wpdb->next_get_var_result     = null; // exact substring check misses
        $wpdb->next_get_results_result = [
            (object) [
                'post_id'    => 55,
                'meta_value' => serialize( [ 'values' => [ [ 'value' => '5555550100', 'key' => 'phone_1' ] ] ] ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
            ],
        ];

        $result = $this->matcher->find_by_phone_or_email( '+1 (555) 555-0100', '' );

        $this->assertSame( 55, $result, 'A country-code-prefixed number must match its unprefixed stored counterpart.' );
    }

    public function test_find_by_phone_normalized_fallback_returns_null_when_no_candidate_agrees(): void {
        global $wpdb;
        $wpdb->next_get_var_result     = null;
        $wpdb->next_get_results_result = [
            (object) [
                'post_id'    => 55,
                'meta_value' => serialize( [ 'values' => [ [ 'value' => '5559990000' ] ] ] ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
            ],
        ];

        $result = $this->matcher->find_by_phone_or_email( '+1 (555) 555-0100', '' );

        $this->assertNull( $result );
    }

    public function test_find_by_phone_normalized_fallback_skips_numbers_too_short_to_compare_safely(): void {
        global $wpdb;
        $wpdb->next_get_var_result = null;

        $result = $this->matcher->find_by_phone_or_email( '12345', '' );

        $this->assertNull( $result, 'Numbers under 7 digits must not risk a false-positive normalized match.' );
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
