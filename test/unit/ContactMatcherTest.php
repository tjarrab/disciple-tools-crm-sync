<?php
/**
 * Unit tests for Disciple_Tools_CRM_Sync_Contact_Matcher.
 *
 * Covers both duplicate-detection strategies:
 *   - Fast path: indexed meta lookup via find_by_connector_id()
 *   - Slow path: LIKE query against DT's per-channel phone/email postmeta via
 *     find_by_phone_or_email() (each value lives under a randomly-suffixed key
 *     like 'contact_phone_a1b', never the bare 'contact_phone' key)
 */

use Brain\Monkey\Functions;

class ContactMatcherTest extends BrainMonkeyTestCase {

    private Disciple_Tools_CRM_Sync_Contact_Matcher $matcher;

    protected function setUp(): void {
        parent::setUp();
        Functions\when( 'sanitize_text_field' )->returnArg();
        // The matcher reads the default phone region from settings; default to
        // "no region configured" unless a test opts into one.
        Functions\when( 'get_option' )->justReturn( [] );
        $this->matcher = new Disciple_Tools_CRM_Sync_Contact_Matcher( '_respond_io_' );
    }

    /**
     * Point the matcher at a default phone region for the duration of a test.
     */
    private function set_phone_region( string $cc, int $len, string $trunk = '' ): void {
        Functions\when( 'get_option' )->alias( function ( $key, $default = false ) use ( $cc, $len, $trunk ) {
            if ( 'dt_crm_sync_settings' === $key ) {
                return [
                    'default_country_code'   => $cc,
                    'national_number_length' => $len,
                    'national_trunk_prefix'  => $trunk,
                ];
            }
            return $default;
        } );
    }

    /**
     * Mirrors how the $wpdb test stub's prepare() renders a %s value that was
     * already run through esc_like() — i.e. what 'contact_phone' looks like once
     * it's embedded in a LIKE-pattern meta_key argument.
     */
    private function escaped_key_prefix( string $key ): string {
        global $wpdb;
        return addslashes( $wpdb->esc_like( $key ) );
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
            $this->escaped_key_prefix( 'contact_email' ),
            (string) $wpdb->last_get_var_sql,
            'Email LIKE query must be issued when phone is empty.'
        );
        $this->assertStringNotContainsString(
            $this->escaped_key_prefix( 'contact_phone' ),
            (string) $wpdb->last_get_var_sql,
            'Phone LIKE query must not be issued when phone is empty.'
        );
    }

    public function test_find_by_phone_or_email_empty(): void {
        $result = $this->matcher->find_by_phone_or_email( '', '' );

        $this->assertNull( $result );
    }

