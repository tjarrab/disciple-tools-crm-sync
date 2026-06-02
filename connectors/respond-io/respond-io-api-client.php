<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Disciple_Tools_CRM_Sync_API_Client' ) ) {
    /**
     * HTTP client for the Respond.io v2 REST API.
     *
     * Covers authentication, cursor pagination, transient-based schema caching,
     * and rate-limit error normalisation. Not a singleton.
     *
     * @package Disciple_Tools
     */
    class Disciple_Tools_CRM_Sync_API_Client {

        /** @var string Base URL, e.g. 'https://api.respond.io' */
        private string $base_url;

        /** @var string Decrypted Bearer token (plaintext). */
        private string $token;

        /** @var int wp_remote_request() timeout in seconds. */
        private const TIMEOUT = 30;

        /** Transient key for the cached custom field schema. */
        public const FIELD_SCHEMA_TRANSIENT = 'dt_crm_sync_field_schema_respond_io';

        /**
         * @param string $base_url Respond.io API base URL (no trailing slash).
         * @param string $token    Decrypted plaintext Bearer token.
         */
        public function __construct( string $base_url, string $token ) {
            $this->base_url = rtrim( $base_url, '/' );
            $this->token    = $token;
        }

// Core request helper

        /**
         * Make an authenticated request to the Respond.io API.
         *
         * @param string  $method       HTTP method ('GET', 'POST', 'DELETE', etc.)
         * @param string  $path         API path, e.g. '/v2/space/user'
         * @param array   $query_params URL query string parameters (key => value).
         * @param array   $body         JSON body for POST/PUT/PATCH calls.
         * @return array|WP_Error       Decoded response body on success, WP_Error on failure.
         */
        private function request(
            string $method,
            string $path,
            array $query_params = [],
            array $body = []
        ): array|WP_Error {
            $url = $this->base_url . $path;

            if ( ! empty( $query_params ) ) {
                $url = add_query_arg( $query_params, $url );
            }

            $headers = [
                'Authorization' => 'Bearer ' . $this->token,
                'Accept'        => 'application/json',
            ];

            $args = [
                'method'  => strtoupper( $method ),
                'headers' => $headers,
                'timeout' => self::TIMEOUT,
            ];

            if ( ! empty( $body ) ) {
                $args['headers']['Content-Type'] = 'application/json';
                $args['body']                    = wp_json_encode( $body );
            }

            $response = wp_safe_remote_request( $url, $args );

            if ( is_wp_error( $response ) ) {
                return $response;
            }

            $status_code   = (int) wp_remote_retrieve_response_code( $response );
            $response_body = wp_remote_retrieve_body( $response );
            $decoded       = json_decode( $response_body, true );

            if ( 429 === $status_code ) {
                $retry_after = (int) wp_remote_retrieve_header( $response, 'retry-after' );
                return new WP_Error(
                    'rate_limited',
                    __( 'Respond.io API rate limit reached.', 'disciple-tools-crm-sync' ),
                    [ 'retry_after' => min( $retry_after > 0 ? $retry_after : 60, 3600 ) ]
                );
            }

            if ( 449 === $status_code ) {
                return new WP_Error(
                    'resource_pending',
                    __( 'Respond.io resource is still being created. Retry in a few minutes.', 'disciple-tools-crm-sync' )
                );
            }

            if ( $status_code < 200 || $status_code >= 300 ) {
                $message = sprintf(
                    /* translators: 1: HTTP status code, 2: error message from API */
                    __( 'HTTP %1$d: %2$s', 'disciple-tools-crm-sync' ),
                    $status_code,
                    isset( $decoded['message'] ) ? $decoded['message'] : substr( trim( $response_body ), 0, 500 )
                );
                return new WP_Error(
                    'api_error',
                    $message,
                    [ 'status_code' => $status_code, 'body' => $response_body ]
                );
            }

            if ( ! is_array( $decoded ) ) {
                if ( ! empty( trim( $response_body ) ) ) {
                    return new WP_Error(
                        'invalid_response',
                        __( 'Respond.io API returned malformed JSON.', 'disciple-tools-crm-sync' ),
                        [ 'body' => substr( $response_body, 0, 500 ) ]
                    );
                }
                return [];
            }

            return $decoded;
        }

// Public API methods

        /**
         * Verify credentials by fetching the space user list (1 result only).
         * Endpoint: GET /v2/space/user?limit=1
         *
         * @return true|WP_Error
         */
        public function test_connection(): bool|WP_Error {
            $result = $this->request( 'GET', '/v2/space/user', [ 'limit' => 1 ] );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
            return true;
        }

        /**
         * Fetch the Respond.io custom field schema.
         * Endpoint: GET /v2/space/custom_field
         * Result cached in a transient for 12 hours.
         *
         * @return array|WP_Error
         */
        public function get_field_schema(): array|WP_Error {
            $cached = get_transient( self::FIELD_SCHEMA_TRANSIENT );
            if ( false !== $cached && is_array( $cached ) ) {
                return $cached;
            }

            $result = $this->request( 'GET', '/v2/space/custom_field' );
            if ( is_wp_error( $result ) ) {
                return $result;
            }

            // Normalise: the API returns { items: [...], pagination: {...} } for list endpoints.
            // Store and return a flat array of field objects so all consumers receive a
            // consistent shape — the same normalisation pattern used by get_contacts().
            $fields = isset( $result['items'] ) && is_array( $result['items'] )
                ? $result['items']
                : ( is_array( $result ) ? $result : [] );

            set_transient( self::FIELD_SCHEMA_TRANSIENT, $fields, 12 * HOUR_IN_SECONDS );
            return $fields;
        }

        /**
         * Clear the cached custom field schema, forcing a live fetch on the next call.
         * Called by the "Refresh Schema" UI action.
         */
        public function refresh_schema_cache(): void {
            delete_transient( self::FIELD_SCHEMA_TRANSIENT );
        }

        /**
         * Fetch a paginated list of contacts using the filter body.
         * Endpoint: POST /v2/contact/list
         *
         * API body shape (per Respond.io v2 docs):
         *   { "search": "", "filter": { "$and": [] }, "timezone": "..." }
         *
         * Pagination params (cursorId, limit) go as URL query string params.
         * The API response uses 'items' (not 'data') and returns a
         * 'pagination.next' full URL instead of a raw cursor ID.
         * This method normalises both so callers receive the stable shape:
         *   { data: [...], cursor: { next: int|null } }
         *
         * @param array       $filter_body  Filter criteria. Recognised keys: 'search', 'timezone'.
         * @param string|null $cursor_id    Cursor ID for the next page, or null for the first page.
         * @param int         $limit        Page size (1–99, default 50).
         * @return array|WP_Error
         */
        public function get_contacts(
            array $filter_body,
            ?string $cursor_id = null,
            int $limit = 50
        ): array|WP_Error {
            // Ensure timezone is always present and is an IANA name.
            // wp_timezone_string() can return a UTC offset string (e.g. +05:30)
            // in some WP configurations; Respond.io requires an IANA identifier.
            if ( empty( $filter_body['timezone'] ) ) {
                $tz = wp_timezone_string();
                if ( preg_match( '/^[+-]/', $tz ) ) {
                    $tz = wp_timezone()->getName();
                }
                $filter_body['timezone'] = $tz;
            }

            // The connector is responsible for building the full filter.$and
            // condition array (including tag filters) before calling this method.
            // The API client passes it through as-is.
            // An empty array means "no additional filters" (return all contacts).
            $body = [
                'search'   => $filter_body['search'] ?? '',
                'filter'   => [ '$and' => $filter_body['filter']['$and'] ?? [] ],
                'timezone' => $filter_body['timezone'],
            ];

            $query_params = [ 'limit' => $limit ];
            if ( ! empty( $cursor_id ) ) {
                $query_params['cursorId'] = $cursor_id;
            }

            $raw = $this->request( 'POST', '/v2/contact/list', $query_params, $body );
            if ( is_wp_error( $raw ) ) {
                return $raw;
            }

            // Normalise response keys:
            //   API returns 'items'            → callers expect 'data'
            //   API returns 'pagination.next'  → callers expect 'cursor.next' (int ID)
            // Extract the cursorId integer from the 'pagination.next' full URL.
            $next_cursor = null;
            $next_url    = $raw['pagination']['next'] ?? null;
            if ( ! empty( $next_url ) ) {
                $query_string = wp_parse_url( $next_url, PHP_URL_QUERY );
                if ( $query_string ) {
                    parse_str( $query_string, $parsed );
                    $raw_cursor  = $parsed['cursorId'] ?? null;
                    $next_cursor = is_numeric( $raw_cursor ) ? (int) $raw_cursor : null;
                }
            }

            return [
                'data'   => $raw['items'] ?? [],
                'cursor' => [ 'next' => $next_cursor ],
            ];
        }

        /**
         * Fetch a single contact's full profile.
         * Endpoint: GET /v2/contact/id:{respond_id}
         *
         * @param string $respond_id Raw numeric ID string (e.g. '123'). Do NOT include 'id:' prefix.
         * @return array|WP_Error
         */
        public function get_contact( string $respond_id ): array|WP_Error {
            return $this->request( 'GET', '/v2/contact/id:' . rawurlencode( $respond_id ) );
        }

        /**
         * Fetch the channels (social platforms) connected to a contact.
         * Endpoint: GET /v2/contact/id:{respond_id}/channels
         *
         * Each item in the response carries a 'source' field identifying the
         * platform (e.g. 'facebook', 'tiktok', 'instagram'). We pull all channels
         * in a single request — contacts rarely have more than a handful.
         *
         * @param string $respond_id Raw numeric ID string.
         * @return array|WP_Error Normalised: { data: [ { source: string, ... }, ... ] }
         */
        public function get_contact_channels( string $respond_id ): array|\WP_Error {
            $raw = $this->request(
                'GET',
                '/v2/contact/id:' . rawurlencode( $respond_id ) . '/channels',
                [ 'limit' => 100 ]
            );
            if ( is_wp_error( $raw ) ) {
                return $raw;
            }

            return [
                'data' => $raw['items'] ?? [],
            ];
        }

        /**
         * Fetch the message list for a contact.
         * Endpoint: GET /v2/contact/id:{respond_id}/message/list
         *
         * The API response uses 'items' (not 'data') and returns a 'pagination.next'
         * full URL instead of a raw cursor ID. This method normalises both so callers
         * receive the stable shape: { data: [...], cursor: { next: int|null } }
         *
         * @param string      $respond_id Raw numeric ID string.
         * @param string|null $cursor_id  Cursor ID for the next page, or null for the first page.
         * @param int         $limit      Page size (max 100).
         * @return array|WP_Error
         */
        public function get_message_list(
            string $respond_id,
            ?string $cursor_id = null,
            int $limit = 100
        ): array|WP_Error {
            $query_params = [ 'limit' => $limit ];
            if ( ! empty( $cursor_id ) ) {
                $query_params['cursorId'] = $cursor_id;
            }

            $raw = $this->request(
                'GET',
                '/v2/contact/id:' . rawurlencode( $respond_id ) . '/message/list',
                $query_params
            );
            if ( is_wp_error( $raw ) ) {
                return $raw;
            }

            // Normalise response keys (same pattern as get_contacts()):
            //   API returns 'items'            → callers expect 'data'
            //   API returns 'pagination.next'  → callers expect 'cursor.next' (int ID)
            $next_cursor = null;
            $next_url    = $raw['pagination']['next'] ?? null;
            if ( ! empty( $next_url ) ) {
                $query_string = wp_parse_url( $next_url, PHP_URL_QUERY );
                if ( $query_string ) {
                    parse_str( $query_string, $parsed );
                    $raw_cursor  = $parsed['cursorId'] ?? null;
                    $next_cursor = is_numeric( $raw_cursor ) ? (int) $raw_cursor : null;
                }
            }

            return [
                'data'   => $raw['items'] ?? [],
                'cursor' => [ 'next' => $next_cursor ],
            ];
        }
    }
}
