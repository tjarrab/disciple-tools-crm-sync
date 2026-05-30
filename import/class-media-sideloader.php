<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Disciple_Tools_CRM_Sync_Media_Sideloader' ) ) {
    /**
     * Downloads a CRM attachment URL and stores it permanently in the WP Media Library.
     *
     * @package Disciple_Tools
     */
    class Disciple_Tools_CRM_Sync_Media_Sideloader {

        /**
         * Download a media URL and store it permanently in the WP Media Library.
         *
         * Returns the permanent local URL on success, or the original URL on failure
         * so the caller can still produce a working (if possibly expiring) link.
         *
         * @param string $url     The remote media URL to sideload.
         * @param int    $post_id DT contact post to attach the media to.
         * @return string Local permanent URL on success, original URL as fallback.
         */
        public function sideload( string $url, int $post_id ): string {
            if ( empty( $url ) ) {
                return $url;
            }

            // SSRF guard: only sideload from known Respond.io CDN hosts.
            // Extend the allowlist via the filter hook if your account uses different domains.
            $allowed_hosts = apply_filters(
                'dt_crm_sync_sideload_allowed_hosts',
                [ 'cdn.respond.io', 'storage.respond.io' ]
            );
            $allowed_hosts = array_values( array_filter( array_map( 'strtolower', $allowed_hosts ), 'strlen' ) );
            $host          = (string) wp_parse_url( $url, PHP_URL_HOST );
            if ( '' === $host || ! in_array( strtolower( $host ), $allowed_hosts, true ) ) {
                return $url; // Skip sideload; fall back to original URL.
            }

            $image_exts = [ 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tiff' ];
            $ext        = strtolower(
                pathinfo( (string) wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION )
            );
            $is_image   = in_array( $ext, $image_exts, true );

            if ( $is_image ) {
                // media_sideload_image() with 'src' returns the permanent attachment URL.
                $local = media_sideload_image( $url, $post_id, null, 'src' );
                return is_wp_error( $local ) ? $url : (string) $local;
            }

            // Non-image (PDF, audio, video, etc.): download to temp file then register.
            $tmp_file = download_url( $url, 300 );
            if ( is_wp_error( $tmp_file ) ) {
                return $url;
            }

            $file_array = [
                'name'     => ( static function ( string $path ): string {
                    $name = sanitize_file_name( basename( $path ) );
                    return $name ?: 'attachment';
                } )( (string) wp_parse_url( $url, PHP_URL_PATH ) ),
                'tmp_name' => $tmp_file,
            ];

            // media_handle_sideload() handles wp_insert_attachment() + metadata in one call.
            $attachment_id = media_handle_sideload( $file_array, $post_id );

            // download_url() writes to a temp file; clean up regardless of outcome.
            if ( file_exists( $tmp_file ) ) {
                wp_delete_file( $tmp_file );
            }

            if ( is_wp_error( $attachment_id ) ) {
                return $url;
            }

            $local = wp_get_attachment_url( $attachment_id );
            return $local ? (string) $local : $url;
        }
    }
}