// Real DT postmeta key format (regression coverage for the duplicate-matching fix)

    /**
     * DT never writes a bare 'contact_phone' row — every channel value gets its own
     * postmeta row under a key with a random suffix, e.g. 'contact_phone_a1b'
     * (see DT_Posts::create_channel_metakey()). A query that only matches the exact
     * bare key would never find any real contact, which was the actual bug.
     */
    public function test_find_by_phone_matches_a_randomly_suffixed_meta_key(): void {
        global $wpdb;
        $wpdb->next_get_var_result = 88;

        $result = $this->matcher->find_by_phone_or_email( '5555550100', '' );

        $this->assertSame( 88, $result );
        $this->assertStringContainsString(
            "meta_key LIKE '" . $this->escaped_key_prefix( 'contact_phone' ) . "%'",
            (string) $wpdb->last_get_var_sql,
            'The query must match keys by prefix, not by exact equality, to find real DT contact_phone_xxx rows.'
        );
        $this->assertStringContainsString(
            "meta_key NOT LIKE '%_details'",
            (string) $wpdb->last_get_var_sql,
            "The query must exclude a channel's sibling _details row."
        );
    }

    public function test_find_by_email_matches_a_randomly_suffixed_meta_key(): void {
        global $wpdb;
        $wpdb->next_get_var_result = 89;

        $result = $this->matcher->find_by_phone_or_email( '', 'jane@example.com' );

        $this->assertSame( 89, $result );
        $this->assertStringContainsString(
            "meta_key LIKE '" . $this->escaped_key_prefix( 'contact_email' ) . "%'",
            (string) $wpdb->last_get_var_sql
        );
        $this->assertStringContainsString(
            "meta_key NOT LIKE '%_details'",
            (string) $wpdb->last_get_var_sql
        );
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
        $this->set_phone_region( '1', 10 );
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
     * The stored value is a plain string, as DT actually writes it — not wrapped
     * in a serialized array.
     */
    public function test_find_by_phone_matches_differently_formatted_stored_value(): void {
        global $wpdb;
        $this->set_phone_region( '1', 10 );
        $wpdb->next_get_var_result     = null; // exact substring check misses
        $wpdb->next_get_results_result = [
            (object) [
                'post_id'    => 55,
                'meta_value' => '5555550100',
            ],
        ];

        $result = $this->matcher->find_by_phone_or_email( '+1 (555) 555-0100', '' );

        $this->assertSame( 55, $result, 'A country-code-prefixed number must match its unprefixed stored counterpart.' );
        $this->assertStringContainsString(
            "meta_key LIKE '" . $this->escaped_key_prefix( 'contact_phone' ) . "%'",
            (string) $wpdb->last_get_results_sql,
            'The normalized-phone fallback must also match keys by prefix, not exact equality.'
        );
        $this->assertStringContainsString(
            "meta_key NOT LIKE '%_details'",
            (string) $wpdb->last_get_results_sql
        );
    }

    public function test_find_by_phone_normalized_fallback_returns_null_when_no_candidate_agrees(): void {
        global $wpdb;
        $wpdb->next_get_var_result     = null;
        $wpdb->next_get_results_result = [
            (object) [
                'post_id'    => 55,
                'meta_value' => '5559990000',
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

// Country-code-aware canonical matching

    /**
     * The whole point of the fix: DT has an 8-digit local number saved with no country
     * code, Respond.io sends the same person with its +216 country code and its own
     * punctuation, and they must resolve to the one contact.
     */
    public function test_find_by_phone_matches_bare_local_number_against_country_code_form(): void {
        global $wpdb;
        $this->set_phone_region( '216', 8 );
        $wpdb->next_get_var_result     = null; // exact substring check misses
        $wpdb->next_get_results_result = [
            (object) [ 'post_id' => 60, 'meta_value' => '22222222' ],
        ];

        $result = $this->matcher->find_by_phone_or_email( '+216-222-222-22', '' );

        $this->assertSame( 60, $result );
    }

    /**
     * Countries that use a trunk prefix (a leading 0 for domestic dialling that the
     * international form drops) still have to line up: 06 12 34 56 78 is +33 6 12 34...
     */
    public function test_find_by_phone_handles_a_trunk_prefixed_local_number(): void {
        global $wpdb;
        $this->set_phone_region( '33', 9, '0' );
        $wpdb->next_get_var_result     = null;
        $wpdb->next_get_results_result = [
            (object) [ 'post_id' => 61, 'meta_value' => '0612345678' ],
        ];

        $result = $this->matcher->find_by_phone_or_email( '+33 6 12 34 56 78', '' );

        $this->assertSame( 61, $result, 'A national number written with a leading trunk 0 must match its +CC international form.' );
    }

    /**
     * Regression guard for the original bug: a plain trailing-suffix comparison treated
     * two different local numbers that happened to share their last digits as the same
     * contact. The full local number is compared now, so these stay distinct.
     */
    public function test_find_by_phone_does_not_match_two_different_local_numbers(): void {
        global $wpdb;
        $this->set_phone_region( '216', 8 );
        $wpdb->next_get_var_result     = null;
        $wpdb->next_get_results_result = [
            (object) [ 'post_id' => 62, 'meta_value' => '50222222' ],
        ];

        $result = $this->matcher->find_by_phone_or_email( '20222222', '' );

        $this->assertNull( $result );
    }

    /**
     * The default-region assumption only applies to local-shaped numbers. A full
     * foreign number must never be dragged onto a local contact.
     */
    public function test_find_by_phone_does_not_match_a_foreign_number(): void {
        global $wpdb;
        $this->set_phone_region( '216', 8 );
        $wpdb->next_get_var_result     = null;
        $wpdb->next_get_results_result = [
            (object) [ 'post_id' => 63, 'meta_value' => '22222222' ],
        ];

        $result = $this->matcher->find_by_phone_or_email( '+33612345678', '' );

        $this->assertNull( $result );
    }

// SQL wildcard safety

    /**
     * The incoming phone is compared with exact equality (meta_value = %s), not
     * embedded in a LIKE pattern, so a value containing SQL wildcard characters
     * ('%', '_') can't accidentally broaden the match to other contacts' numbers.
     */
    public function test_find_by_phone_compares_meta_value_by_exact_equality(): void {
        global $wpdb;
        $phone                      = '50%_test';
        $wpdb->next_get_var_result  = 77;

        $result = $this->matcher->find_by_phone_or_email( $phone, '' );

        $this->assertSame( 77, $result );
        $this->assertStringContainsString(
            "meta_value = '50%_test'",
            (string) $wpdb->last_get_var_sql
        );
    }
}
