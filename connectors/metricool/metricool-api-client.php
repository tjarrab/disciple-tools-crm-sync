<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Disciple_Tools_CRM_Sync_Metricool_API_Client' ) ) {
    /**
     * HTTP client for the Metricool REST API. Auth uses three query parameters
     * (userToken, userId, blogId) on every request. Conversation responses are
     * cached in a short-lived transient to avoid redundant requests within the
     * same import session.
     *
     * @package Disciple_Tools
     */
    class Disciple_Tools_CRM_Sync_Metricool_API_Client {

        /** @var string Metricool API base URL (no trailing slash). */
        private const BASE_URL = 'https://app.metricool.com/api';

        /** @var int wp_remote_request() timeout in seconds. */
        private const TIMEOUT = 30;

        /** @var int Conversation list transient TTL in seconds (5 minutes). */
        private const CACHE_TTL = 5 * MINUTE_IN_SECONDS;

        /** @var string Plaintext user token. */
        private string $user_token;

        /** @var string Metricool user ID. */
        private string $user_id;

        /** @var string Metricool blog / brand ID. */
        private string $blog_id;

        /** @var string Social platform provider string (e.g. 'INSTAGRAM'). */
        private string $provider;

        /**
         * @param string $user_token Plaintext Metricool user token.
         * @param string $user_id    Metricool user ID.
         * @param string $blog_id    Metricool blog / brand ID.
         * @param string $provider   Social platform provider (e.g. 'INSTAGRAM').
         */
        public function __construct(
            string $user_token,
            string $user_id,
            string $blog_id,
            string $provider
        ) {
            $this->user_token = $user_token;
            $this->user_id    = $user_id;
            $this->blog_id    = $blog_id;
            $this->provider   = $provider;
        }

// Core request helper

        /**
         * Make an authenticated GET request to the Metricool API.
         *
         * The three auth query params (`userToken`, `userId`, `blogId`) are merged
         * into every request automatically. Additional query params may be passed
         * via `$extra_params`.
         *
         * @param string $path         API path, e.g. '/admin/simpleProfiles'.
         * @param array  $extra_params Additional URL query string parameters.
         * @return array|WP_Error Decoded response body on success, WP_Error on failure.
         */
        private function request( string $path, array $extra_params = [] ): array|\WP_Error {
            $auth_params = [
                'userToken' => $this->user_token,
                'userId'    => $this->user_id,
                'blogId'    => $this->blog_id,
            ];

            $query_params = array_merge( $extra_params, $auth_params );

            $url = add_query_arg( $query_params, self::BASE_URL . $path );

            $args = [
                'method'  => 'GET',
                'headers' => [ 'Accept' => 'application/json' ],
                'timeout' => self::TIMEOUT,
            ];

            $response = wp_safe_remote_request( $url, $args );

            if ( is_wp_error( $response ) ) {
                return $response;
            }

            $status_code   = (int) wp_remote_retrieve_response_code( $response );
            $response_body = wp_remote_retrieve_body( $response );
            $decoded       = json_decode( $response_body, true );

            if ( $status_code < 200 || $status_code >= 300 ) {
                $detail = '';
                if ( is_array( $decoded ) && ! empty( $decoded['message'] ) ) {
                    $detail = $decoded['message'];
                } else {
                    $detail = substr( trim( $response_body ), 0, 500 );
                }
                $message = sprintf(
                    /* translators: 1: HTTP status code 2: error detail */
                    __( 'HTTP %1$d: %2$s', 'disciple-tools-crm-sync' ),
                    $status_code,
                    $detail
                );
                return new \WP_Error(
                    'api_error',
                    $message,
                    [ 'status_code' => $status_code, 'body' => $response_body ]
                );
            }

            if ( ! is_array( $decoded ) ) {
                if ( ! empty( trim( $response_body ) ) ) {
                    return new \WP_Error(
                        'invalid_response',
                        __( 'Metricool API returned malformed JSON.', 'disciple-tools-crm-sync' ),
                        [ 'body' => substr( $response_body, 0, 500 ) ]
                    );
                }
                return [];
            }

            return $decoded;
        }

// Public API methods

        /**
         * Verify credentials by fetching the simple-profiles list.
         * Endpoint: GET /admin/simpleProfiles
         *
         * Success requires HTTP 200 AND a non-empty array. An empty array means the
         * credentials are syntactically valid but no brands are accessible.
         *
         * @return true|WP_Error
         */
        public function test_connection(): bool|\WP_Error {
            $result = $this->request( '/admin/simpleProfiles' );

            if ( is_wp_error( $result ) ) {
                return $result;
            }

            // The endpoint returns an array of PublicBlog objects.
            // An empty array means credentials are valid but no brands are available.
            if ( empty( $result ) ) {
                return new \WP_Error(
                    'no_brands_found',
                    __( 'Metricool connection succeeded but no brands were found. Check that the credentials belong to an account with at least one active brand.', 'disciple-tools-crm-sync' )
                );
            }

            return true;
        }

        /**
         * Fetch conversations for the configured provider.
         * Endpoint: GET /v2/inbox/conversations?provider={X}
         *
         * NOTE: The Metricool Swagger spec only documents `provider` as a supported
         * query parameter. Status filtering is NOT available server-side and must be
         * applied client-side by the connector after this method returns.
         *
         * Pagination: the response envelope includes `page.next` (a string). The exact
         * format of this cursor token is unconfirmed in the spec — the implementation
         * handles both a bare cursor string and a full URL (extracts the cursor from
         * the URL query string in the latter case). If `next` is null or empty, there
         * are no further pages.
         *
         * Results are cached in a transient for 5 minutes so that subsequent
         * `get_contact()` calls during the same import session avoid re-fetching.
         * The cache is keyed by (provider, user_id, blog_id).
         *
         * @param string|null $cursor Cursor token for the next page, or null for the first page.
         * @return array|WP_Error Normalised shape: `['data' => [...], 'cursor' => ['next' => string|null]]`
         */
        public function get_conversations( ?string $cursor = null ): array|\WP_Error {
            // Use the transient cache only for the first page (no cursor) so that
            // subsequent pages are always fetched fresh.
            $cache_key  = 'dt_crm_sync_conversations_metricool_' . md5( $this->provider . '.' . $this->user_id . '.' . $this->blog_id );
            $use_cache  = ( null === $cursor );

            if ( $use_cache ) {
                $cached = get_transient( $cache_key );
                if ( false !== $cached && is_array( $cached ) ) {
                    return $cached;
                }
            }

            $extra_params = [ 'provider' => $this->provider ];
            if ( ! empty( $cursor ) ) {
                // Append the cursor parameter. The exact param name is unconfirmed —
                // using 'cursor' as the most likely convention; verify against a live
                // account and update if the API uses a different name (e.g. 'after', 'page').
                $extra_params['cursor'] = $cursor;
            }

            $raw = $this->request( '/v2/inbox/conversations', $extra_params );

            if ( is_wp_error( $raw ) ) {
                return $raw;
            }

            // Normalise the JsonPaging.next value into a stable cursor string.
            // The spec returns `page.next` as a string. It may be:
            //   (a) a bare cursor token  → use directly
            //   (b) a full URL           → extract the cursor query param from it
            $next_cursor = null;
            $raw_next    = $raw['page']['next'] ?? null;
            if ( ! empty( $raw_next ) ) {
                if ( false !== strpos( $raw_next, '://' ) ) {
                    // Full URL — extract the cursor value from the query string.
                    $query_string = wp_parse_url( $raw_next, PHP_URL_QUERY );
                    if ( $query_string ) {
                        parse_str( $query_string, $parsed );
                        $next_cursor = $parsed['cursor'] ?? $parsed['after'] ?? $parsed['page'] ?? null;
                    }
                } else {
                    // Bare cursor token.
                    $next_cursor = $raw_next;
                }
            }

            $result = [
                'data'   => $raw['data'] ?? [],
                'cursor' => [ 'next' => $next_cursor ],
            ];

            if ( $use_cache ) {
                set_transient( $cache_key, $result, self::CACHE_TTL );
            }

            return $result;
        }

        /**
         * Delete the conversation list transient, forcing a live fetch on the next call.
         * Should be called at the start of a new import session via `get_contacts()`.
         */
        public function clear_conversation_cache(): void {
            $cache_key = 'dt_crm_sync_conversations_metricool_' . md5( $this->provider . '.' . $this->user_id . '.' . $this->blog_id );
            delete_transient( $cache_key );
        }
    }
}
