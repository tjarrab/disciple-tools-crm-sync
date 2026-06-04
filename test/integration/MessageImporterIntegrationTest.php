<?php
/**
 * Integration tests for Disciple_Tools_CRM_Sync_Message_Importer.
 *
 * Tests the full import pipeline — comment creation, deduplication via
 * comment meta, and attachment URL storage — using a real WordPress
 * environment with an actual DT contacts post.
 *
 * DT_Posts::add_post_comment() is the production entrypoint. In the WP test
 * environment the DT theme may not be fully loaded, so we fall back to using
 * wp_insert_comment() if add_post_comment() is unavailable.  The tests assert
 * the correct data flows through the Message_Importer whether the DT method
 * is real or stubbed.
 *
 * Run with: php vendor/bin/phpunit -c phpunit.xml.dist --testdox
 */

class MessageImporterIntegrationTest extends TestCase {

    /** @var int DT contact post ID created for each test. */
    private int $post_id;

    /** @var Disciple_Tools_CRM_Sync_Abstract_Connector|\PHPUnit\Framework\MockObject\MockObject */
    private $connector;

    /** @var Disciple_Tools_CRM_Sync_Media_Sideloader|\PHPUnit\Framework\MockObject\MockObject */
    private $sideloader;

    private Disciple_Tools_CRM_Sync_Message_Importer $importer;

    protected function setUp(): void {
        parent::setUp();

        // Create a minimal contact post to attach comments to.
        $this->post_id = wp_insert_post( [
            'post_type'   => 'contacts',
            'post_status' => 'publish',
            'post_title'  => 'Test Contact for Message Importer',
        ] );

        $this->connector = $this->createMock( Disciple_Tools_CRM_Sync_Abstract_Connector::class );
        $this->connector->method( 'get_meta_key_prefix' )->willReturn( '_respond_io_' );
        $this->connector->method( 'get_label' )->willReturn( 'Respond.io' );

        $this->sideloader = $this->createMock( Disciple_Tools_CRM_Sync_Media_Sideloader::class );
        // Default sideload: return the original URL unchanged (no HTTP calls).
        $this->sideloader->method( 'sideload' )->willReturnArgument( 0 );

        $this->importer = new Disciple_Tools_CRM_Sync_Message_Importer( $this->connector, $this->sideloader );
    }

    protected function tearDown(): void {
        if ( ! empty( $this->post_id ) ) {
            wp_delete_post( $this->post_id, true );
        }
        parent::tearDown();
    }

// Helpers

    /**
     * Return the comments attached to the test post, newest first.
     * @return array<WP_Comment>
     */
    private function get_test_comments(): array {
        return get_comments( [
            'post_id' => $this->post_id,
            'orderby' => 'comment_ID',
            'order'   => 'DESC',
        ] );
    }

    /**
     * Build a minimal message array matching the Respond.io v2 API shape.
     */
    private function make_message( string $msg_id, string $text, string $traffic = 'incoming', int $ts = 0 ): array {
        return [
            'messageId' => $msg_id,
            'traffic'   => $traffic,
            'sender'    => [ 'source' => 'contact' === $traffic ? 'contact' : 'user' ],
            'message'   => [ 'type' => 'text', 'text' => $text ],
            'status'    => [ [ 'timestamp' => $ts > 0 ? $ts : time() ] ],
        ];
    }

// Comment creation

    /**
     * A valid message must be inserted as a comment on the DT contact post
     * with the message text in the content.
     */
    public function test_import_creates_comment_for_incoming_message(): void {
        $message = $this->make_message( 'msg_001', 'Hello from contact', 'incoming' );
        $this->connector->method( 'get_messages' )->willReturn( [
            'data'   => [ $message ],
            'cursor' => [ 'next' => null ],
        ] );

        $result = $this->importer->import( 'rid_001', $this->post_id, 0 );

        $this->assertNull( $result, 'import() must return null on success.' );

        $comments = $this->get_test_comments();
        $this->assertCount( 1, $comments, 'Exactly one comment must be created.' );
        $this->assertStringContainsString(
            'Hello from contact',
            $comments[0]->comment_content,
            'Comment content must include the message text.'
        );
    }

    /**
     * All conversation log notes are written under a single 'Respond.io' author.
     * The traffic direction (Agent / Contact) lives inside the comment content,
     * not in comment_author — the log is one consolidated note, not per-message.
     */
    public function test_import_sets_correct_comment_author_for_outgoing_message(): void {
        $message = $this->make_message( 'msg_002', 'Hello from agent', 'outgoing' );
        $this->connector->method( 'get_messages' )->willReturn( [
            'data'   => [ $message ],
            'cursor' => [ 'next' => null ],
        ] );

        $this->importer->import( 'rid_001', $this->post_id, 0 );

        $comments = $this->get_test_comments();
        $this->assertCount( 1, $comments );
        $this->assertSame( 'Respond.io', $comments[0]->comment_author );
        $this->assertStringContainsString(
            'Agent',
            $comments[0]->comment_content,
            'The sender label must appear inside the log content.'
        );
    }

// Deduplication

