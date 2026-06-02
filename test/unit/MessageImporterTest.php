<?php
/**
 * Unit tests for Disciple_Tools_CRM_Sync_Message_Importer.
 *
 * Covers: log upsert (create + update in place), both-sides conversation,
 * sender labeling, placeholder fallbacks, field-target routing, skip sentinal,
 * rate-limit error propagation, pagination, and translation service injection.
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

        // Default post-meta stubs: no existing note, writes succeed.
        Functions\when( 'get_post_meta' )->justReturn( 0 );
        Functions\when( 'update_post_meta' )->justReturn( true );
        Functions\when( 'sanitize_text_field' )->returnArg();
    }

    public function test_import_null_empty_message_list(): void {
        $this->connector->method( 'get_messages' )->willReturn( [ 'data' => [], 'cursor' => [ 'next' => null ] ] );

        $result = $this->importer->import( 'rid_1', 10, 0 );

        $this->assertNull( $result );
        $this->assertEmpty( DT_Posts::$add_comment_calls, 'No note should be created for an empty message list.' );
    }

    public function test_import_skips_message_without_id(): void {
        $this->connector->method( 'get_messages' )->willReturn( [
            'data'   => [ [ 'messageId' => '', 'traffic' => 'incoming', 'message' => [ 'text' => 'hi' ], 'status' => [ [ 'timestamp' => 0 ] ] ] ],
            'cursor' => [ 'next' => null ],
        ] );

        $result = $this->importer->import( 'rid_1', 10, 0 );

        $this->assertNull( $result );
        $this->assertEmpty( DT_Posts::$add_comment_calls, 'No note should be created when the only message has no ID.' );
    }

    // --- single-note creation (both sides) ---

    public function test_import_creates_single_note_with_both_sides(): void {
        $this->connector->method( 'get_messages' )->willReturn( [
            'data'   => [
                [ 'messageId' => 'msg_in',  'traffic' => 'incoming', 'sender' => [ 'source' => 'contact' ], 'message' => [ 'text' => 'Hi there' ],  'status' => [ [ 'timestamp' => 100 ] ] ],
                [ 'messageId' => 'msg_out', 'traffic' => 'outgoing', 'sender' => [ 'source' => 'user' ],    'message' => [ 'text' => 'Hello back' ], 'status' => [ [ 'timestamp' => 200 ] ] ],
            ],
            'cursor' => [ 'next' => null ],
        ] );

        $this->importer->import( 'rid_1', 10, 0 );

        $calls = DT_Posts::$add_comment_calls;
        $this->assertCount( 1, $calls, 'Exactly one conversation log note should be created.' );
        $this->assertStringContainsString( 'Contact:', $calls[0]['content'], 'Log should include incoming message from Contact.' );
        $this->assertStringContainsString( 'Agent:', $calls[0]['content'], 'Log should include outgoing message from Agent.' );
    }

    // --- sender labels ---

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

        $this->importer->import( 'rid_1', 10, 0 );

        $calls = DT_Posts::$add_comment_calls;
        $this->assertNotEmpty( $calls, 'Expected a conversation log note to be created.' );
        $this->assertStringContainsString(
            $expected_sender . ':',
            $calls[0]['content'],
            'The log note should contain the sender label.'
        );
    }

    public static function senderLabelProvider(): array {
        return [
            'internal note'    => [ [ 'traffic' => 'internal', 'sender' => [ 'source' => '' ] ], 'Internal Note' ],
            'outgoing agent'   => [ [ 'traffic' => 'outgoing', 'sender' => [ 'source' => 'user' ] ], 'Agent' ],
            'incoming contact' => [ [ 'traffic' => 'incoming', 'sender' => [ 'source' => 'contact' ] ], 'Contact' ],
            'unknown fallback' => [ [ 'traffic' => '', 'sender' => [ 'source' => '' ] ], 'Respond.io' ],
        ];
    }

    // --- content fallbacks ---

    public function test_import_uses_message_placeholder_when_text_empty(): void {
        $this->connector->method( 'get_messages' )->willReturn( [
            'data'   => [
            [
                'messageId' => 'msg_empty',
                'traffic'   => 'outgoing',
                'sender'    => [ 'source' => 'user' ],
                'message'   => [ 'text' => '', 'type' => 'template' ],
                'status'    => [ [ 'timestamp' => 0 ] ],
            ]
            ],
            'cursor' => [ 'next' => null ],
        ] );

        $this->importer->import( 'rid_1', 10, 0 );

        $calls = DT_Posts::$add_comment_calls;
        $this->assertNotEmpty( $calls );
        $this->assertStringContainsString( '[Message]', $calls[0]['content'] );
    }

    public function test_import_uses_attachment_label_when_type_is_attachment(): void {
        $this->sideloader->method( 'sideload' )->willReturn( 'https://cdn.respond.io/file.pdf' );

        $this->connector->method( 'get_messages' )->willReturn( [
            'data'   => [
            [
                'messageId' => 'msg_attach',
                'traffic'   => 'outgoing',
                'sender'    => [ 'source' => 'user' ],
                'message'   => [ 'text' => '', 'type' => 'attachment', 'url' => 'https://cdn.respond.io/file.pdf', 'filename' => 'report.pdf' ],
                'status'    => [ [ 'timestamp' => 0 ] ],
            ]
            ],
            'cursor' => [ 'next' => null ],
        ] );

        $this->importer->import( 'rid_1', 10, 0 );

        $calls = DT_Posts::$add_comment_calls;
        $this->assertNotEmpty( $calls );
        $this->assertStringContainsString( '[Attachment: report.pdf]', $calls[0]['content'] );
    }

    // --- timestamp formatting ---

    public function test_import_formats_utc_timestamp_in_log_line(): void {
        $ts = 1748246400; // 2025-05-26 00:00:00 UTC

        $this->connector->method( 'get_messages' )->willReturn( [
            'data'   => [
            [
                'messageId' => 'msg_ts',
                'traffic'   => 'incoming',
                'sender'    => [ 'source' => 'contact' ],
                'message'   => [ 'text' => 'hi' ],
                'status'    => [ [ 'timestamp' => $ts ] ],
            ]
            ],
            'cursor' => [ 'next' => null ],
        ] );

        $this->importer->import( 'rid_1', 10, 0 );

        $calls = DT_Posts::$add_comment_calls;
        $this->assertNotEmpty( $calls );
        $this->assertStringContainsString(
            gmdate( 'Y-m-d H:i', $ts ) . ' UTC',
            $calls[0]['content'],
            'Log line should include the UTC timestamp.'
        );
    }

    public function test_import_sorts_messages_chronologically(): void {
        $this->connector->method( 'get_messages' )->willReturn( [
            'data'   => [
                [ 'messageId' => 'msg_b', 'traffic' => 'outgoing', 'sender' => [ 'source' => 'user' ],    'message' => [ 'text' => 'second' ], 'status' => [ [ 'timestamp' => 200 ] ] ],
                [ 'messageId' => 'msg_a', 'traffic' => 'incoming', 'sender' => [ 'source' => 'contact' ], 'message' => [ 'text' => 'first' ],  'status' => [ [ 'timestamp' => 100 ] ] ],
            ],
            'cursor' => [ 'next' => null ],
        ] );

        $this->importer->import( 'rid_1', 10, 0 );

        $calls = DT_Posts::$add_comment_calls;
        $this->assertNotEmpty( $calls );
        $pos_first  = strpos( $calls[0]['content'], 'first' );
        $pos_second = strpos( $calls[0]['content'], 'second' );
        $this->assertLessThan( $pos_second, $pos_first, 'Earlier timestamp must appear before later timestamp in the log.' );
    }

    // --- upsert behaviour ---

    public function test_import_updates_existing_note_on_reimport(): void {
        // Simulate an existing note comment ID stored in post meta.
        Functions\when( 'get_post_meta' )->justReturn( 42 );
        Functions\when( 'get_comment' )->justReturn( (object) [ 'comment_ID' => 42 ] );
        Functions\expect( 'wp_update_comment' )->once()->andReturn( 1 );

        $this->connector->method( 'get_messages' )->willReturn( [
            'data'   => [
            [
                'messageId' => 'msg_1',
                'traffic'   => 'incoming',
                'sender'    => [ 'source' => 'contact' ],
                'message'   => [ 'text' => 'hi' ],
                'status'    => [ [ 'timestamp' => 0 ] ],
            ]
            ],
            'cursor' => [ 'next' => null ],
        ] );

        $this->importer->import( 'rid_1', 10, 0 );

        $this->assertEmpty( DT_Posts::$add_comment_calls, 'add_post_comment must not be called when wp_update_comment is used.' );
    }

    // --- target field routing ---

    public function test_import_writes_to_dt_field_when_target_field_provided(): void {
        $this->connector->method( 'get_messages' )->willReturn( [
            'data'   => [
            [
                'messageId' => 'msg_1',
                'traffic'   => 'incoming',
                'sender'    => [ 'source' => 'contact' ],
                'message'   => [ 'text' => 'field text' ],
                'status'    => [ [ 'timestamp' => 0 ] ],
            ]
            ],
            'cursor' => [ 'next' => null ],
        ] );

        $this->importer->import( 'rid_1', 10, 0, 'notes_for_dt' );

        $this->assertEmpty( DT_Posts::$add_comment_calls, 'add_post_comment must not be called when writing to a DT field.' );
        $calls = DT_Posts::$update_post_calls;
        $this->assertNotEmpty( $calls, 'update_post must be called when a target field is provided.' );
        $this->assertArrayHasKey( 'notes_for_dt', $calls[0]['fields'], 'update_post must target the specified field key.' );
        $this->assertStringContainsString( 'field text', $calls[0]['fields']['notes_for_dt'], 'Field value must include message content.' );
    }

    public function test_import_skips_when_target_is_skip(): void {
        $this->connector->expects( $this->never() )->method( 'get_messages' );

        $result = $this->importer->import( 'rid_1', 10, 0, '__skip__' );

        $this->assertNull( $result );
        $this->assertEmpty( DT_Posts::$add_comment_calls );
        $this->assertEmpty( DT_Posts::$update_post_calls );
    }

    // --- error propagation ---

    public function test_import_propagates_rate_limit_error(): void {
        $error = new WP_Error( 'rate_limited', 'Too Many Requests', [ 'status' => 429 ] );
        $this->connector->method( 'get_messages' )->willReturn( $error );

        $result = $this->importer->import( 'rid_1', 10, 0 );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'rate_limited', $result->get_error_code() );
    }

    // --- pagination ---

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

    // --- translation service injection ---

    public function test_import_appends_translation_when_different_from_original(): void {
        $translation_service = $this->createMock( Disciple_Tools_CRM_Sync_Translation_Service::class );
        $translation_service->method( 'translate' )->willReturn( 'Hello, how are you?' );

        $importer = new Disciple_Tools_CRM_Sync_Message_Importer(
            $this->connector,
            $this->sideloader,
            $translation_service
        );

        $this->connector->method( 'get_messages' )->willReturn( [
            'data'   => [
            [
                'messageId' => 'msg_1',
                'traffic'   => 'incoming',
                'sender'    => [ 'source' => 'contact' ],
                'message'   => [ 'text' => 'Hola, ¿cómo estás?' ],
                'status'    => [ [ 'timestamp' => 0 ] ],
            ]
            ],
            'cursor' => [ 'next' => null ],
        ] );

        $importer->import( 'rid_1', 10, 0 );

        $calls = DT_Posts::$add_comment_calls;
        $this->assertNotEmpty( $calls, 'Expected add_post_comment to be called.' );
        $this->assertStringContainsString(
            '[Translation: Hello, how are you?]',
            $calls[0]['content'],
            'Translation suffix should be appended when translation differs from original.'
        );
    }

    public function test_import_skips_translation_suffix_when_same_as_original(): void {
        $translation_service = $this->createMock( Disciple_Tools_CRM_Sync_Translation_Service::class );
        $translation_service->method( 'translate' )->willReturn( 'Hello world' );

        $importer = new Disciple_Tools_CRM_Sync_Message_Importer(
            $this->connector,
            $this->sideloader,
            $translation_service
        );

        $this->connector->method( 'get_messages' )->willReturn( [
            'data'   => [
            [
                'messageId' => 'msg_2',
                'traffic'   => 'incoming',
                'sender'    => [ 'source' => 'contact' ],
                'message'   => [ 'text' => 'Hello world' ],
                'status'    => [ [ 'timestamp' => 0 ] ],
            ]
            ],
            'cursor' => [ 'next' => null ],
        ] );

        $importer->import( 'rid_1', 10, 0 );

        $calls = DT_Posts::$add_comment_calls;
        $this->assertNotEmpty( $calls, 'Expected add_post_comment to be called.' );
        $this->assertStringNotContainsString(
            '[Translation:',
            $calls[0]['content'],
            'Translation suffix should not be appended when translation equals original.'
        );
    }
}
