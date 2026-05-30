<?php
/**
 * Abstract base class for REST API controllers in Disciple.Tools - CRM Sync.
 *
 * Provides the shared permission callback and connector helper used by all
 * resource-specific controllers. Subclasses implement register_routes() to
 * register their own endpoints.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Disciple_Tools_CRM_Sync_Abstract_REST_Controller' ) ) {
    /**
     * Base for all plugin REST controllers.
     *
     * Subclasses must implement register_routes() and may call
     * has_permission() and get_active_connector() freely.
     *
     * @package Disciple_Tools
     */
    abstract class Disciple_Tools_CRM_Sync_Abstract_REST_Controller {

        /** REST namespace shared by every plugin route. */
        const API_NAMESPACE = 'disciple-tools-crm-sync/v1';

        /**
         * Register routes for this controller.
         * Called by Disciple_Tools_CRM_Sync_REST::add_api_routes() inside the rest_api_init hook.
         */
        abstract public function register_routes(): void;

        /**
         * Permission callback for all internal routes.
         *
         * Checks only dt_crm_sync_import. The user_has_cap filter registered in
         * the main plugin class automatically grants this capability to any user
         * who holds manage_dt, so admins retain full access without widening the
         * check here to a broad OR condition.
         *
         * Returns a WP_Error with HTTP 403 (rather than a plain false) so that
         * both unauthenticated and authenticated-but-unauthorised callers receive
         * a consistent 403 Forbidden response instead of the default 401.
         *
         * @return true|\WP_Error
         */
        public function has_permission(): bool|\WP_Error {
            if ( ! current_user_can( 'dt_crm_sync_import' ) ) {
                return new \WP_Error(
                    'rest_forbidden',
                    __( 'Sorry, you are not allowed to do that.', 'disciple-tools-crm-sync' ),
                    [ 'status' => 403 ]
                );
            }
            return true;
        }

        /**
         * Return the active connector, or a WP_Error if not configured.
         *
         * @return Disciple_Tools_CRM_Sync_Abstract_Connector|WP_Error
         */
        protected function get_active_connector(): Disciple_Tools_CRM_Sync_Abstract_Connector|\WP_Error {
            $connector = Disciple_Tools_CRM_Sync_Connector_Registry::get_active_connector();

            if ( null === $connector ) {
                return new \WP_Error(
                    'missing_credentials',
                    __( 'No CRM connector is configured or credentials could not be decrypted. Please configure a connector on the Configuration tab.', 'disciple-tools-crm-sync' ),
                    [ 'status' => 400 ]
                );
            }

            return $connector;
        }
    }
}
