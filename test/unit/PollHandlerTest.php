<?php
/**
 * Unit tests for Disciple_Tools_CRM_Sync_Poll_Handler.
 *
 * Tests batch chunking, staggered event scheduling, legacy filter_body key
 * support, and rate-limit rescheduling — all without a real WP environment.
 *
 * Strategy:
 *  - get_option() is mocked to return a stored filter envelope
 *  - apply_filters() is mocked so the registry can resolve the Respond_IO connector
 *  - wp_safe_remote_request() is mocked to return a controlled contact list
 *  - wp_schedule_single_event() is mocked and its call count / arguments asserted
 *  - The $wpdb stub in bootstrap absorbs any Logger::write() DB calls
 */

use Brain\Monkey\Functions;

class PollHandlerTest extends BrainMonkeyTestCase {

    private const FILTER_ID = 'test_filter_abc';

    protected function setUp(): void {
        parent::setUp();
        // Common pass-throughs for all tests.
        Functions\when( 'sanitize_key' )->returnArg();
    }

// Helpers

    /**
     * Builds a minimal get_option() mock that serves both the filter envelope
     * (dt_crm_sync_saved_filter_*) and the settings option (dt_crm_sync_settings).
     */
    private function mock_get_option_for_filter( array $filter_params ): void {
        $envelope = json_encode( [ 'filter_params' => $filter_params ] );

        Functions\when( 'get_option' )->alias(
            function ( string $option ) use ( $envelope ) {
                if ( 'dt_crm_sync_saved_filter_' . self::FILTER_ID === $option ) {
                    return $envelope;
                }
                if ( 'dt_crm_sync_settings' === $option ) {
                    return [
                        'active_connector' => 'respond_io',
                        'connectors'       => [
                            'respond_io' => [
                                'api_url'   => 'https://api.test',
                                'api_token' => 'token',
                            ],
                        ],
                    ];
                }
                return false;
            }
        );
    }

    /**
     * Stubs the connector registry filter so it returns the real Respond_IO class,
     * and mocks the HTTP layer to return $contact_count contacts and no next cursor.
     */
    private function mock_connector_returning_contacts( int $contact_count ): void {
        Functions\when( 'apply_filters' )->alias(
            function ( string $hook, $value ) {
                if ( 'dt_crm_sync_connectors' === $hook ) {
                    return [ 'respond_io' => 'Disciple_Tools_CRM_Sync_Connector_Respond_IO' ];
                }
                return $value;
            }
        );

        $items = array_map( fn( $i ) => [ 'id' => $i + 1 ], range( 0, $contact_count - 1 ) );
        $body  = json_encode( [ 'items' => $items, 'pagination' => [] ] );

        Functions\when( 'wp_timezone_string' )->justReturn( 'UTC' );
        Functions\when( 'add_query_arg' )->justReturn( 'https://api.test/mocked' );
        Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
        Functions\when( 'wp_safe_remote_request' )->justReturn( [ '_mocked' => true ] );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( $body );
    }

// Batch chunking

    public function test_60_contacts_are_split_into_3_batches_of_25_25_10(): void {
        $this->mock_get_option_for_filter( [ 'search' => '' ] );
        $this->mock_connector_returning_contacts( 60 );

        $schedule_calls = [];
        Functions\when( 'wp_schedule_single_event' )->alias(
            function ( int $timestamp, string $hook, array $args ) use ( &$schedule_calls ) {
                if ( 'dt_crm_sync_process_batch' === $hook ) {
                    $schedule_calls[] = count( $args[0]['ids'] );
                }
                return true;
            }
        );

        ( new Disciple_Tools_CRM_Sync_Poll_Handler() )->run_poll( self::FILTER_ID );

        $this->assertCount( 3, $schedule_calls, 'Expected 3 batch events for 60 contacts.' );
        $this->assertSame( [ 25, 25, 10 ], $schedule_calls );
    }

    public function test_single_contact_schedules_one_batch(): void {
        $this->mock_get_option_for_filter( [ 'search' => '' ] );
        $this->mock_connector_returning_contacts( 1 );

        $batch_count = 0;
        Functions\when( 'wp_schedule_single_event' )->alias(
            function ( int $t, string $hook ) use ( &$batch_count ) {
                if ( 'dt_crm_sync_process_batch' === $hook ) {
                    ++$batch_count;
                }
                return true;
            }
        );

        ( new Disciple_Tools_CRM_Sync_Poll_Handler() )->run_poll( self::FILTER_ID );

        $this->assertSame( 1, $batch_count );
    }

// Staggered scheduling

