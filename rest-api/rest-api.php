<?php
/**
 * REST API coordinator for Disciple.Tools - CRM Sync.
 *
 * Bootstraps the disciple-tools-crm-sync/v1 namespace and delegates route
 * registration to resource-specific controllers:
 *
 *   Disciple_Tools_CRM_Sync_REST_Config    — /test-connection, /schema, /dt-fields
 *   Disciple_Tools_CRM_Sync_REST_Contacts  — /contacts, /import
 *   Disciple_Tools_CRM_Sync_REST_Filters   — /saved-filters (CRUD + run)
 *
 * All internal routes are protected by has_permission() (defined in the abstract
 * base). The webhook endpoint (/webhook) lives in webhook/webhook-listener.php
 * and uses __return_true with HMAC authentication instead.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/abstract-rest-controller.php';
require_once __DIR__ . '/class-rest-config.php';
require_once __DIR__ . '/class-rest-contacts.php';
require_once __DIR__ . '/class-rest-filters.php';
require_once __DIR__ . '/class-rest-message-viewer.php';
require_once __DIR__ . '/class-rest-translation.php';

if ( ! class_exists( 'Disciple_Tools_CRM_Sync_REST' ) ) {
    /**
     * REST API coordinator for the plugin under the disciple-tools-crm-sync/v1 namespace.
     *
     * @package Disciple_Tools
     */
    class Disciple_Tools_CRM_Sync_REST {

        private static ?self $instance = null;

        private ?Disciple_Tools_CRM_Sync_REST_Config $config         = null;
        private ?Disciple_Tools_CRM_Sync_REST_Contacts $contacts       = null;
        private ?Disciple_Tools_CRM_Sync_REST_Filters $filters         = null;
        private ?Disciple_Tools_CRM_Sync_REST_Message_Viewer $message_viewer = null;
        private ?Disciple_Tools_CRM_Sync_REST_Translation $translation  = null;

        /**
         * Returns the singleton instance, creating it on first call.
         *
         * @return self
         */
        public static function instance(): self {
            if ( is_null( self::$instance ) ) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        /**
         * Registers all REST routes via the rest_api_init hook.
         */
        private function __construct() {
            add_action( 'rest_api_init', [ $this, 'add_api_routes' ] );
        }

        /**
         * Instantiate each resource controller and register its routes.
         */
        public function add_api_routes(): void {
            $this->config         = new Disciple_Tools_CRM_Sync_REST_Config();
            $this->contacts       = new Disciple_Tools_CRM_Sync_REST_Contacts();
            $this->filters        = new Disciple_Tools_CRM_Sync_REST_Filters();
            $this->message_viewer = new Disciple_Tools_CRM_Sync_REST_Message_Viewer();
            $this->translation    = new Disciple_Tools_CRM_Sync_REST_Translation();

            $this->config->register_routes();
            $this->contacts->register_routes();
            $this->filters->register_routes();
            $this->message_viewer->register_routes();
            $this->translation->register_routes();
        }
    }
}
