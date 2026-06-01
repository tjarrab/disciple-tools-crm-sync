<?php
/**
 * Admin menu registration and tab routing for Disciple.Tools - CRM Sync.
 *
 * Contains:
 *   Disciple_Tools_CRM_Sync_Menu — singleton; registers submenu, enqueues assets, routes tabs
 *
 * Tab classes are loaded from admin/tabs/:
 *   Disciple_Tools_CRM_Sync_Tab_Config      — admin/tabs/class-tab-config.php
 *   Disciple_Tools_CRM_Sync_Tab_Importer    — admin/tabs/class-tab-importer.php
 *   Disciple_Tools_CRM_Sync_Tab_Automations — admin/tabs/class-tab-automations.php
 *   Disciple_Tools_CRM_Sync_Tab_Logs        — admin/tabs/class-tab-logs.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/tabs/class-tab-config.php';
require_once __DIR__ . '/tabs/class-tab-importer.php';
require_once __DIR__ . '/tabs/class-tab-automations.php';
require_once __DIR__ . '/tabs/class-tab-logs.php';
require_once __DIR__ . '/tabs/class-tab-translation.php';

// Main menu class

if ( ! class_exists( 'Disciple_Tools_CRM_Sync_Menu' ) ) {
    /**
     * Registers the CRM Sync admin submenu and enqueues the React SPA asset.
     *
     * Singleton. Hooked to admin_menu and admin_enqueue_scripts. Routes tab
     * requests to the appropriate tab class via a switch on $_GET['tab'].
     *
     * @package Disciple_Tools
     */
    class Disciple_Tools_CRM_Sync_Menu {

        private static ?self $instance = null;

        /** @return self */
        public static function instance(): self {
            if ( is_null( self::$instance ) ) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        /** Register admin_menu and admin_enqueue_scripts hooks. */
        private function __construct() {
            add_action( 'admin_menu', [ $this, 'register_menu' ] );
            add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
        }

        /** Add the CRM Sync submenu page under the dt_extensions parent. */
        public function register_menu(): void {
            add_submenu_page(
                'dt_extensions',
                __( 'CRM Sync', 'disciple-tools-crm-sync' ),
                __( 'CRM Sync', 'disciple-tools-crm-sync' ),
                'manage_dt',
                'disciple-tools-crm-sync',
                [ $this, 'content' ]
            );
        }

        /**
         * Render the tab wrapper and delegate to the active tab class.
         *
         * Reads $_GET['tab'] for routing; defaults to 'config'. Verifies the
         * manage_dt capability before rendering anything.
         */
        public function content(): void {
            if ( ! current_user_can( 'manage_dt' ) ) {
                wp_die( esc_html__( 'You do not have permission to access this page.', 'disciple-tools-crm-sync' ) );
            }

            $tab       = sanitize_key( wp_unslash( $_GET['tab'] ?? 'config' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing parameter, not form submission.
            $page_base = 'admin.php?page=disciple-tools-crm-sync&tab=';
            ?>
            <div class="wrap dt-crm-sync-admin">
                <h1><?php esc_html_e( 'CRM Sync', 'disciple-tools-crm-sync' ); ?></h1>

                <nav class="nav-tab-wrapper">
                    <a href="<?php echo esc_url( admin_url( $page_base . 'config' ) ); ?>"
                        data-tab="tab-config"
                        class="nav-tab<?php echo 'config' === $tab ? ' nav-tab-active' : ''; ?>">
                        <?php esc_html_e( 'Configuration', 'disciple-tools-crm-sync' ); ?>
                    </a>
                    <a href="<?php echo esc_url( admin_url( $page_base . 'importer' ) ); ?>"
                        data-tab="tab-importer"
                        class="nav-tab<?php echo 'importer' === $tab ? ' nav-tab-active' : ''; ?>">
                        <?php esc_html_e( 'Manual Contact Import', 'disciple-tools-crm-sync' ); ?>
                    </a>
                    <a href="<?php echo esc_url( admin_url( $page_base . 'automations' ) ); ?>"
                        data-tab="tab-automations"
                        class="nav-tab<?php echo 'automations' === $tab ? ' nav-tab-active' : ''; ?>">
                        <?php esc_html_e( 'Automations', 'disciple-tools-crm-sync' ); ?>
                    </a>
                    <a href="<?php echo esc_url( admin_url( $page_base . 'translation' ) ); ?>"
                        data-tab="tab-translation"
                        class="nav-tab<?php echo 'translation' === $tab ? ' nav-tab-active' : ''; ?>">
                        <?php esc_html_e( 'Translation', 'disciple-tools-crm-sync' ); ?>
                    </a>
                    <a href="<?php echo esc_url( admin_url( $page_base . 'logs' ) ); ?>"
                        data-tab="tab-logs"
                        class="nav-tab<?php echo 'logs' === $tab ? ' nav-tab-active' : ''; ?>">
                        <?php esc_html_e( 'Sync Logs', 'disciple-tools-crm-sync' ); ?>
                    </a>
                </nav>

                <div style="margin-top: 20px;">
                    <div id="tab-config" class="dt-crm-sync-tab-panel"<?php echo 'config' !== $tab ? ' style="display:none"' : ''; ?>>
                        <?php ( new Disciple_Tools_CRM_Sync_Tab_Config() )->content(); ?>
                    </div>
                    <div id="tab-importer" class="dt-crm-sync-tab-panel"<?php echo 'importer' !== $tab ? ' style="display:none"' : ''; ?>>
                        <?php ( new Disciple_Tools_CRM_Sync_Tab_Importer() )->content(); ?>
                    </div>
                    <div id="tab-automations" class="dt-crm-sync-tab-panel"<?php echo 'automations' !== $tab ? ' style="display:none"' : ''; ?>>
                        <?php ( new Disciple_Tools_CRM_Sync_Tab_Automations() )->content(); ?>
                    </div>
                    <div id="tab-translation" class="dt-crm-sync-tab-panel"<?php echo 'translation' !== $tab ? ' style="display:none"' : ''; ?>>
                        <?php ( new Disciple_Tools_CRM_Sync_Tab_Translation() )->content(); ?>
                    </div>
                    <div id="tab-logs" class="dt-crm-sync-tab-panel"<?php echo 'logs' !== $tab ? ' style="display:none"' : ''; ?>>
                        <?php ( new Disciple_Tools_CRM_Sync_Tab_Logs() )->content(); ?>
                    </div>
                </div>
                <script>
                ( function () {
                    document.querySelectorAll( '.nav-tab-wrapper [data-tab]' ).forEach( function ( link ) {
                        link.addEventListener( 'click', function ( e ) {
                            e.preventDefault();
                            var tabId = this.getAttribute( 'data-tab' );
                            document.querySelectorAll( '.dt-crm-sync-tab-panel' ).forEach( function ( p ) {
                                p.style.display = 'none';
                            } );
                            document.getElementById( tabId ).style.display = '';
                            document.querySelectorAll( '.nav-tab-wrapper .nav-tab' ).forEach( function ( t ) {
                                t.classList.remove( 'nav-tab-active' );
                            } );
                            this.classList.add( 'nav-tab-active' );
                            history.pushState( null, '', this.getAttribute( 'href' ) );
                        } );
                    } );
                } )();
                </script>
            </div>
            <?php
        }

        /**
         * Enqueue the React SPA asset only on this plugin's admin page.
         * Falls back gracefully if the built JS file is not present.
         */
        public function enqueue_scripts( string $hook ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by wp_enqueue_scripts hook signature.
            if ( 'disciple-tools-crm-sync' !== sanitize_key( wp_unslash( $_GET['page'] ?? '' ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing parameter, not form submission.
                return;
            }

            // Always enqueue a lightweight handle so admin-button data is available
            // regardless of whether the full SPA bundle has been built.
            // false (not in footer) is required so wp_localize_script outputs
            // dtCrmSync into <head> before the inline IIFE in content() runs.
            wp_register_script(
                'dt-crm-sync-admin-data',
                false,
                [],
                false,
                false
            );
            wp_enqueue_script( 'dt-crm-sync-admin-data' );
            // Build connector-specific data for the JS layer.
            $active_connector = Disciple_Tools_CRM_Sync_Connector_Registry::get_active_connector();
            $connector_slug   = '';
            $connector_label  = '';
            $filter_fields    = [];
            if ( $active_connector ) {
                $connector_slug  = $active_connector->get_slug();
                $connector_label = $active_connector->get_label();
                $filter_fields   = $active_connector->get_filter_fields();
            }

            wp_localize_script(
                'dt-crm-sync-admin-data',
                'dtCrmSync',
                [
                    'apiRoot'        => esc_url_raw( rest_url( 'disciple-tools-crm-sync/v1' ) ),
                    'nonce'          => wp_create_nonce( 'wp_rest' ),
                    'siteUrl'        => esc_url_raw( site_url() ),
                    'logsUrl'        => esc_url( admin_url( 'admin.php?page=disciple-tools-crm-sync&tab=logs' ) ),
                    'pluginUrl'      => esc_url_raw( DT_CRM_SYNC_URL ),
                    'connectorSlug'  => $connector_slug,
                    'connectorLabel' => $connector_label,
                    'filterFields'   => $filter_fields,
                    'i18n'           => [
                        'testing'              => __( 'Testing…', 'disciple-tools-crm-sync' ),
                        'connectionSuccessful' => __( 'Connection successful', 'disciple-tools-crm-sync' ),
                        'connectionFailed'     => __( 'Connection failed', 'disciple-tools-crm-sync' ),
                        'requestError'         => __( 'Request error', 'disciple-tools-crm-sync' ),
                        'refreshing'           => __( 'Refreshing…', 'disciple-tools-crm-sync' ),
                        'doneReloading'        => __( 'Done — reloading…', 'disciple-tools-crm-sync' ),
                        'refreshFailed'        => __( 'Refresh failed', 'disciple-tools-crm-sync' ),
                        'queueing'             => __( 'Queueing…', 'disciple-tools-crm-sync' ),
                        'queued'               => __( 'Queued!', 'disciple-tools-crm-sync' ),
                        'failed'               => __( 'Failed', 'disciple-tools-crm-sync' ),
                        'deleteFilterConfirm'  => __( 'Delete this filter? This will stop all scheduled imports for it.', 'disciple-tools-crm-sync' ),
                    ],
                ]
            );

            // Admin stylesheet (status indicator classes used by tab JS).
            wp_enqueue_style(
                'dt-crm-sync-admin',
                DT_CRM_SYNC_URL . 'admin/css/admin.css',
                [],
                DT_CRM_SYNC_VERSION
            );

            // Tab-specific admin scripts (no build step required).
            wp_enqueue_script(
                'dt-crm-sync-tab-config',
                DT_CRM_SYNC_URL . 'admin/js/tab-config.js',
                [ 'dt-crm-sync-admin-data' ],
                DT_CRM_SYNC_VERSION,
                true
            );
            wp_enqueue_script(
                'dt-crm-sync-tab-automations',
                DT_CRM_SYNC_URL . 'admin/js/tab-automations.js',
                [ 'dt-crm-sync-admin-data' ],
                DT_CRM_SYNC_VERSION,
                true
            );
            wp_enqueue_script(
                'dt-crm-sync-tab-translation',
                DT_CRM_SYNC_URL . 'admin/js/tab-translation.js',
                [ 'dt-crm-sync-admin-data' ],
                DT_CRM_SYNC_VERSION,
                true
            );

            // Skip if the built file is not present on this install.
            $js_file = DT_CRM_SYNC_PATH . 'dist/importer-app.js';
            if ( ! file_exists( $js_file ) ) {
                return;
            }

            $asset_file = DT_CRM_SYNC_PATH . 'dist/importer-app.asset.php';
            $asset      = file_exists( $asset_file ) ? require $asset_file : [];
            $deps       = $asset['dependencies'] ?? [];
            $version    = $asset['version'] ?? DT_CRM_SYNC_VERSION;

            wp_enqueue_script(
                'dt-crm-sync-importer',
                DT_CRM_SYNC_URL . 'dist/importer-app.js',
                $deps,
                $version,
                true
            );
        }
    }
}
