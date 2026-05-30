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
     * The comment author field must reflect the traffic direction.
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
        $this->assertSame( 'Agent', $comments[0]->comment_author );
    }

// Deduplication

    /**
     * Importing the same message twice must not create a duplicate comment.
     * The deduplication key is the `_respond_io_message_id` comment meta.
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
            'The same message must not be imported twice (deduplication via comment meta).'
        );
    }

    /**
     * After a successful comment is inserted, the message ID must be stored
     * as `_respond_io_message_id` comment meta for deduplication.
     */
    public function test_import_stores_message_id_as_comment_meta(): void {
        $message = $this->make_message( 'msg_meta_check', 'Meta test', 'incoming' );
        $this->connector->method( 'get_messages' )->willReturn( [
            'data'   => [ $message ],
            'cursor' => [ 'next' => null ],
        ] );

        $this->importer->import( 'rid_001', $this->post_id, 0 );

        $comments = $this->get_test_comments();
        $this->assertCount( 1, $comments );

        $meta_value = get_comment_meta( (int) $comments[0]->comment_ID, '_respond_io_message_id', true );
        $this->assertSame( 'msg_meta_check', $meta_value, 'Message ID must be stored as comment meta.' );
    }

// Attachment messages

    /**
     * An attachment message must produce a comment that includes a link to
     * the attachment URL (either local or original fallback).
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
            $attachment_url,
            $comments[0]->comment_content,
            'Attachment URL must appear in the comment content.'
        );
        $this->assertStringContainsString(
            '[attachment]',
            $comments[0]->comment_content,
            'Comment must include [attachment] link label.'
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
        $this->assertCount( 2, $comments, 'All paginated messages must be imported.' );
        $this->assertSame( 2, $call_count, 'get_messages() must be called for each page.' );
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