    public function test_batch_event_timestamps_are_staggered_by_3_seconds(): void {
        $this->mock_get_option_for_filter( [ 'search' => '' ] );
        $this->mock_connector_returning_contacts( 50 ); // 2 batches: 25 + 25

        $timestamps = [];
        Functions\when( 'wp_schedule_single_event' )->alias(
            function ( int $timestamp, string $hook ) use ( &$timestamps ) {
                if ( 'dt_crm_sync_process_batch' === $hook ) {
                    $timestamps[] = $timestamp;
                }
                return true;
            }
        );

        ( new Disciple_Tools_CRM_Sync_Poll_Handler() )->run_poll( self::FILTER_ID );

        $this->assertCount( 2, $timestamps );
        // The second batch must be scheduled exactly 3 seconds after the first.
        $this->assertSame( 3, $timestamps[1] - $timestamps[0] );
    }

// Legacy filter_body key

    public function test_run_poll_accepts_legacy_filter_body_key_in_envelope(): void {
        // Store envelope using the old 'filter_body' key instead of 'filter_params'.
        $legacy_envelope = json_encode( [
            'filter_body' => [ 'search' => 'legacy', 'tag' => '' ],
        ] );

        Functions\when( 'get_option' )->alias(
            function ( string $option ) use ( $legacy_envelope ) {
                if ( 'dt_crm_sync_saved_filter_' . self::FILTER_ID === $option ) {
                    return $legacy_envelope;
                }
                if ( 'dt_crm_sync_settings' === $option ) {
                    return [
                        'active_connector' => 'respond_io',
                        'connectors'       => [
                            'respond_io' => [ 'api_url' => 'https://api.test', 'api_token' => 'tok' ],
                        ],
                    ];
                }
                return false;
            }
        );

        Functions\when( 'apply_filters' )->alias(
            fn( $hook, $value ) => 'dt_crm_sync_connectors' === $hook
                ? [ 'respond_io' => 'Disciple_Tools_CRM_Sync_Connector_Respond_IO' ]
                : $value
        );

        $this->mock_connector_returning_contacts( 2 );

        $scheduled = false;
        Functions\when( 'wp_schedule_single_event' )->alias(
            function ( $t, $hook ) use ( &$scheduled ) {
                if ( 'dt_crm_sync_process_batch' === $hook ) {
                    $scheduled = true;
                }
                return true;
            }
        );

        ( new Disciple_Tools_CRM_Sync_Poll_Handler() )->run_poll( self::FILTER_ID );

        $this->assertTrue( $scheduled, 'Legacy filter_body envelope should still schedule a batch.' );
    }

// Rate-limit rescheduling

    public function test_rate_limit_during_pagination_reschedules_entire_poll(): void {
        $this->mock_get_option_for_filter( [ 'search' => '' ] );

        // Registry resolves the connector.
        Functions\when( 'apply_filters' )->alias(
            fn( $hook, $value ) => 'dt_crm_sync_connectors' === $hook
                ? [ 'respond_io' => 'Disciple_Tools_CRM_Sync_Connector_Respond_IO' ]
                : $value
        );

        // Connector returns a rate-limited 429 for the first (and only) page.
        Functions\when( 'wp_timezone_string' )->justReturn( 'UTC' );
        Functions\when( 'add_query_arg' )->justReturn( 'https://api.test/mocked' );
        Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
        Functions\when( 'wp_safe_remote_request' )->justReturn( [ '_mocked' => true ] );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 429 );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{}' );
        Functions\when( 'wp_remote_retrieve_header' )->justReturn( '45' ); // retry-after = 45s

        $poll_rescheduled = false;
        $batch_scheduled  = false;
        Functions\when( 'wp_schedule_single_event' )->alias(
            function ( int $timestamp, string $hook ) use ( &$poll_rescheduled, &$batch_scheduled ) {
                if ( 'dt_crm_sync_poll' === $hook ) {
                    $poll_rescheduled = true;
                }
                if ( 'dt_crm_sync_process_batch' === $hook ) {
                    $batch_scheduled = true;
                }
                return true;
            }
        );

        ( new Disciple_Tools_CRM_Sync_Poll_Handler() )->run_poll( self::FILTER_ID );

        $this->assertTrue( $poll_rescheduled, 'Poll should be rescheduled after a 429.' );
        $this->assertFalse( $batch_scheduled, 'No batch should be scheduled when pagination hit a 429.' );
    }

