<?php
/**
 * Unit tests for Disciple_Tools_CRM_Sync_Message_Importer.
 *
 * Covers empty list, deduplication, sender labeling, rate-limit error propagation,
 * and pagination cursor behavior.
 */

use Brain\Monkey\Functions;

class MessageImporterTest extends BrainMonkeyTestCase {

    private Disciple_Tools_CRM_Sync_Message_Importer $importer;
    /** @var MockObject&Disciple_Tools_CRM_Sync_Abstract_Connector */
    private $connector;
    /** @var MockObject&Disciple_Tools_CRM_Sync_Media_Sideloader */
    private $sideloader;

    protected function setUp(): void {
        parent::setUp();

        $this->connector = $this->createMock( Disciple_Tools_CRM_Sync_Abstract_Connector::class );
        $this->connector->method( 'get_meta_key_prefix' )->willReturn( '_respond_io_' );

        $this->sideloader = $this->createMock( Disciple_Tools_CRM_Sync_Media_Sideloader::class );
        $this->importer   = new Disciple_Tools_CRM_Sync_Message_Importer( $this->connector, $this->sideloader );
    }

    public function test_import_null_empty_message_list(): void {
        $this->connector->method( 'get_messages' )->willReturn( [ 'data' => [], 'cursor' => [ 'next' => null ] ] );

        $result = $this->importer->import( 'rid_1', 10, 0 );

        $this->assertNull( $result );
    }

    public function test_import_skips_message_without_id(): void {
        $this->connector->method( 'get_messages' )->willReturn( [
            'data'   => [ [ 'messageId' => '', 'traffic' => 'incoming', 'message' => [ 'text' => 'hi' ] ] ],
            'cursor' => [ 'next' => null ],
        ] );

        // get_comments would not be called for a message with no ID.
        Functions\expect( 'get_comments' )->never();

        $result = $this->importer->import( 'rid_1', 10, 0 );

        $this->assertNull( $result );
        $this->assertEmpty( DT_Posts::$add_comment_calls, 'No comment should be added for a message without an ID.' );
    }

    public function test_import_skips_already_imported_message(): void {
        $this->connector->method( 'get_messages' )->willReturn( [
            'data'   => [ [ 'messageId' => 'msg_42', 'traffic' => 'incoming', 'message' => [ 'text' => 'hi' ], 'status' => [ [ 'timestamp' => 0 ] ] ] ],
            'cursor' => [ 'next' => null ],
        ] );

        // Batch dedup query returns a row indicating msg_42 is already imported.
        global $wpdb;
        $wpdb->next_get_results_result = [ (object) [ 'meta_value' => 'msg_42' ] ];

        $result = $this->importer->import( 'rid_1', 10, 0 );

        $this->assertNull( $result );
        $this->assertEmpty( DT_Posts::$add_comment_calls, 'add_post_comment should not be called for already-imported message.' );
    }

    /**
     * @dataProvider senderLabelProvider
     */
    public function test_import_labels_sender_correctly( array $msg_fields, string $expected_sender ): void {
        $message = array_merge(
            [
                'messageId' => 'msg_' . uniqid(),
                'status'    => [ [ 'timestamp' => 0 ] ],
                'message'   => [ 'text' => 'hello' ],
            ],
            $msg_fields
        );

        $this->connector->method( 'get_messages' )->willReturn( [
            'data'   => [ $message ],
            'cursor' => [ 'next' => null ],
        ] );

        Functions\when( 'get_comments' )->justReturn( 0 );
        Functions\when( 'sanitize_text_field' )->returnArg();

        $this->importer->import( 'rid_1', 10, 0 );

        $calls = DT_Posts::$add_comment_calls;
        $this->assertNotEmpty( $calls, 'Expected add_post_comment to be called.' );
        $this->assertSame( $expected_sender, $calls[0]['args']['comment_author'] );
    }

    public static function senderLabelProvider(): array {
        return [
            'internal note'  => [ [ 'traffic' => 'internal', 'sender' => [ 'source' => '' ] ], 'Internal Note' ],
            'outgoing agent' => [ [ 'traffic' => 'outgoing', 'sender' => [ 'source' => 'user' ] ], 'Agent' ],
            'incoming contact' => [ [ 'traffic' => 'incoming', 'sender' => [ 'source' => 'contact' ] ], 'Contact' ],
            'unknown fallback'  => [ [ 'traffic' => '', 'sender' => [ 'source' => '' ] ], 'Respond.io' ],
        ];
    }

    public function test_import_propagates_rate_limit_error(): void {
        $error = new WP_Error( 'rate_limited', 'Too Many Requests', [ 'status' => 429 ] );
        $this->connector->method( 'get_messages' )->willReturn( $error );

        $result = $this->importer->import( 'rid_1', 10, 0 );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'rate_limited', $result->get_error_code() );
    }

    public function test_import_follows_pagination_cursor(): void {
        $call_count = 0;
        $this->connector->method( 'get_messages' )->willReturnCallback(
            function ( $respond_id, $cursor_id ) use ( &$call_count ) {
                $call_count++;
                if ( null === $cursor_id ) {
                    return [ 'data' => [], 'cursor' => [ 'next' => '100' ] ];
                }
                return [ 'data' => [], 'cursor' => [ 'next' => null ] ];
            }
        );

        $this->importer->import( 'rid_1', 10, 0 );

        $this->assertSame( 2, $call_count, 'Should paginate twice: first page then cursor page.' );
    }

    public function test_import_passes_local_time_for_comment_date_and_utc_for_comment_date_gmt(): void {
        // Timestamp 1748246400 = 2025-05-26 00:00:00 UTC. Use a value > 0 so the
        // production code takes the wp_date() / gmdate() branch, not the fallback.
        $ts = 1748246400;

        $this->connector->method( 'get_messages' )->willReturn( [
            'data'   => [
            [
                'messageId' => 'msg_ts',
                'traffic'   => 'incoming',
                'message'   => [ 'text' => 'hi' ],
                'status'    => [ [ 'timestamp' => $ts ] ],
            ]
            ],
            'cursor' => [ 'next' => null ],
        ] );

        // Stub wp_date() to simulate a non-UTC local timezone (e.g. UTC+8).
        Functions\when( 'wp_date' )->justReturn( '2025-05-26 08:00:00' );

        $this->importer->import( 'rid_1', 10, 0 );

        $calls = DT_Posts::$add_comment_calls;
        $this->assertNotEmpty( $calls, 'Expected add_post_comment to be called.' );
        $this->assertSame(
            '2025-05-26 08:00:00',
            $calls[0]['args']['comment_date'],
            'comment_date must use wp_date() (local timezone).'
        );
        $this->assertSame(
            gmdate( 'Y-m-d H:i:s', $ts ),
            $calls[0]['args']['comment_date_gmt'],
            'comment_date_gmt must use gmdate() (UTC).'
        );
    }
}
