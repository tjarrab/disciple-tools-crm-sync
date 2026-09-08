<?php
/**
 * Unit tests for Disciple_Tools_CRM_Sync_Email_Digest.
 *
 * Strategy:
 *  - get_option() is mocked per test for the notifier settings and the
 *    last-sent marker
 *  - The $wpdb stub's next_get_results_result controls which log rows the
 *    digest "finds"; insert_calls captures the digest's own audit log row
 *  - wp_mail() is mocked so the exact recipients/body can be inspected
 */

use Brain\Monkey\Functions;

class EmailDigestTest extends BrainMonkeyTestCase {

    protected function setUp(): void {
        parent::setUp();
        Functions\when( 'is_email' )->alias( fn( $email ) => (bool) filter_var( $email, FILTER_VALIDATE_EMAIL ) );
        Functions\when( 'update_option' )->justReturn( true );
        Functions\when( 'get_permalink' )->alias( fn( $id ) => 'https://example.org/?p=' . $id );
    }

    private function mock_settings( array $settings, int $last_sent = 0 ): void {
        Functions\when( 'get_option' )->alias(
            function ( string $key, $default = false ) use ( $settings, $last_sent ) {
                if ( 'dt_crm_sync_email_notifier_settings' === $key ) {
                    return $settings;
                }
                if ( 'dt_crm_sync_email_digest_last_sent' === $key ) {
                    return $last_sent;
                }
                return $default;
            }
        );
    }

// Gating

    public function test_send_skips_when_disabled(): void {
        $this->mock_settings( [ 'enabled' => false, 'recipients' => [ 'admin@example.org' ], 'send_time' => '06:00' ] );
        Functions\expect( 'wp_mail' )->never();

        Disciple_Tools_CRM_Sync_Email_Digest::send();
    }

    public function test_send_skips_when_no_recipients(): void {
        $this->mock_settings( [ 'enabled' => true, 'recipients' => [], 'send_time' => '06:00' ] );
        Functions\expect( 'wp_mail' )->never();

        Disciple_Tools_CRM_Sync_Email_Digest::send();
    }

// Body content

    public function test_send_reports_no_contacts_when_nothing_was_imported(): void {
        global $wpdb;
        $wpdb->next_get_results_result = [];

        $this->mock_settings( [ 'enabled' => true, 'recipients' => [ 'admin@example.org' ], 'send_time' => '06:00' ] );

        $captured_body = null;
        Functions\expect( 'wp_mail' )
            ->once()
            ->andReturnUsing( function ( $to, $subject, $body ) use ( &$captured_body ) {
                $captured_body = $body;
                return true;
            } );

        Disciple_Tools_CRM_Sync_Email_Digest::send();

        $this->assertStringContainsString( 'No contacts imported today.', $captured_body );
        $this->assertSame( 'success', $wpdb->insert_calls[0]['data']['status'] );
        $this->assertSame( 'email_digest', $wpdb->insert_calls[0]['data']['contact_id'] );
    }

    public function test_send_lists_each_imported_contact_with_a_link(): void {
        global $wpdb;
        $wpdb->next_get_results_result = [
            (object) [ 'dt_post_id' => 101, 'status' => 'success', 'created_at' => '2026-09-08 01:00:00' ],
            (object) [ 'dt_post_id' => 102, 'status' => 'merged', 'created_at' => '2026-09-08 02:00:00' ],
        ];

        $this->mock_settings( [ 'enabled' => true, 'recipients' => [ 'admin@example.org' ], 'send_time' => '06:00' ] );

        $captured_body = null;
        Functions\expect( 'wp_mail' )
            ->once()
            ->andReturnUsing( function ( $to, $subject, $body ) use ( &$captured_body ) {
                $captured_body = $body;
                return true;
            } );

        Disciple_Tools_CRM_Sync_Email_Digest::send();

        $this->assertStringContainsString( 'https://example.org/?p=101', $captured_body );
        $this->assertStringContainsString( 'https://example.org/?p=102', $captured_body );
        $this->assertStringNotContainsString( 'No contacts imported today.', $captured_body );
    }