// Logger status assertions

    public function test_run_poll_success_logs(): void {
        $this->mock_get_option_for_filter( [ 'search' => '' ] );
        $this->mock_connector_returning_contacts( 1 );
        Functions\when( 'wp_schedule_single_event' )->justReturn( true );

        ( new Disciple_Tools_CRM_Sync_Poll_Handler() )->run_poll( self::FILTER_ID );

        global $wpdb;
        $statuses = array_column( array_column( $wpdb->insert_calls, 'data' ), 'status' );
        $this->assertContains(
            'success',
            $statuses,
            'Logger must write a "success" entry after contacts are found and batches are scheduled.'
        );
    }

    public function test_run_poll_skipped_logs(): void {
        $this->mock_get_option_for_filter( [ 'search' => '' ] );

        // Build the connector/HTTP mock manually to return a truly empty items array.
        Functions\when( 'apply_filters' )->alias(
            fn( $hook, $value ) => 'dt_crm_sync_connectors' === $hook
                ? [ 'respond_io' => 'Disciple_Tools_CRM_Sync_Connector_Respond_IO' ]
                : $value
        );
        $body = json_encode( [ 'items' => [], 'pagination' => [] ] );
        Functions\when( 'wp_timezone_string' )->justReturn( 'UTC' );
        Functions\when( 'add_query_arg' )->justReturn( 'https://api.test/mocked' );
        Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
        Functions\when( 'wp_safe_remote_request' )->justReturn( [ '_mocked' => true ] );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( $body );

        $batch_scheduled = false;
        Functions\when( 'wp_schedule_single_event' )->alias(
            function ( int $t, string $hook ) use ( &$batch_scheduled ): bool {
                if ( 'dt_crm_sync_process_batch' === $hook ) {
                    $batch_scheduled = true;
                }
                return true;
            }
        );

        ( new Disciple_Tools_CRM_Sync_Poll_Handler() )->run_poll( self::FILTER_ID );

        global $wpdb;
        $statuses = array_column( array_column( $wpdb->insert_calls, 'data' ), 'status' );
        $this->assertContains(
            'skipped',
            $statuses,
            'Logger must write a "skipped" entry when zero contacts are returned.'
        );
        $this->assertFalse( $batch_scheduled, 'No batch event should be scheduled when zero contacts found.' );
    }

    public function test_500_during_pagination_logs_failed_status_without_scheduling_any_event(): void {
        $this->mock_get_option_for_filter( [ 'search' => '' ] );

        Functions\when( 'apply_filters' )->alias(
            fn( $hook, $value ) => 'dt_crm_sync_connectors' === $hook
                ? [ 'respond_io' => 'Disciple_Tools_CRM_Sync_Connector_Respond_IO' ]
                : $value
        );

        // Connector returns a 500 (non-rate-limit API error) for the first page.
        Functions\when( 'wp_timezone_string' )->justReturn( 'UTC' );
        Functions\when( 'add_query_arg' )->justReturn( 'https://api.test/mocked' );
        Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
        Functions\when( 'wp_safe_remote_request' )->justReturn( [ '_mocked' => true ] );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 500 );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"message":"Server Error"}' );
        Functions\when( 'wp_remote_retrieve_header' )->justReturn( '' );

        $any_event_scheduled = false;
        Functions\when( 'wp_schedule_single_event' )->alias(
            function () use ( &$any_event_scheduled ): bool {
                $any_event_scheduled = true;
                return true;
            }
        );

        ( new Disciple_Tools_CRM_Sync_Poll_Handler() )->run_poll( self::FILTER_ID );

        global $wpdb;
        $statuses = array_column( array_column( $wpdb->insert_calls, 'data' ), 'status' );
        $this->assertContains(
            'failed',
            $statuses,
            'A 500 during pagination must produce a "failed" log entry.'
        );
        $this->assertFalse(
            $any_event_scheduled,
            'A non-rate-limit error must not schedule any WP-Cron event.'
        );
    }

