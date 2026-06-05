<?php
/**
 * Unit tests for the render_message_bubbles() method in the message viewer.
 *
 * The viewer is the piece users actually see, so these tests focus on the
 * output that ends up in the browser: correct bubble classes, correct sender
 * labels, and — crucially — that content spread across multiple physical lines
 * (either legacy bad storage or a message that still has embedded newlines) is
 * reassembled into a single bubble rather than broken out as system notes.
 */

use Brain\Monkey\Functions;

// ---------------------------------------------------------------------------
// Test harness
// ---------------------------------------------------------------------------

/**
 * Exposes the private render_message_bubbles() method as public so we can
 * call it directly from tests without routing through the full REST stack.
 */
class TestableMessageViewer extends Disciple_Tools_CRM_Sync_REST_Message_Viewer {

    public function expose_render( string $text, string $connector_label ): string {
        return $this->render_message_bubbles( $text, $connector_label );
    }
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

class MessageViewerTest extends BrainMonkeyTestCase {

    private TestableMessageViewer $viewer;

    protected function setUp(): void {
        parent::setUp();
        Functions\when( 'sanitize_text_field' )->returnArg();
        $this->viewer = new TestableMessageViewer();
    }

// --- basic bubble rendering ---

    public function test_agent_message_renders_as_blue_bubble(): void {
        $log = '[2025-05-26, Monday, 10:00:00 UTC] Agent: Hello!';

        $html = $this->viewer->expose_render( $log, 'Respond.io' );

        $this->assertStringContainsString( 'bg-blue-500', $html, 'Agent bubble must use the blue background class.' );
        $this->assertStringContainsString( 'Hello!', $html );
    }

    public function test_contact_message_renders_as_white_bubble(): void {
        $log = '[2025-05-26, Monday, 10:00:00 UTC] Contact: Hi there';

        $html = $this->viewer->expose_render( $log, 'Respond.io' );

        $this->assertStringContainsString( 'bg-white', $html, 'Contact bubble must use the white background class.' );
        $this->assertStringContainsString( 'Hi there', $html );
    }

    public function test_internal_note_renders_as_amber_bubble(): void {
        $log = '[2025-05-26, Monday, 10:00:00 UTC] Internal Note: check this';

        $html = $this->viewer->expose_render( $log, 'Respond.io' );

        $this->assertStringContainsString( 'bg-amber-50', $html, 'Internal Note must use the amber background class.' );
        $this->assertStringContainsString( 'check this', $html );
    }

    public function test_sender_and_timestamp_appear_in_label(): void {
        $log = '[2025-05-26, Monday, 10:00:00 UTC] Agent: Hello!';

        $html = $this->viewer->expose_render( $log, 'Respond.io' );

        $this->assertStringContainsString( 'Agent', $html );
        $this->assertStringContainsString( '2025-05-26', $html );
    }

    public function test_empty_log_returns_empty_string(): void {
        $html = $this->viewer->expose_render( '', 'Respond.io' );

        $this->assertSame( '', $html );
    }

    public function test_blank_lines_between_entries_are_ignored(): void {
        $log = "\n[2025-05-26, Monday, 10:00:00 UTC] Agent: Hi\n\n[2025-05-26, Monday, 10:01:00 UTC] Contact: Hello\n";

        $html = $this->viewer->expose_render( $log, 'Respond.io' );

        $this->assertStringContainsString( 'Hi', $html );
        $this->assertStringContainsString( 'Hello', $html );
        // Two bubbles should appear (two "flex flex-col" wrappers)
        $this->assertSame( 2, substr_count( $html, 'flex flex-col' ) );
    }

// --- multi-line reassembly (the core bug fix) ---

