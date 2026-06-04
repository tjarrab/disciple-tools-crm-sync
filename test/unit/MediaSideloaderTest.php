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
        Functions\when( 'get_posts' )->justReturn( [] );
        // media_sideload_image() now returns an attachment ID (int) when called with 'id'.
        Functions\when( 'media_sideload_image' )->justReturn( 9 );
        Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://local.test/wp-content/uploads/img.jpg' );

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
        Functions\when( 'get_posts' )->justReturn( [] );
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
        Functions\when( 'get_posts' )->justReturn( [] );
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

        Functions\when( 'get_posts' )->justReturn( [] );
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

// Deduplication — re-sync must not re-download an already-sideloaded URL

    public function test_sideload_returns_existing_attachment_url_without_downloading(): void {
        Functions\when( 'apply_filters' )->alias(
            fn( $hook, $value ) => 'dt_crm_sync_sideload_allowed_hosts' === $hook
                ? [ 'cdn.respond.io' ]
                : $value
        );
        Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
        // Pretend this URL was sideloaded before — attachment 7 is already in the library.
        Functions\when( 'get_posts' )->justReturn( [ 7 ] );
        Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://local.test/wp-content/uploads/img.jpg' );
        Functions\expect( 'media_sideload_image' )->never();
        Functions\expect( 'download_url' )->never();

        $result = $this->sideloader->sideload( 'https://cdn.respond.io/img.jpg', 1 );

        $this->assertSame(
            'https://local.test/wp-content/uploads/img.jpg',
            $result,
            'An already-sideloaded URL must return the existing attachment URL.'
        );
    }

    public function test_sideload_saves_source_url_meta_after_image_sideload(): void {
        $url = 'https://cdn.respond.io/photo.jpg';

        Functions\when( 'apply_filters' )->alias(
            fn( $hook, $value ) => 'dt_crm_sync_sideload_allowed_hosts' === $hook
                ? [ 'cdn.respond.io' ]
                : $value
        );
        Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
        Functions\when( 'get_posts' )->justReturn( [] );
        Functions\when( 'media_sideload_image' )->justReturn( 42 );
        Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://local.test/wp-content/uploads/photo.jpg' );

        Functions\expect( 'update_post_meta' )
            ->once()
            ->with( 42, '_dt_crm_sync_source_url', $url )
            ->andReturn( true );

        $this->sideloader->sideload( $url, 1 );
    }

    public function test_sideload_saves_source_url_meta_after_non_image_sideload(): void {
        $url = 'https://cdn.respond.io/doc.pdf';

        Functions\when( 'apply_filters' )->alias(
            fn( $hook, $value ) => 'dt_crm_sync_sideload_allowed_hosts' === $hook
                ? [ 'cdn.respond.io' ]
                : $value
        );
        Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
        Functions\when( 'get_posts' )->justReturn( [] );
        Functions\when( 'download_url' )->justReturn( '/nonexistent/tmp/doc.pdf' );
        Functions\when( 'media_handle_sideload' )->justReturn( 55 );
        Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://local.test/wp-content/uploads/doc.pdf' );

        Functions\expect( 'update_post_meta' )
            ->once()
            ->with( 55, '_dt_crm_sync_source_url', $url )
            ->andReturn( true );

        $this->sideloader->sideload( $url, 1 );
    }
}