    public function test_send_dedupes_repeated_contact_keeping_latest_row(): void {
        global $wpdb;
        $wpdb->next_get_results_result = [
            (object) [ 'dt_post_id' => 101, 'status' => 'success', 'created_at' => '2026-09-08 01:00:00' ],
            (object) [ 'dt_post_id' => 101, 'status' => 'merged', 'created_at' => '2026-09-08 03:00:00' ],
        ];

        $this->mock_settings( [ 'enabled' => true, 'recipients' => [ 'admin@example.org' ], 'send_time' => '06:00' ] );

        $captured_body = null;
        Functions\expect( 'wp_mail' )
            ->once()
            ->andReturnUsing( function ( $to, $subject, $body ) use ( &$captured_body ) {
                $captured_body = $body;
                return true;
            } );

        Disciple_Tools_CRM_Sync_Email_Digest::send();

        $this->assertSame( 1, substr_count( $captured_body, '<li>' ), 'A contact touched twice in the window must be reported once.' );
        $this->assertStringContainsString( 'reporting 1 contact', $wpdb->insert_calls[0]['data']['message'] );
    }

// Failure handling

    public function test_send_advances_last_sent_and_logs_failure_when_mail_fails(): void {
        global $wpdb;
        $wpdb->next_get_results_result = [];

        $this->mock_settings( [ 'enabled' => true, 'recipients' => [ 'admin@example.org' ], 'send_time' => '06:00' ] );
        Functions\expect( 'wp_mail' )->once()->andReturn( false );

        $last_sent_calls = [];
        Functions\when( 'update_option' )->alias(
            function ( $key, $value ) use ( &$last_sent_calls ) {
                if ( 'dt_crm_sync_email_digest_last_sent' === $key ) {
                    $last_sent_calls[] = $value;
                }
                return true;
            }
        );

        Disciple_Tools_CRM_Sync_Email_Digest::send();

        $this->assertNotEmpty( $last_sent_calls, 'The last-sent marker must advance even when wp_mail() fails.' );
        $this->assertSame( 'failed', $wpdb->insert_calls[0]['data']['status'] );
    }

// Concurrency lock

    public function test_send_skips_when_already_locked(): void {
        $this->mock_settings( [ 'enabled' => true, 'recipients' => [ 'admin@example.org' ], 'send_time' => '06:00' ] );
        Functions\when( 'get_transient' )->justReturn( time() );
        Functions\expect( 'wp_mail' )->never();

        Disciple_Tools_CRM_Sync_Email_Digest::send();
    }

    public function test_send_releases_lock_after_a_normal_run(): void {
        global $wpdb;
        $wpdb->next_get_results_result = [];

        $this->mock_settings( [ 'enabled' => true, 'recipients' => [ 'admin@example.org' ], 'send_time' => '06:00' ] );
        Functions\when( 'get_transient' )->justReturn( false );
        Functions\when( 'wp_mail' )->justReturn( true );

        $deleted_transients = [];
        Functions\when( 'delete_transient' )->alias(
            function ( $key ) use ( &$deleted_transients ) {
                $deleted_transients[] = $key;
                return true;
            }
        );

        Disciple_Tools_CRM_Sync_Email_Digest::send();

        $this->assertContains( 'dt_crm_sync_email_digest_lock', $deleted_transients, 'The lock must be released once the run completes.' );
    }

// Contact list cap

    public function test_send_caps_contact_list_and_notes_remaining(): void {
        global $wpdb;
        $rows = [];
        for ( $i = 1; $i <= 205; $i++ ) {
            $rows[] = (object) [ 'dt_post_id' => $i, 'status' => 'success', 'created_at' => '2026-09-08 01:00:00' ];
        }
        $wpdb->next_get_results_result = $rows;

        $this->mock_settings( [ 'enabled' => true, 'recipients' => [ 'admin@example.org' ], 'send_time' => '06:00' ] );

        $captured_body = null;
        Functions\expect( 'wp_mail' )
            ->once()
            ->andReturnUsing( function ( $to, $subject, $body ) use ( &$captured_body ) {
                $captured_body = $body;
                return true;
            } );

        Disciple_Tools_CRM_Sync_Email_Digest::send();

        $this->assertSame( 200, substr_count( $captured_body, '<li>' ), 'The rendered list must be capped at 200 contacts.' );
        $this->assertStringContainsString( '+ 5 more', $captured_body );
        $this->assertStringContainsString( 'reporting 205 contact', $wpdb->insert_calls[0]['data']['message'], 'The logged total must be the true, uncapped count.' );
    }
}
