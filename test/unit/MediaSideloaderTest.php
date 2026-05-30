<?php
/**
 * Unit tests for Disciple_Tools_CRM_Sync_Media_Sideloader.
 *
 * Covers SSRF guard, image routing, non-image routing, and failure fallbacks.
 */

use Brain\Monkey\Functions;

class MediaSideloaderTest extends BrainMonkeyTestCase {

    private Disciple_Tools_CRM_Sync_Media_Sideloader $sideloader;

    protected function setUp(): void {
        parent::setUp();
        $this->sideloader = new Disciple_Tools_CRM_Sync_Media_Sideloader();
    }

    public function test_sideload_unchanged_on_empty(): void {
        $result = $this->sideloader->sideload( '', 1 );
        $this->assertSame( '', $result );
    }

    public function test_sideload_rejects_non_allowlisted_host(): void {
        Functions\when( 'apply_filters' )->alias(
            fn( $hook, $value ) => 'dt_crm_sync_sideload_allowed_hosts' === $hook
                ? [ 'cdn.respond.io', 'storage.respond.io' ]
                : $value
        );
        Functions\when( 'wp_parse_url' )->alias( 'parse_url' );

        $url    = 'https://evil.example.com/file.jpg';
        $result = $this->sideloader->sideload( $url, 1 );

        $this->assertSame( $url, $result, 'Non-allowlisted host should be returned unchanged.' );
    }

    public function test_sideload_routes_image_url_to_media_sideload_image(): void {
        Functions\when( 'apply_filters' )->alias(
            fn( $hook, $value ) => 'dt_crm_sync_sideload_allowed_hosts' === $hook
                ? [ 'cdn.respond.io', 'storage.respond.io' ]
                : $value
        );
        Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
        Functions\when( 'media_sideload_image' )->justReturn( 'https://local.test/wp-content/uploads/img.jpg' );

        $result = $this->sideloader->sideload( 'https://cdn.respond.io/img.jpg', 1 );

        $this->assertSame( 'https://local.test/wp-content/uploads/img.jpg', $result );
    }

    public function test_sideload_fallback_on_failure(): void {
        Functions\when( 'apply_filters' )->alias(
            fn( $hook, $value ) => 'dt_crm_sync_sideload_allowed_hosts' === $hook
                ? [ 'cdn.respond.io', 'storage.respond.io' ]
                : $value
        );
        Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
        Functions\when( 'media_sideload_image' )->justReturn( new WP_Error( 'sideload_failed', 'Could not sideload' ) );

        $url    = 'https://cdn.respond.io/img.png';
        $result = $this->sideloader->sideload( $url, 1 );

        $this->assertSame( $url, $result, 'Original URL must be returned when media_sideload_image fails.' );
    }

    public function test_sideload_routes_non_image_through_download_url(): void {
        Functions\when( 'apply_filters' )->alias(
            fn( $hook, $value ) => 'dt_crm_sync_sideload_allowed_hosts' === $hook
                ? [ 'cdn.respond.io', 'storage.respond.io' ]
                : $value
        );
        Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
        Functions\when( 'download_url' )->justReturn( '/nonexistent/tmp/file.pdf' );
        Functions\when( 'media_handle_sideload' )->justReturn( 9 );
        Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://local.test/wp-content/uploads/file.pdf' );

        $result = $this->sideloader->sideload( 'https://cdn.respond.io/doc.pdf', 1 );

        $this->assertSame( 'https://local.test/wp-content/uploads/file.pdf', $result );
    }

// Filename fallback when URL path has no basename (fix 3.18)

    public function test_sideload_attachment_fallback_filename(): void {
        Functions\when( 'apply_filters' )->alias(
            fn( $hook, $value ) => 'dt_crm_sync_sideload_allowed_hosts' === $hook
                ? [ 'cdn.respond.io' ]
                : $value
        );

        // Return the host for the SSRF guard, and a root path for the filename closure.
        Functions\when( 'wp_parse_url' )->alias(
            function ( $url, $component = -1 ) {
                if ( PHP_URL_HOST === $component ) {
                    return 'cdn.respond.io';
                }
                if ( PHP_URL_PATH === $component ) {
                    return '/'; // Root path → basename('') → sanitize_file_name('') → 'attachment' fallback.
                }
                return parse_url( $url, $component );
            }
        );

        Functions\when( 'download_url' )->justReturn( '/tmp/fake_nonexistent_file' );

        $captured_name = null;
        Functions\when( 'media_handle_sideload' )->alias(
            function ( $file_array, $post_id ) use ( &$captured_name ) {
                $captured_name = $file_array['name'];
                return 42;
            }
        );

        Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://local.test/wp-content/uploads/attachment' );

        $this->sideloader->sideload( 'https://cdn.respond.io/', 1 );

        $this->assertSame(
            'attachment',
            $captured_name,
            'An empty URL path must fall back to the filename "attachment".'
        );
    }
}
