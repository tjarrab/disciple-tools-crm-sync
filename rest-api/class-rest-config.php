<?php
/**
 * REST controller for configuration and schema inspection routes.
 *
 * Routes:
 *   POST /test-connection
 *   GET  /schema
 *   GET  /dt-fields
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Disciple_Tools_CRM_Sync_REST_Config' ) ) {
    /**
     * Handles connection testing, CRM field schema retrieval, and DT field settings.
     *
     * @package Disciple_Tools
     */
    class Disciple_Tools_CRM_Sync_REST_Config extends Disciple_Tools_CRM_Sync_Abstract_REST_Controller {

// Route registration

        /**
         * Register configuration and schema routes.
         */
        public function register_routes(): void {
            $ns   = self::API_NAMESPACE;
            $perm = [ $this, 'has_permission' ];

            register_rest_route( $ns, '/test-connection', [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'handle_test_connection' ],
                'permission_callback' => $perm,
            ] );

            register_rest_route( $ns, '/schema', [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'handle_get_schema' ],
                'permission_callback' => $perm,
            ] );

            register_rest_route( $ns, '/dt-fields', [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'handle_get_dt_fields' ],
                'permission_callback' => $perm,
            ] );
        }

// Route handlers

        /**
         * POST /test-connection
         * Proxy to Disciple_Tools_CRM_Sync_API_Client::test_connection().
         * Returns HTTP 400 if no connector is configured, HTTP 502 if the
         * upstream API call fails, HTTP 200 on success.
         *
         * @return WP_REST_Response
         */
        public function handle_test_connection( WP_REST_Request $request ): WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- WordPress REST API callback signature.
            $connector = $this->get_active_connector();
            if ( is_wp_error( $connector ) ) {
                return new WP_REST_Response(
                    [
                        'success' => false,
                        'message' => $connector->get_error_message(),
                        'debug'   => [ 'code' => $connector->get_error_code(), 'data' => $connector->get_error_data() ],
                    ],
                    400
                );
            }

            $result = $connector->test_connection();
            if ( is_wp_error( $result ) ) {
                return new WP_REST_Response(
                    [
                        'success' => false,
                        'message' => $result->get_error_message(),
                        'debug'   => [ 'code' => $result->get_error_code(), 'data' => $result->get_error_data() ],
                    ],
                    502
                );
            }

            return new WP_REST_Response( [ 'success' => true ], 200 );
        }

        /**
         * GET /schema
         * Get field schemas from cache.
         * Pass ?refresh=1 to bust the transient and force a live fetch.
         * On a forced refresh, performs schema-drift detection: any field that
         * existed in the saved field mapping but is no longer present in the
         * live schema is flagged with 'broken' => true so Tab 1 can warn the
         * administrator to re-map it.
         *
         * @return WP_REST_Response
         */
        public function handle_get_schema( WP_REST_Request $request ): WP_REST_Response {
            $is_refresh = ( (int) $request->get_param( 'refresh' ) === 1 );

            // Return the cached schema without requiring valid credentials, unless
            // the caller is forcing a refresh (cache miss path also falls through).
            if ( ! $is_refresh ) {
                $cached = get_transient( Disciple_Tools_CRM_Sync_API_Client::FIELD_SCHEMA_TRANSIENT );
                if ( false !== $cached ) {
                    return new WP_REST_Response( $cached, 200 );
                }
            }

            // Cache miss or forced refresh — credentials are required from here.
            $connector = $this->get_active_connector();
            if ( is_wp_error( $connector ) ) {
                return new WP_REST_Response(
                    [ 'error' => $connector->get_error_message() ],
                    400
                );
            }

            if ( $is_refresh ) {
                // Rate-limit forced refreshes to prevent repeated outbound API calls.
                // A transient acts as a 60-second cooldown lock per WordPress instance.
                if ( false !== get_transient( 'dt_crm_sync_schema_refresh_lock' ) ) {
                    $response = new WP_REST_Response(
                        [ 'error' => __( 'Schema refresh is rate-limited. Please wait before refreshing again.', 'disciple-tools-crm-sync' ) ],
                        429
                    );
                    $response->header( 'Retry-After', '60' );
                    return $response;
                }
                set_transient( 'dt_crm_sync_schema_refresh_lock', 1, 60 );

                $connector->refresh_schema_cache();
            }

            $schema = $connector->get_field_schema();
            if ( is_wp_error( $schema ) ) {
                return new WP_REST_Response(
                    [ 'error' => $schema->get_error_message() ],
                    502
                );
            }

// Schema-drift detection (only on forced refresh)
            if ( $is_refresh ) {
                $this->detect_schema_drift( $schema );
                // Return a { success: true } envelope so the admin JS can detect
                // success with `data.success`. The full schema is already written
                // to the transient by get_field_schema(); the page reload triggered
                // by the JS will re-render the mapping table from the transient.
                return new WP_REST_Response( [ 'success' => true ], 200 );
            }

            return new WP_REST_Response( $schema, 200 );
        }

        /**
         * Compare the fresh schema against the stored field mapping and flag
         * any mapping entry whose Respond.io field no longer exists.
         *
         * The mapping is stored as a PHP array keyed by sanitize_key(field_name):
         *   [ 'field_key' => [ 'dt_key' => '...', 'dt_type' => '...' ], ... ]
         *
         * A 'broken' => true flag is added when the Respond.io field is absent;
         * the flag is cleared when the field reappears (e.g. after a rename-back).
         *
         * @param array $schema Fresh schema response from the API.
         */
        protected function detect_schema_drift( array $schema ): void {
            $mapping = get_option( 'dt_crm_sync_field_mapping', [] );
            if ( empty( $mapping ) || ! is_array( $mapping ) ) {
                return;
            }

            // Normalise schema shape — matches render_field_mapping() in admin tab.
            if ( isset( $schema['data'] ) && is_array( $schema['data'] ) ) {
                $schema_fields = $schema['data'];
            } else {
                $schema_fields = is_array( $schema ) ? $schema : [];
            }

            // Build a set of sanitized field name keys present in the live schema.
            // Use 'slug' (the API identifier) to match the key used by render_field_mapping()
            // and by the importer when reading contact custom_fields.
            $live_keys = [];
            foreach ( $schema_fields as $field ) {
                $key = sanitize_key( $field['slug'] ?? $field['name'] ?? '' );
                if ( '' !== $key ) {
                    $live_keys[ $key ] = true;
                }
            }

            $changed = false;
            foreach ( $mapping as $respond_key => &$entry ) {
                if ( isset( $live_keys[ $respond_key ] ) ) {
                    // Field is present — clear any stale broken flag.
                    if ( ! empty( $entry['broken'] ) ) {
                        unset( $entry['broken'] );
                        $changed = true;
                    }
                } else {
                    // Field is missing from the live schema — flag as broken.
                    if ( empty( $entry['broken'] ) ) {
                        $entry['broken'] = true;
                        $changed         = true;
                    }
                }
            }
            unset( $entry ); // break reference

            if ( $changed ) {
                update_option( 'dt_crm_sync_field_mapping', $mapping );
            }
        }

        /**
         * GET /dt-fields
         * Returns the full DT contact field settings array.
         * Used by the React SPA to populate the field-mapping UI.
         * No API call required.
         *
         * @return WP_REST_Response
         */
        public function handle_get_dt_fields( WP_REST_Request $request ): WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- WordPress REST API callback signature.
            $settings = DT_Posts::get_post_settings( 'contacts' );
            return new WP_REST_Response( $settings['fields'] ?? [], 200 );
        }
    }
}
