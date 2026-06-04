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

        // Keeps the last failure reason without changing the return type of sideload().
        // Callers that care about diagnostics can read it via get_last_error().
        private ?string $last_error = null;

        /**
         * Returns the failure reason from the most recent sideload() call, or null
         * when the last call succeeded (or sideload() hasn't been called yet).
         */
        public function get_last_error(): ?string {
            return $this->last_error;
        }

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
            $this->last_error = null;

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
                // Host not in the allowlist — blocked to prevent SSRF.
                $this->last_error = 'ssrf_blocked: host "' . $host . '" not in allowlist';
                return $url;
            }

            // Already in the library — skip the download.
            $existing_id = $this->find_existing_attachment( $url, $post_id );
            if ( $existing_id > 0 ) {
                $local = wp_get_attachment_url( $existing_id );
                return $local ? (string) $local : $url;
            }

            $image_exts = [ 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tiff' ];
            $ext        = strtolower(
                pathinfo( (string) wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION )
            );
            $is_image   = in_array( $ext, $image_exts, true );

            if ( $is_image ) {
                // Return 'id' so we can record the source URL without an extra lookup.
                $attachment_id = media_sideload_image( $url, $post_id, null, 'id' );
                if ( is_wp_error( $attachment_id ) ) {
                    $this->last_error = 'image_wp_error: ' . $attachment_id->get_error_message();
                    return $url;
                }
                $attachment_id = (int) $attachment_id;
                $saved = update_post_meta( $attachment_id, '_dt_crm_sync_source_url', $url );
                if ( false === $saved && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( sprintf(
                        '[DT CRM Sync] Sideloader: could not save source URL meta (attachment %d). The same URL may be re-downloaded on next sync.',
                        $attachment_id
                    ) );
                }
                $local = wp_get_attachment_url( $attachment_id );
                return $local ? (string) $local : $url;
            }

            // Non-image (PDF, audio, video, etc.): download to temp file then register.
            $tmp_file = download_url( $url, 300 );
            if ( is_wp_error( $tmp_file ) ) {
                $this->last_error = 'download_failed: ' . $tmp_file->get_error_message();
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
                $this->last_error = 'attach_wp_error: ' . $attachment_id->get_error_message();
                return $url;
            }

            $saved = update_post_meta( $attachment_id, '_dt_crm_sync_source_url', $url );
            if ( false === $saved && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( sprintf(
                    '[DT CRM Sync] Sideloader: could not save source URL meta (attachment %d). The same URL may be re-downloaded on next sync.',
                    $attachment_id
                ) );
            }

            $local = wp_get_attachment_url( $attachment_id );
            return $local ? (string) $local : $url;
        }

        /**
         * Look up an attachment for this contact that was previously sideloaded from $url.
         *
         * Scoped to $post_id so contacts don't share attachment posts — ownership
         * stays clear and deleting one contact's media doesn't affect another's.
         */
        private function find_existing_attachment( string $url, int $post_id ): int {
            $results = get_posts( [
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'post_parent'    => $post_id,
                'meta_key'       => '_dt_crm_sync_source_url',
                'meta_value'     => $url,
                'posts_per_page' => 1,
                'fields'         => 'ids',
            ] );

            return ! empty( $results ) ? (int) $results[0] : 0;
        }
    }
}
