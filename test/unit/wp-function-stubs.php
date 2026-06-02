<?php
/**
 * Runtime WP function stubs — defined in a separate file so Patchwork's
 * stream-wrapper intercepts this include and marks every function below as
 * re-definable.  If these stubs were inlined in bootstrap.php, the stream
 * wrapper would never see them (bootstrap.php is loaded by PHPUnit before the
 * wrapper is registered) and Brain Monkey would raise
 * Patchwork\Exceptions\DefinedTooEarly whenever a test calls Functions\when().
 *
 * Require this file AFTER the Composer autoloader (which registers the
 * Patchwork stream wrapper) but BEFORE any test class is instantiated.
 */

if ( ! function_exists( 'wp_parse_url' ) ) {
    function wp_parse_url( $url, $component = -1 ): mixed { return parse_url( $url, $component ); } // phpcs:ignore
}
if ( ! function_exists( 'wp_timezone' ) ) {
    function wp_timezone() { // phpcs:ignore
        return new DateTimeZone( 'UTC' );
    }
}
if ( ! function_exists( 'get_comments' ) ) {
    function get_comments( $args = [] ): mixed { return 0; } // phpcs:ignore
}
if ( ! function_exists( 'current_user_can' ) ) {
    function current_user_can( $capability ): bool { return false; } // phpcs:ignore
}
if ( ! function_exists( 'register_rest_route' ) ) {
    function register_rest_route( $namespace, $route, $args = [], $override = false ): bool { return true; } // phpcs:ignore
}
if ( ! function_exists( 'media_sideload_image' ) ) {
    function media_sideload_image( $url, $post_id, $desc = null, $return = 'html' ): mixed { return ''; } // phpcs:ignore
}
if ( ! function_exists( 'media_handle_sideload' ) ) {
    function media_handle_sideload( $file_array, $post_id, $desc = null, $post_data = [] ): mixed { return 0; } // phpcs:ignore
}
if ( ! function_exists( 'download_url' ) ) {
    function download_url( $url, $timeout = 300, $signature_verification = false ): mixed { return ''; } // phpcs:ignore
}
if ( ! function_exists( 'wp_delete_file' ) ) {
    function wp_delete_file( $file ): void {} // phpcs:ignore
}
if ( ! function_exists( 'wp_get_attachment_url' ) ) {
    function wp_get_attachment_url( $post_id ): string|false { return ''; } // phpcs:ignore
}

// Admin / output-escape stubs (needed by admin tab classes)

