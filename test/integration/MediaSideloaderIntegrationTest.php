<?php
/**
 * Integration tests for Disciple_Tools_CRM_Sync_Media_Sideloader.
 *
 * These tests exercise the SSRF host-allowlist guard and, where the GD
 * extension is available and a real HTTP client can be used, verify the
 * end-to-end sideload pipeline.
 *
 * Run with: php vendor/bin/phpunit -c phpunit.xml.dist --testdox
 */

class MediaSideloaderIntegrationTest extends TestCase {

    private Disciple_Tools_CRM_Sync_Media_Sideloader $sideloader;

    protected function setUp(): void {
        parent::setUp();
        $this->sideloader = new Disciple_Tools_CRM_Sync_Media_Sideloader();
    }

// SSRF guard

    /**
     * A URL whose host is not on the default allowlist must be returned
     * unchanged — no HTTP request or media library write is performed.
     */
    public function test_ssrf_guard_blocks_disallowed_host(): void {
        $url = 'https://malicious.example.com/secret.jpg';

        // No filter override — use the default allowlist (cdn.respond.io, storage.respond.io).
        $result = $this->sideloader->sideload( $url, 0 );

        $this->assertSame(
            $url,
            $result,
            'A URL from a disallowed host must be returned unchanged (SSRF guard).'
        );
    }

    /**
     * An empty URL must be returned unchanged without attempting any network call.
     */
    public function test_empty_url_unchanged(): void {
        $result = $this->sideloader->sideload( '', 1 );
        $this->assertSame( '', $result );
    }

    /**
     * A URL from an explicitly allowlisted custom host must be accepted by
     * the SSRF guard and passed to the sideload pipeline.
     *
     * This test uses WP's pre_http_request filter to intercept the HTTP call
     * so no outbound network request is made.
     */
    public function test_allowlisted_host_passes_ssrf_guard(): void {
        // Allowlist a local test domain via the filter.
        add_filter( 'dt_crm_sync_sideload_allowed_hosts', static function ( array $hosts ): array {
            $hosts[] = 'media.test';
            return $hosts;
        } );

        // Intercept the HTTP pipeline and count calls so we can prove the SSRF
        // guard passed the URL through (rather than returning early before any HTTP call).
        $http_calls = 0;
        add_filter( 'pre_http_request', static function ( $pre, array $args, string $url ) use ( &$http_calls ) {
            ++$http_calls;
            return new WP_Error( 'test_intercept', 'Test HTTP intercept.' );
        }, 10, 3 );

        $url    = 'https://media.test/image.jpg';
        $result = $this->sideloader->sideload( $url, 1 );

        // The HTTP pipeline must have been invoked — proving the SSRF guard let
        // the allowlisted host through rather than returning the URL immediately.
        $this->assertGreaterThan(
            0,
            $http_calls,
            'pre_http_request filter must fire: SSRF guard must not have blocked the allowlisted host.'
        );
        // After HTTP failure the sideloader falls back to the original URL.
        $this->assertSame( $url, $result, 'Original URL must be returned as fallback when sideload fails.' );

        // Cleanup.
        remove_all_filters( 'dt_crm_sync_sideload_allowed_hosts' );
        remove_all_filters( 'pre_http_request' );
    }

// Sideload pipeline (requires HTTP mock)

    /**
     * When media_sideload_image() returns a WP_Error the original URL must
     * be returned as a fallback.
     *
     * Uses WP's pre_http_request filter to simulate a failed download.
     */
    public function test_failed_image_sideload_falls_back(): void {
        add_filter( 'dt_crm_sync_sideload_allowed_hosts', static function ( array $hosts ): array {
            $hosts[] = 'cdn.test.respond.io';
            return $hosts;
        } );

        add_filter( 'pre_http_request', static function () {
            return new WP_Error( 'http_request_failed', 'Simulated network failure.' );
        } );

        $url    = 'https://cdn.test.respond.io/photo.jpg';
        $result = $this->sideloader->sideload( $url, 1 );

        $this->assertSame( $url, $result, 'Original URL must be returned when sideload fails.' );

        remove_all_filters( 'dt_crm_sync_sideload_allowed_hosts' );
        remove_all_filters( 'pre_http_request' );
    }

    /**
     * When download_url() fails for a non-image attachment the original URL
     * must be returned as a fallback.
     */
    public function test_failed_nonimage_download_falls_back(): void {
        add_filter( 'dt_crm_sync_sideload_allowed_hosts', static function ( array $hosts ): array {
            $hosts[] = 'cdn.test.respond.io';
            return $hosts;
        } );

        add_filter( 'pre_http_request', static function () {
            return new WP_Error( 'http_request_failed', 'Simulated network failure.' );
        } );

        $url    = 'https://cdn.test.respond.io/document.pdf';
        $result = $this->sideloader->sideload( $url, 1 );

        $this->assertSame( $url, $result, 'Original URL must be returned when download_url() fails for PDF.' );

        remove_all_filters( 'dt_crm_sync_sideload_allowed_hosts' );
        remove_all_filters( 'pre_http_request' );
    }

// Deduplication

    /**
     * Sideloading a URL that was already processed in a previous run must
     * return the existing attachment URL without making any HTTP request.
     *
     * This covers the re-sync scenario where the same contact is imported
     * more than once — each attachment must only end up in the Media Library
     * once.
     */
    public function test_second_sideload_of_same_url_skips_download(): void {
        $url     = 'https://cdn.test.respond.io/already-sideloaded.jpg';
        $post_id = 1;

        add_filter( 'dt_crm_sync_sideload_allowed_hosts', static function ( array $hosts ): array {
            $hosts[] = 'cdn.test.respond.io';
            return $hosts;
        } );

        // Simulate a previous successful sideload: insert a stub attachment and
        // tag it with its source URL so the dedup check can find it.
        $attachment_id = wp_insert_post( [
            'post_type'   => 'attachment',
            'post_status' => 'inherit',
            'post_parent' => $post_id,
            'post_title'  => 'already-sideloaded.jpg',
            'guid'        => 'https://local.test/wp-content/uploads/already-sideloaded.jpg',
        ] );
        update_post_meta( $attachment_id, '_dt_crm_sync_source_url', $url );

        $http_calls = 0;
        add_filter( 'pre_http_request', static function ( $pre, $args, $req_url ) use ( &$http_calls ) {
            ++$http_calls;
            return new WP_Error( 'should_not_reach', 'No HTTP calls expected for an already-sideloaded URL.' );
        }, 10, 3 );

        $result = $this->sideloader->sideload( $url, $post_id );

        $this->assertSame(
            0,
            $http_calls,
            'No HTTP request must be made when the URL is already in the Media Library.'
        );
        $this->assertSame(
            wp_get_attachment_url( $attachment_id ),
            $result,
            'The existing local attachment URL must be returned instead of the original CDN URL.'
        );

        // Cleanup.
        wp_delete_attachment( $attachment_id, true );
        remove_all_filters( 'dt_crm_sync_sideload_allowed_hosts' );
        remove_all_filters( 'pre_http_request' );
    }
}