    public function test_continuation_line_is_joined_into_same_bubble(): void {
        // This reproduces the exact log format written before the importer
        // normalised embedded newlines: the agent message spills onto a second
        // physical line and the viewer must reassemble it into one bubble.
        $log = implode( "\n", [
            '[2025-02-25, Tuesday, 02:03:00 UTC] Agent: باهية برشة! تنجم تبدا من هنا:',
            'https://example.com/5/ [Translation: Very good! You can start from here:',
            'https://example.com/5/]',
        ] );

        $html = $this->viewer->expose_render( $log, 'Respond.io' );

        // All three physical lines make up one logical message — only one bubble.
        $this->assertSame( 1, substr_count( $html, 'bg-blue-500' ), 'Three continuation lines must produce exactly one agent bubble.' );
        $this->assertStringContainsString( 'example.com/5/', $html, 'URL must appear inside the bubble.' );
        $this->assertStringContainsString( '[Translation:', $html, 'Translation suffix must appear inside the bubble.' );
        // The stray-line system-note class must NOT appear for the continuation lines.
        $this->assertSame( 0, substr_count( $html, 'self-center text-xs text-gray-400' ), 'Continuation lines must not render as system notes.' );
    }

    public function test_second_multi_line_example_from_bug_report(): void {
        // Second problem message from the bug report: contact message whose
        // translation spans two lines.
        $log = implode( "\n", [
            '[2025-02-27, Thursday, 07:56:00 UTC] Agent: إي نعم، تنجم تبدا من هنا: https://example.com/5/',
            'تحب نعاونك تلقى فصل معيّن؟ [Translation: Yes, you can start from here: https://example.com/5/',
            'Do you want me to help you find a specific chapter?]',
        ] );

        $html = $this->viewer->expose_render( $log, 'Respond.io' );

        $this->assertSame( 1, substr_count( $html, 'bg-blue-500' ), 'Three-line agent entry must produce one bubble.' );
        $this->assertStringContainsString( 'Do you want me', $html, 'Continuation text must appear in the bubble.' );
        $this->assertSame( 0, substr_count( $html, 'self-center text-xs text-gray-400' ), 'No continuation line should become a system note.' );
    }

    public function test_multiple_entries_each_get_their_own_bubble(): void {
        $log = implode( "\n", [
            '[2025-05-26, Monday, 10:00:00 UTC] Contact: First message',
            '[2025-05-26, Monday, 10:01:00 UTC] Agent: Second message',
        ] );

        $html = $this->viewer->expose_render( $log, 'Respond.io' );

        $this->assertSame( 1, substr_count( $html, 'bg-blue-500' ), 'One agent bubble expected.' );
        $this->assertSame( 1, substr_count( $html, 'bg-white' ), 'One contact bubble expected.' );
    }

    public function test_clean_single_line_entry_is_unaffected_by_reassembly(): void {
        // Entries written by the fixed importer are already on one line; the
        // reassembly pass must not corrupt them.
        $log = '[2025-05-26, Monday, 10:00:00 UTC] Agent: Start here: https://example.com/ [Translation: Start here: https://example.com/]';

        $html = $this->viewer->expose_render( $log, 'Respond.io' );

        $this->assertSame( 1, substr_count( $html, 'bg-blue-500' ), 'A single-line entry must produce exactly one bubble.' );
        $this->assertStringContainsString( 'https://example.com/', $html );
        $this->assertStringContainsString( '[Translation:', $html );
    }

// --- HTML escaping ---

    public function test_message_content_is_html_escaped(): void {
        $log = '[2025-05-26, Monday, 10:00:00 UTC] Agent: <script>alert(1)</script>';

        $html = $this->viewer->expose_render( $log, 'Respond.io' );

        $this->assertStringNotContainsString( '<script>', $html, 'Raw <script> tag must not appear in output.' );
        $this->assertStringContainsString( '&lt;script&gt;', $html, 'Script tag must be HTML-encoded.' );
    }

    public function test_sender_name_is_html_escaped(): void {
        $log = '[2025-05-26, Monday, 10:00:00 UTC] A&B: Hello';

        $html = $this->viewer->expose_render( $log, 'Respond.io' );

        $this->assertStringNotContainsString( 'A&B', $html );
        $this->assertStringContainsString( 'A&amp;B', $html );
    }

// --- connector label as agent sender ---

    public function test_connector_label_treated_as_agent(): void {
        $log = '[2025-05-26, Monday, 10:00:00 UTC] Respond.io: Automated message';

        $html = $this->viewer->expose_render( $log, 'Respond.io' );

        $this->assertStringContainsString( 'bg-blue-500', $html, 'A message from the connector label should render as an agent bubble.' );
    }
}