if ( ! function_exists( 'esc_attr' ) ) {
    function esc_attr( $text ): string { return htmlspecialchars( (string) $text, ENT_QUOTES ); } // phpcs:ignore
}
if ( ! function_exists( 'esc_url_raw' ) ) {
    function esc_url_raw( $url ): string { return (string) $url; } // phpcs:ignore
}
if ( ! function_exists( 'esc_js' ) ) {
    function esc_js( $text ): string { return addslashes( (string) $text ); } // phpcs:ignore
}
if ( ! function_exists( 'esc_html__' ) ) {
    function esc_html__( $text, $domain = 'default' ): string { return htmlspecialchars( (string) $text, ENT_QUOTES ); } // phpcs:ignore
}
if ( ! function_exists( 'esc_attr__' ) ) {
    function esc_attr__( $text, $domain = 'default' ): string { return htmlspecialchars( (string) $text, ENT_QUOTES ); } // phpcs:ignore
}
if ( ! function_exists( 'esc_html_e' ) ) {
    function esc_html_e( $text, $domain = 'default' ): void { echo htmlspecialchars( (string) $text, ENT_QUOTES ); } // phpcs:ignore
}
if ( ! function_exists( 'esc_attr_e' ) ) {
    function esc_attr_e( $text, $domain = 'default' ): void { echo htmlspecialchars( (string) $text, ENT_QUOTES ); } // phpcs:ignore
}
if ( ! function_exists( 'wp_nonce_field' ) ) {
    function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $echo = true ): string { return ''; } // phpcs:ignore
}
if ( ! function_exists( 'submit_button' ) ) {
    function submit_button( $text = null, $type = 'primary', $name = 'submit', $wrap = true, $other_attributes = null ): void {} // phpcs:ignore
}
if ( ! function_exists( 'checked' ) ) {
    function checked( $checked, $current = true, $echo = true ): string { return ''; } // phpcs:ignore
}
if ( ! function_exists( 'selected' ) ) {
    function selected( $selected, $current = true, $echo = true ): string { return ''; } // phpcs:ignore
}
if ( ! function_exists( 'admin_url' ) ) {
    function admin_url( $path = '', $scheme = 'admin' ): string { return '/wp-admin/' . ltrim( $path, '/' ); } // phpcs:ignore
}
if ( ! function_exists( 'add_query_arg' ) ) {
    function add_query_arg( ...$args ): string { return '/wp-admin/admin.php'; } // phpcs:ignore
}
if ( ! function_exists( 'wp_date' ) ) {
    function wp_date( $format, $timestamp = null, $timezone = null ): string|false { return date( $format, $timestamp ?? time() ); } // phpcs:ignore
}
if ( ! function_exists( 'wp_next_scheduled' ) ) {
    function wp_next_scheduled( $hook, $args = [] ): int|false { return false; } // phpcs:ignore
}
if ( ! function_exists( 'wp_schedule_event' ) ) {
    function wp_schedule_event( $timestamp, $recurrence, $hook, $args = [], $wp_error = false ): bool|WP_Error { return true; } // phpcs:ignore
}
if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
    function wp_clear_scheduled_hook( $hook, $args = [], $wp_error = false ): int|false|WP_Error { return false; } // phpcs:ignore
}
if ( ! function_exists( 'wp_schedule_single_event' ) ) {
    function wp_schedule_single_event( $timestamp, $hook, $args = [], $wp_error = false ): bool|WP_Error { return true; } // phpcs:ignore
}
if ( ! function_exists( 'get_transient' ) ) {
    function get_transient( $transient ): mixed { return false; } // phpcs:ignore
}
if ( ! function_exists( 'set_transient' ) ) {
    function set_transient( $transient, $value, $expiration = 0 ): bool { return true; } // phpcs:ignore
}
if ( ! function_exists( 'delete_transient' ) ) {
    function delete_transient( $transient ): bool { return true; } // phpcs:ignore
}
if ( ! function_exists( 'sanitize_file_name' ) ) {
    function sanitize_file_name( $filename ): string { return basename( (string) $filename ); } // phpcs:ignore
}
if ( ! function_exists( 'absint' ) ) {
    function absint( $maybeint ): int { return abs( (int) $maybeint ); } // phpcs:ignore
}
if ( ! function_exists( 'wp_die' ) ) {
    function wp_die( $message = '', $title = '', $args = [] ): void {} // phpcs:ignore
}
if ( ! function_exists( 'dt_recursive_sanitize_array' ) ) {
    function dt_recursive_sanitize_array( array $array ): array { return $array; } // phpcs:ignore
}
if ( ! function_exists( 'sanitize_key' ) ) {
    function sanitize_key( $key ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ); } // phpcs:ignore
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( $str ): string { return strip_tags( (string) $str ); } // phpcs:ignore
}
if ( ! function_exists( 'wp_unslash' ) ) {
    function wp_unslash( $value ): mixed { return is_array( $value ) ? array_map( 'stripslashes', $value ) : stripslashes( (string) $value ); } // phpcs:ignore
}
if ( ! function_exists( 'wp_hash' ) ) {
    function wp_hash( $data, $scheme = 'auth' ): string { return hash_hmac( 'md5', $data, 'test_salt_' . $scheme ); } // phpcs:ignore
}
if ( ! function_exists( 'rest_url' ) ) {
    function rest_url( $path = '' ): string { return 'http://example.com/wp-json/' . ltrim( (string) $path, '/' ); } // phpcs:ignore
}
if ( ! function_exists( 'wp_create_nonce' ) ) {
    function wp_create_nonce( $action = -1 ): string { return 'test_nonce'; } // phpcs:ignore
}
if ( ! function_exists( '_get_cron_array' ) ) {
    function _get_cron_array(): array { return []; } // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
}
if ( ! function_exists( '_set_cron_array' ) ) {
    function _set_cron_array( array $cron ): void {} // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
}
if ( ! function_exists( '_n' ) ) {
    function _n( string $single, string $plural, int $number, string $domain = 'default' ): string { return 1 === $number ? $single : $plural; } // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
}
if ( ! defined( 'OBJECT' ) ) {
    define( 'OBJECT', 'OBJECT' );
}
if ( ! function_exists( 'get_comment' ) ) {
    function get_comment( $id, $output = OBJECT, $filter = 'raw' ): mixed { return null; } // phpcs:ignore
}
if ( ! function_exists( 'wp_update_comment' ) ) {
    function wp_update_comment( array $commentarr, bool $wp_error = false ): int|bool|\WP_Error { return 1; } // phpcs:ignore
}
if ( ! function_exists( 'get_post_meta' ) ) {
    function get_post_meta( $post_id, string $key = '', bool $single = false ): mixed { return $single ? '' : []; } // phpcs:ignore
}
if ( ! function_exists( 'update_post_meta' ) ) {
    function update_post_meta( $post_id, string $meta_key, mixed $meta_value, mixed $prev_value = '' ): int|bool { return true; } // phpcs:ignore
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
    function wp_strip_all_tags( string $text, bool $remove_breaks = false ): string { // phpcs:ignore
        $text = strip_tags( $text );
        if ( $remove_breaks ) {
            $text = (string) preg_replace( '/[\r\n\t ]+/', ' ', $text );
        }
        return trim( $text );
    }
}
