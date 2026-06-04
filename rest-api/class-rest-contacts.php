<?php
/**
 * REST controller for contact browsing and import routes.
 *
 * Routes:
 *   POST /contacts
 *   POST /import
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Disciple_Tools_CRM_Sync_REST_Contacts' ) ) {
    /**
     * Handles CRM contact browsing (proxied from the connector) and batch import scheduling.
     *
     * @package Disciple_Tools
     */
    class Disciple_Tools_CRM_Sync_REST_Contacts extends Disciple_Tools_CRM_Sync_Abstract_REST_Controller {

// Route registration

        /**
         * Register contact browsing and import routes.
         */
        public function register_routes(): void {
            $ns   = self::API_NAMESPACE;
            $perm = [ $this, 'has_permission' ];

            register_rest_route( $ns, '/contacts', [
                'methods'             => 'GET, POST',
                'callback'            => [ $this, 'handle_get_contacts' ],
                'permission_callback' => $perm,
            ] );

            register_rest_route( $ns, '/import', [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'handle_import' ],
                'permission_callback' => $perm,
            ] );
        }

// Route handlers

        /**
         * POST /contacts
         * Proxy to Disciple_Tools_CRM_Sync_API_Client::get_contacts().
         * The request body is the Respond.io filter body (JSON).
         * Pagination: ?cursorId=... and ?limit=... as query params.
         *
         * @return WP_REST_Response
         */
        public function handle_get_contacts( WP_REST_Request $request ): WP_REST_Response {
            $connector = $this->get_active_connector();
            if ( is_wp_error( $connector ) ) {
                return new WP_REST_Response(
                    [ 'error' => $connector->get_error_message() ],
                    400
                );
            }

            // The filter_params are forwarded to the connector and do not enter
            // the WP database directly. This endpoint is gated by a capability
            // check (dt_crm_sync_import). A nonce is only present when the user
            // authenticates via cookie + X-WP-Nonce; application-password and
            // OAuth clients do not send nonces. Sanitizing here would silently
            // corrupt non-ASCII characters and special-character search terms.
            $body          = is_array( $request->get_json_params() ) ? $request->get_json_params() : [];
            $filter_params = isset( $body['filter_params'] ) && is_array( $body['filter_params'] ) ? $body['filter_params'] : $body;

            $raw_cursor = $request->get_param( 'cursorId' );
            $cursor_id  = ( is_string( $raw_cursor ) && ctype_digit( $raw_cursor ) ) ? $raw_cursor : null;

            $limit = min( 100, max( 1, absint( $request->get_param( 'limit' ) ?: 50 ) ) );

            $result = $connector->get_contacts( $filter_params, $cursor_id, $limit );
            if ( is_wp_error( $result ) ) {
                return new WP_REST_Response(
                    [ 'error' => $result->get_error_message() ],
                    502
                );
            }

            return new WP_REST_Response( $result, 200 );
        }

        /**
         * POST /import
         * Accepts { ids: int[] } and schedules one WP-Cron batch per 25 IDs.
         * Import task queued.
         *
         * @return WP_REST_Response
         */
        public function handle_import( WP_REST_Request $request ): WP_REST_Response {
            $params        = $request->get_json_params() ?? [];
            $raw_ids       = $params['ids'] ?? null;
            $skip_existing = isset( $params['skip_existing'] ) ? (bool) $params['skip_existing'] : true;

            if ( ! is_array( $raw_ids ) || empty( $raw_ids ) ) {
                return new WP_REST_Response(
                    [ 'error' => __( 'ids must be a non-empty array of integers.', 'disciple-tools-crm-sync' ) ],
                    400
                );
            }

            $ids = array_values( array_filter( array_map( 'absint', $raw_ids ) ) );

            if ( empty( $ids ) ) {
                return new WP_REST_Response(
                    [ 'error' => __( 'No valid positive integer IDs were provided.', 'disciple-tools-crm-sync' ) ],
                    400
                );
            }

            // Cap at 500 IDs per request to prevent flooding the cron queue.
            if ( count( $ids ) > 500 ) {
                return new WP_REST_Response(
                    [ 'error' => __( 'A maximum of 500 IDs may be submitted per request.', 'disciple-tools-crm-sync' ) ],
                    400
                );
            }

            $chunks = array_chunk( $ids, 25 );
            $queued = 0;

            // Stagger events by 3 seconds per chunk to avoid concurrent cron spikes.
            foreach ( $chunks as $i => $chunk ) {
                $scheduled = wp_schedule_single_event(
                    time() + 5 + ( $i * 3 ),
                    'dt_crm_sync_process_batch',
                    [ [ 'ids' => $chunk, '_token' => bin2hex( random_bytes( 8 ) ), '_trigger' => 'manual', '_skip_existing' => $skip_existing ] ]
                );
                if ( false !== $scheduled ) {
                    $queued++;
                }
            }

            if ( 0 === $queued ) {
                return new WP_REST_Response(
                    [ 'error' => __( 'Import could not be queued — no batches were scheduled. Please try again.', 'disciple-tools-crm-sync' ) ],
                    500
                );
            }

            return new WP_REST_Response(
                [ 'status' => 'queued', 'batches' => $queued ],
                200
            );
        }
    }
}
