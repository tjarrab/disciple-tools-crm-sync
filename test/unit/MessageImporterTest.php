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
        $this->connector->method( 'get_label' )->willReturn( 'Respond.io' );

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
                'messageId' => $ts * 1_000_000,
                'traffic'   => 'incoming',
                'sender'    => [ 'source' => 'contact' ],
                'message'   => [ 'text' => 'hi' ],
                'status'    => [],
            ]
            ],
            'cursor' => [ 'next' => null ],
        ] );

        $this->importer->import( 'rid_1', 10, 0 );

        $calls = DT_Posts::$add_comment_calls;
        $this->assertNotEmpty( $calls );
        $this->assertStringContainsString(
            gmdate( 'Y-m-d, l, H:i:s', $ts ) . ' UTC',
            $calls[0]['content'],
            'Log line should include the UTC timestamp derived from messageId.'
        );
    }

    public function test_import_sorts_messages_chronologically(): void {
        $this->connector->method( 'get_messages' )->willReturn( [
            'data'   => [
                [ 'messageId' => 200_000_000, 'traffic' => 'outgoing', 'sender' => [ 'source' => 'user' ],    'message' => [ 'text' => 'second' ], 'status' => [] ],
                [ 'messageId' => 100_000_000, 'traffic' => 'incoming', 'sender' => [ 'source' => 'contact' ], 'message' => [ 'text' => 'first' ],  'status' => [] ],
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

    public function test_import_derives_timestamp_from_message_id_for_incoming_message(): void {
        $ts = 1748246400; // 2025-05-26 00:00:00 UTC — incoming messages have status: []

        $this->connector->method( 'get_messages' )->willReturn( [
            'data'   => [
            [
                'messageId' => $ts * 1_000_000,
                'traffic'   => 'incoming',
                'sender'    => [ 'source' => 'contact' ],
                'message'   => [ 'text' => 'hi' ],
                'status'    => [],
            ]
            ],
            'cursor' => [ 'next' => null ],
        ] );

        $this->importer->import( 'rid_1', 10, 0 );

        $calls = DT_Posts::$add_comment_calls;
        $this->assertNotEmpty( $calls );
        $this->assertStringContainsString(
            '2025-',
            $calls[0]['content'],
            'Timestamp from messageId should produce a real year, not the — placeholder.'
        );
        $this->assertStringNotContainsString(
            '[—]',
            $calls[0]['content'],
            'An incoming message with a valid messageId must not render with the missing-timestamp placeholder.'
        );
    }

    public function test_import_sorts_contact_before_agent_when_contact_sent_first(): void {
        // The original bug: API returns newest-first so the agent reply comes back
        // before the contact message in the items array, but after sorting by
        // messageId the contact message must appear first in the log.
        $this->connector->method( 'get_messages' )->willReturn( [
            'data'   => [
                [ 'messageId' => 1_000_200_000_000, 'traffic' => 'outgoing', 'sender' => [ 'source' => 'user' ],    'message' => [ 'text' => 'agent reply' ],  'status' => [] ],
                [ 'messageId' => 1_000_100_000_000, 'traffic' => 'incoming', 'sender' => [ 'source' => 'contact' ], 'message' => [ 'text' => 'contact msg' ], 'status' => [] ],
            ],
            'cursor' => [ 'next' => null ],
        ] );

        $this->importer->import( 'rid_1', 10, 0 );

        $calls = DT_Posts::$add_comment_calls;
        $this->assertNotEmpty( $calls );
        $pos_contact = strpos( $calls[0]['content'], 'contact msg' );
        $pos_agent   = strpos( $calls[0]['content'], 'agent reply' );
        $this->assertLessThan(
            $pos_agent,
            $pos_contact,
            'Contact message sent before agent reply must appear first in the log.'
        );
    }

    public function test_import_uses_contact_name_as_sender_label(): void {
        $this->connector->method( 'get_messages' )->willReturn( [
            'data'   => [
            [
                'messageId' => 1_000_100_000_000,
                'traffic'   => 'incoming',
                'sender'    => [ 'source' => 'contact' ],
                'message'   => [ 'text' => 'hello' ],
                'status'    => [],
            ]
            ],
            'cursor' => [ 'next' => null ],
        ] );

        $this->importer->import( 'rid_1', 10, 0, null, 'import', 'Ahmed Ali' );

        $calls = DT_Posts::$add_comment_calls;
        $this->assertNotEmpty( $calls );
        $this->assertStringContainsString(
            'Ahmed Ali:',
            $calls[0]['content'],
            'Contact name passed to import() should appear as the sender label.'
        );
        $this->assertStringNotContainsString(
            'Contact:',
            $calls[0]['content'],
            'Generic "Contact" label must not appear when a real name is provided.'
        );
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

    public function test_import_logs_warning_when_field_write_fails(): void {
        $this->connector->method( 'get_messages' )->willReturn( [
            'data'   => [
            [
                'messageId' => 'msg_1',
                'traffic'   => 'incoming',
                'sender'    => [ 'source' => 'contact' ],
                'message'   => [ 'text' => 'hello' ],
                'status'    => [ [ 'timestamp' => 0 ] ],
            ]
            ],
            'cursor' => [ 'next' => null ],
        ] );

        DT_Posts::$update_post_result = new WP_Error( 'permission_error', 'Not allowed.' );

        $result = $this->importer->import( 'rid_1', 10, 0, 'notes_for_dt' );

        $this->assertNull( $result, 'A field write failure must not abort the batch (must return null).' );

        global $wpdb;
        $log_rows = array_filter(
            $wpdb->insert_calls,
            fn( $call ) => str_contains( $call['table'], 'dt_crm_sync_logs' )
        );
        $this->assertNotEmpty( $log_rows, 'A log row must be written when the field write fails.' );

        $row = array_values( $log_rows )[0]['data'];
        $this->assertSame( 'warning', $row['status'], 'Log status must be "warning" for a non-fatal field write failure.' );
        $this->assertStringContainsString( 'notes_for_dt', $row['message'], 'Log message must identify the target field key.' );
        $this->assertStringContainsString( 'Not allowed.', $row['message'], 'Log message must include the WP_Error message.' );
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
        $translation_service->method( 'translate_batch' )->willReturnCallback(
            function ( array $texts ) {
                return array_map( fn() => 'Hello, how are you?', $texts );
            }
        );

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
        $translation_service->method( 'translate_batch' )->willReturnCallback(
            function ( array $texts ) {
                return $texts; // return originals unchanged
            }
        );

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

    public function test_import_succeeds_when_translate_batch_returns_empty_array(): void {
        $translation_service = $this->createMock( Disciple_Tools_CRM_Sync_Translation_Service::class );
        $translation_service->method( 'translate_batch' )->willReturn( [] );

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
                    'message'   => [ 'text' => 'Hola' ],
                    'status'    => [ [ 'timestamp' => 0 ] ],
                ],
            ],
            'cursor' => [ 'next' => null ],
        ] );

        $result = $importer->import( 'rid_1', 10, 0 );

        $this->assertNull( $result, 'Import should complete successfully.' );
        $calls = DT_Posts::$add_comment_calls;
        $this->assertNotEmpty( $calls, 'Conversation log should still be written when translate_batch returns [].' );
        $this->assertStringNotContainsString( '[Translation:', $calls[0]['content'] );
    }

    public function test_translation_containing_html_metacharacters_is_encoded(): void {
        // Regression: esc_html() must be used when appending the translation label,
        // not sanitize_text_field(), which passes & < > through unencoded.
        $translation_service = $this->createMock( Disciple_Tools_CRM_Sync_Translation_Service::class );
        $translation_service->method( 'translate_batch' )->willReturnCallback(
            function ( array $texts ) {
                return array_map( fn() => 'A & B < C > D', $texts );
            }
        );

        $importer = new Disciple_Tools_CRM_Sync_Message_Importer(
            $this->connector,
            $this->sideloader,
            $translation_service
        );

        $this->connector->method( 'get_messages' )->willReturn( [
            'data'   => [
                [
                    'messageId' => 'msg_meta',
                    'traffic'   => 'incoming',
                    'sender'    => [ 'source' => 'contact' ],
                    'message'   => [ 'text' => 'Hola' ],
                    'status'    => [ [ 'timestamp' => 0 ] ],
                ],
            ],
            'cursor' => [ 'next' => null ],
        ] );

        $importer->import( 'rid_1', 10, 0 );

        $content = DT_Posts::$add_comment_calls[0]['content'] ?? '';
        $this->assertStringContainsString( '&amp;', $content, 'Ampersand must be HTML-encoded in the translation label.' );
        $this->assertStringContainsString( '&lt;', $content, 'Less-than must be HTML-encoded in the translation label.' );
        $this->assertStringContainsString( '&gt;', $content, 'Greater-than must be HTML-encoded in the translation label.' );
        // The raw characters must not appear inside the [Translation: ...] label itself.
        $label_start = strpos( $content, '[Translation:' );
        $label_end   = strpos( $content, ']', $label_start );
        $label        = substr( $content, $label_start, $label_end - $label_start + 1 );
        $this->assertStringNotContainsString( ' & ', $label, 'Raw ampersand must not appear inside the translation label.' );
        $this->assertStringNotContainsString( ' < ', $label, 'Raw less-than must not appear inside the translation label.' );
    }

    // --- sideload failure warning log ---

    public function test_sideload_failure_writes_warning_log_entry(): void {
        // Stub a sideloader that reports a blocked host and returns the original URL.
        $failing_sideloader = new class() extends Disciple_Tools_CRM_Sync_Media_Sideloader {
            public function sideload( string $url, int $post_id ): string {
                return $url;
            }
            public function get_last_error(): ?string {
                return 'ssrf_blocked: host "evil.example.com" not in allowlist';
            }
        };

        $importer = new Disciple_Tools_CRM_Sync_Message_Importer( $this->connector, $failing_sideloader );

        $this->connector->method( 'get_messages' )->willReturn( [
            'data'   => [
                [
                    'messageId' => 'msg_attach',
                    'traffic'   => 'outgoing',
                    'sender'    => [ 'source' => 'user' ],
                    'message'   => [ 'type' => 'attachment', 'text' => '', 'url' => 'https://evil.example.com/file.pdf', 'filename' => 'file.pdf' ],
                    'status'    => [ [ 'timestamp' => 0 ] ],
                ],
            ],
            'cursor' => [ 'next' => null ],
        ] );

        $importer->import( 'rid_1', 10, 0, null, 'scheduled' );

        global $wpdb;
        $warning_calls = array_filter(
            $wpdb->insert_calls,
            fn( $call ) => ( $call['data']['status'] ?? '' ) === 'warning'
        );

        $this->assertNotEmpty( $warning_calls, 'A warning log entry should be written when sideload fails.' );

        $entry = array_values( $warning_calls )[0]['data'];
        $this->assertSame( 'scheduled', $entry['trigger_type'] );
        $this->assertSame( 'rid_1', $entry['contact_id'] );
        $this->assertStringStartsWith( 'ssrf_blocked:', $entry['message'] );
    }

    // --- plain-text log newline normalization ---

    public function test_plain_log_collapses_embedded_newlines_in_message_text(): void {
        // Respond.io sometimes delivers a message whose text contains a URL on its
        // own line, e.g. "Start here:\nhttps://example.com/path". Without
        // normalization this splits into two physical lines and breaks the viewer.
        $this->connector->method( 'get_messages' )->willReturn( [
            'data'   => [
            [
                'messageId' => 'msg_nl',
                'traffic'   => 'outgoing',
                'sender'    => [ 'source' => 'user' ],
                'message'   => [ 'text' => "Start here:\nhttps://example.com/path" ],
                'status'    => [],
            ]
            ],
            'cursor' => [ 'next' => null ],
        ] );

        $this->importer->import( 'rid_1', 10, 0, 'log_field' );

        $calls = DT_Posts::$update_post_calls;
        $this->assertNotEmpty( $calls );
        $stored = $calls[0]['fields']['log_field'];

        $this->assertSame( 1, substr_count( $stored, "\n" ) + 1, 'The plain-text log for a single message must be exactly one line.' );
        $this->assertStringNotContainsString( "\n", $stored, 'Embedded newlines must be collapsed before writing the plain-text log.' );
        $this->assertStringContainsString( 'Start here:', $stored, 'Message content must still appear in the log.' );
        $this->assertStringContainsString( 'https://example.com/path', $stored, 'URL that was on a separate line must still appear after normalization.' );
    }

    public function test_plain_log_collapses_crlf_in_message_text(): void {
        $this->connector->method( 'get_messages' )->willReturn( [
            'data'   => [
            [
                'messageId' => 'msg_crlf',
                'traffic'   => 'incoming',
                'sender'    => [ 'source' => 'contact' ],
                'message'   => [ 'text' => "Line one\r\nLine two" ],
                'status'    => [],
            ]
            ],
            'cursor' => [ 'next' => null ],
        ] );

        $this->importer->import( 'rid_1', 10, 0, 'log_field' );

        $calls = DT_Posts::$update_post_calls;
        $this->assertNotEmpty( $calls );
        $stored = $calls[0]['fields']['log_field'];

        $this->assertStringNotContainsString( "\r", $stored, 'Carriage returns must be removed from the plain-text log.' );
        $this->assertStringNotContainsString( "\n", $stored, 'Embedded newlines must be removed from the plain-text log.' );
    }

    public function test_plain_log_collapses_newlines_in_appended_translation(): void {
        // The translation suffix itself can span lines when the translated text
        // contains newlines (e.g. a URL on a new line in the translation).
        $translation_service = $this->createMock( Disciple_Tools_CRM_Sync_Translation_Service::class );
        $translation_service->method( 'translate_batch' )->willReturnCallback(
            function ( array $texts ) {
                return array_map( fn() => "Yes, start here:\nhttps://example.com/", $texts );
            }
        );

        $importer = new Disciple_Tools_CRM_Sync_Message_Importer(
            $this->connector,
            $this->sideloader,
            $translation_service
        );

        $this->connector->method( 'get_messages' )->willReturn( [
            'data'   => [
            [
                'messageId' => 'msg_tr_nl',
                'traffic'   => 'outgoing',
                'sender'    => [ 'source' => 'user' ],
                'message'   => [ 'text' => 'نعم، ابدأ من هنا:' ],
                'status'    => [],
            ]
            ],
            'cursor' => [ 'next' => null ],
        ] );

        $importer->import( 'rid_1', 10, 0, 'log_field' );

        $calls = DT_Posts::$update_post_calls;
        $this->assertNotEmpty( $calls );
        $stored = $calls[0]['fields']['log_field'];

        $this->assertStringNotContainsString( "\n", $stored, 'Embedded newlines inside the translation must not survive into the plain-text log.' );
        $this->assertStringContainsString( '[Translation:', $stored, 'Translation suffix must still appear in the log.' );
        $this->assertStringContainsString( 'https://example.com/', $stored, 'URL from translation must appear in the log.' );
    }
}