// Multi-page cursor pagination

    /**
     * Stubs the connector registry + HTTP layer for a two-page response.
     * Call 1 returns $page1_count contacts with a pagination.next cursor URL (cursorId=99).
     * Call 2 returns $page2_count contacts and empty pagination (no more pages).
     * IDs are assigned sequentially starting at 1 across both pages.
     */
    private function mock_two_page_response( int $page1_count, int $page2_count ): void {
        Functions\when( 'apply_filters' )->alias(
            fn( $hook, $value ) => 'dt_crm_sync_connectors' === $hook
                ? [ 'respond_io' => 'Disciple_Tools_CRM_Sync_Connector_Respond_IO' ]
                : $value
        );
        Functions\when( 'wp_timezone_string' )->justReturn( 'UTC' );
        Functions\when( 'add_query_arg' )->justReturn( 'https://api.test/mocked' );
        Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
        Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
        Functions\when( 'wp_safe_remote_request' )->justReturn( [ '_mocked' => true ] );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );

        $page1_body = json_encode( [
            'items'      => array_map( fn( $i ) => [ 'id' => $i ], range( 1, $page1_count ) ),
            'pagination' => [ 'next' => 'https://api.test/v2/contact/list?cursorId=99&limit=50' ],
        ] );
        $page2_body = json_encode( [
            'items'      => array_map( fn( $i ) => [ 'id' => $i ], range( $page1_count + 1, $page1_count + $page2_count ) ),
            'pagination' => [],
        ] );

        $call = 0;
        Functions\when( 'wp_remote_retrieve_body' )->alias(
            function () use ( &$call, $page1_body, $page2_body ) {
                return 0 === $call++ ? $page1_body : $page2_body;
            }
        );
    }

    public function test_multi_page_pagination_collects_contacts_across_all_pages(): void {
        $this->mock_get_option_for_filter( [ 'search' => '' ] );
        $this->mock_two_page_response( 5, 3 );

        $batch_ids = [];
        Functions\when( 'wp_schedule_single_event' )->alias(
            function ( $time, $hook, $args ) use ( &$batch_ids ) {
                if ( 'dt_crm_sync_process_batch' === $hook ) {
                    $batch_ids = array_merge( $batch_ids, $args[0]['ids'] );
                }
                return true;
            }
        );

        ( new Disciple_Tools_CRM_Sync_Poll_Handler() )->run_poll( self::FILTER_ID );

        $this->assertCount( 8, $batch_ids, 'All 8 contacts from both pages must be scheduled.' );
        $this->assertSame( range( 1, 8 ), $batch_ids );
    }

    public function test_poll_rate_limit_reschedules(): void {
        $this->mock_get_option_for_filter( [ 'search' => '' ] );

        Functions\when( 'apply_filters' )->alias(
            fn( $hook, $value ) => 'dt_crm_sync_connectors' === $hook
                ? [ 'respond_io' => 'Disciple_Tools_CRM_Sync_Connector_Respond_IO' ]
                : $value
        );
        Functions\when( 'wp_timezone_string' )->justReturn( 'UTC' );
        Functions\when( 'add_query_arg' )->justReturn( 'https://api.test/mocked' );
        Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
        Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
        Functions\when( 'wp_safe_remote_request' )->justReturn( [ '_mocked' => true ] );
        Functions\when( 'wp_remote_retrieve_header' )->justReturn( '45' );

        $page1_body = json_encode( [
            'items'      => array_map( fn( $i ) => [ 'id' => $i ], range( 1, 5 ) ),
            'pagination' => [ 'next' => 'https://api.test/v2/contact/list?cursorId=99&limit=50' ],
        ] );

        // Page 1 → HTTP 200; page 2 → HTTP 429.
        $call = 0;
        Functions\when( 'wp_remote_retrieve_response_code' )->alias(
            function () use ( &$call ) {
                return 0 === $call ? 200 : 429;
            }
        );
        Functions\when( 'wp_remote_retrieve_body' )->alias(
            function () use ( &$call, $page1_body ) {
                $body = 0 === $call ? $page1_body : '{}';
                $call++;
                return $body;
            }
        );

        $poll_rescheduled = false;
        $batch_ids        = [];
        Functions\when( 'wp_schedule_single_event' )->alias(
            function ( $time, $hook, $args ) use ( &$poll_rescheduled, &$batch_ids ) {
                if ( 'dt_crm_sync_poll' === $hook ) {
                    $poll_rescheduled = true;
                }
                if ( 'dt_crm_sync_process_batch' === $hook ) {
                    $batch_ids = array_merge( $batch_ids, $args[0]['ids'] );
                }
                return true;
            }
        );

        ( new Disciple_Tools_CRM_Sync_Poll_Handler() )->run_poll( self::FILTER_ID );

        $this->assertTrue( $poll_rescheduled, 'A rate-limit on page 2 must reschedule the full poll.' );
        $this->assertEmpty( $batch_ids, 'Rate-limited poll must not schedule any batch events — the rescheduled poll will process all contacts from scratch.' );
    }
}