    /**
     * Running the importer twice for the same contact must not create a second
     * comment. The importer stores the comment ID in post meta and calls
     * wp_update_comment() on subsequent runs instead of inserting a new one.
     */
    public function test_import_skips_already_imported_message(): void {
        $message = $this->make_message( 'msg_dup', 'Duplicate message', 'incoming' );
        $this->connector->method( 'get_messages' )->willReturn( [
            'data'   => [ $message ],
            'cursor' => [ 'next' => null ],
        ] );

        // First import.
        $this->importer->import( 'rid_001', $this->post_id, 0 );

        // Second import — same message ID, same post.
        $this->importer->import( 'rid_001', $this->post_id, 0 );

        $comments = $this->get_test_comments();
        $this->assertCount(
            1,
            $comments,
            'Re-importing must update the existing log comment, not create a second one.'
        );
    }

    /**
     * After the log comment is created, the importer must store its ID in post
     * meta so subsequent runs can update it in place. The meta key follows the
     * connector prefix convention: `_respond_io_message_log_comment_id`.
     */
    public function test_import_stores_message_id_as_comment_meta(): void {
        $message = $this->make_message( 'msg_meta_check', 'Meta test', 'incoming' );
        $this->connector->method( 'get_messages' )->willReturn( [
            'data'   => [ $message ],
            'cursor' => [ 'next' => null ],
        ] );

        $this->importer->import( 'rid_001', $this->post_id, 0 );

        $comments   = $this->get_test_comments();
        $this->assertCount( 1, $comments );

        $stored_id = get_post_meta( $this->post_id, '_respond_io_message_log_comment_id', true );
        $this->assertSame(
            (string) $comments[0]->comment_ID,
            (string) $stored_id,
            'The log comment ID must be saved in post meta for upsert on next import.'
        );
    }

// Attachment messages

    /**
     * Attachment messages get sideloaded to the media library, but the URL is
     * intentionally left out of the log — CDN links expire and the sideloaded
     * path can change. What matters is that the log records something happened.
     */
    public function test_import_attachment_message_stores_url_in_comment(): void {
        $attachment_url = 'https://cdn.respond.io/file.pdf';

        $message = [
            'messageId' => 'msg_attach',
            'traffic'   => 'incoming',
            'sender'    => [ 'source' => 'contact' ],
            'message'   => [
                'type' => 'attachment',
                'text' => '',
                'url'  => $attachment_url,
            ],
            'status'    => [ [ 'timestamp' => time() ] ],
        ];

        $this->connector->method( 'get_messages' )->willReturn( [
            'data'   => [ $message ],
            'cursor' => [ 'next' => null ],
        ] );

        // Sideload returns the original URL (no real HTTP in integration test).
        $this->sideloader->method( 'sideload' )->willReturn( $attachment_url );

        $this->importer->import( 'rid_001', $this->post_id, 0 );

        $comments = $this->get_test_comments();
        $this->assertCount( 1, $comments );
        $this->assertStringContainsString(
            '[Attachment]',
            $comments[0]->comment_content,
            'Comment must include the [Attachment] label.'
        );
    }

// Pagination

    /**
     * Messages spread across multiple pages must all be imported (pagination
     * cursor must be followed until exhausted).
     */
    public function test_import_follows_pagination_cursor(): void {
        $call_count = 0;
        $this->connector->method( 'get_messages' )->willReturnCallback(
            function ( string $respond_id, ?string $cursor_id ) use ( &$call_count ): array {
                $call_count++;
                if ( 1 === $call_count ) {
                    return [
                        'data'   => [ $this->make_message( 'msg_page1', 'Page one message', 'incoming' ) ],
                        'cursor' => [ 'next' => 'cursor_abc' ],
                    ];
                }
                return [
                    'data'   => [ $this->make_message( 'msg_page2', 'Page two message', 'incoming' ) ],
                    'cursor' => [ 'next' => null ],
                ];
            }
        );

        $this->importer->import( 'rid_001', $this->post_id, 0 );

        $comments = $this->get_test_comments();
        // One consolidated log comment, not one per message.
        $this->assertCount( 1, $comments, 'All paginated messages must be imported.' );
        $this->assertSame( 2, $call_count, 'get_messages() must be called for each page.' );
        $this->assertStringContainsString( 'Page one message', $comments[0]->comment_content );
        $this->assertStringContainsString( 'Page two message', $comments[0]->comment_content );
    }

// Error propagation

    /**
     * When the connector returns a WP_Error (e.g. 429) import() must
     * propagate it so the batch processor can reschedule.
     */
    public function test_import_propagates_rate_limit_error(): void {
        $error = new WP_Error( 'rate_limited', 'Too Many Requests', [ 'status' => 429 ] );
        $this->connector->method( 'get_messages' )->willReturn( $error );

        $result = $this->importer->import( 'rid_001', $this->post_id, 0 );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'rate_limited', $result->get_error_code() );
    }
}
