<?php
/**
 * REST controller for translation routes.
 *
 * Routes:
 *   DELETE /translation/models-cache
 *   POST   /translation/test
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Disciple_Tools_CRM_Sync_REST_Translation' ) ) {
    /**
     * Handles translation model cache management and connection testing.
     *
     * @package Disciple_Tools
     */
    class Disciple_Tools_CRM_Sync_REST_Translation extends Disciple_Tools_CRM_Sync_Abstract_REST_Controller {

// Route registration

        /**
         * Register translation routes.
         */
        public function register_routes(): void {
            $ns   = self::API_NAMESPACE;
            $perm = [ $this, 'has_permission' ];

            register_rest_route( $ns, '/translation/models-cache', [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [ $this, 'handle_delete_models_cache' ],
                'permission_callback' => $perm,
            ] );

            register_rest_route( $ns, '/translation/test', [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'handle_test_translation' ],
                'permission_callback' => $perm,
            ] );
        }

// Route handlers

        /**
         * DELETE /translation/models-cache
         * Clears the cached Gemini model list so the next settings page load
         * fetches a fresh list from the API.
         *
         * @param WP_REST_Request $request Unused.
         * @return WP_REST_Response
         */
        public function handle_delete_models_cache( WP_REST_Request $request ): WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- WordPress REST API callback signature.
            delete_transient( 'dt_crm_sync_gemini_models' );
            return new WP_REST_Response( [ 'success' => true ], 200 );
        }

        /**
         * POST /translation/test
         * Translates a fixed test phrase using the saved settings and returns
         * the result so the admin can verify the API key and model are working.
         *
         * @param WP_REST_Request $request Unused.
         * @return WP_REST_Response
         */
        public function handle_test_translation( WP_REST_Request $request ): WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- WordPress REST API callback signature.
            $settings = get_option( 'dt_crm_sync_translation_settings', [] );
            $api_key  = '';

            if ( ! empty( $settings['api_key'] ) ) {
                $decrypted = Disciple_Tools_CRM_Sync::decrypt_value( $settings['api_key'] );
                if ( false === $decrypted ) {
                    return new WP_REST_Response(
                        [ 'success' => false, 'message' => __( 'API key could not be decrypted. Please re-enter it.', 'disciple-tools-crm-sync' ) ],
                        400
                    );
                }
                $api_key = $decrypted;
            }

            if ( empty( $api_key ) ) {
                return new WP_REST_Response(
                    [ 'success' => false, 'message' => __( 'No API key saved. Enter and save a Gemini API key first.', 'disciple-tools-crm-sync' ) ],
                    400
                );
            }

            $model = sanitize_text_field( $settings['model'] ?? '' );
            if ( empty( $model ) ) {
                return new WP_REST_Response(
                    [ 'success' => false, 'message' => __( 'No model selected. Save a model selection first.', 'disciple-tools-crm-sync' ) ],
                    400
                );
            }

            $prompt   = $settings['prompt'] ?? '';
            $provider = new Disciple_Tools_CRM_Sync_Gemini_Translation_Provider( $api_key, $model );
            $result   = $provider->translate_with_meta( 'Hola, ¿cómo estás?', $prompt );

            if ( is_wp_error( $result ) ) {
                return new WP_REST_Response(
                    [ 'success' => false, 'message' => $result->get_error_message() ],
                    502
                );
            }

            return new WP_REST_Response(
                [
                    'success'     => true,
                    'translation' => $result['translation'],
                    'http_status' => $result['http_status'],
                ],
                200
            );
        }
    }
}
