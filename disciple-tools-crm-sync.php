<?php
/**
 * Plugin Name:       Disciple.Tools - CRM Sync
 * Plugin URI:        https://github.com/tjarrab/disciple-tools-crm-sync
 * Author:            tjarrab
 * Author URI:        https://github.com/tjarrab
 * Description:       Imports and syncs contacts from CRM platforms into Disciple.Tools, with message history, webhook automation, and scheduled polling.
 * Version:           1.0.2
 * Text Domain:       disciple-tools-crm-sync
 * Domain Path:       /languages
 * GitHub Plugin URI: https://github.com/tjarrab/disciple-tools-crm-sync
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Tested up to:      6.7
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package Disciple_Tools
 * @link    https://github.com/tjarrab
 * @license GPL-2.0 or later
 *          https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Constants

define( 'DT_CRM_SYNC_PATH', plugin_dir_path( __FILE__ ) );
define( 'DT_CRM_SYNC_URL', plugin_dir_url( __FILE__ ) );
define( 'DT_CRM_SYNC_VERSION', '1.0.2' );

// Configuration (repo-specific values — edit config.php before release)
require_once plugin_dir_path( __FILE__ ) . 'config.php';

// dt_plugins registration

/**
 * Register this plugin's metadata in the dt_plugins filter.
 *
 * Version and name are read from the plugin file header so they stay in sync
 * automatically without manual updates here.
 *
 * @param array $plugins Existing registered DT plugins.
 * @return array
 */
function dt_crm_sync_register_plugin( array $plugins ): array {
    $data                               = get_file_data( __FILE__, [
        'Version' => 'Version',
        'Name'    => 'Plugin Name',
    ] );
    $plugins['disciple-tools-crm-sync'] = [
        'plugin_url' => trailingslashit( plugin_dir_url( __FILE__ ) ),
        'version'    => $data['Version'],
        'name'       => $data['Name'],
    ];
    return $plugins;
}
add_filter( 'dt_plugins', 'dt_crm_sync_register_plugin' );

// Plugin Update Checker

/**
 * Load the plugin update checker (PUC) library bundled with the DT theme.
 *
 * Only runs in admin and cron contexts, and skips entirely when the GitHub
 * org/repo constants still hold their placeholder values.
 */
function dt_crm_sync_load_puc(): void {
    if ( ( is_admin() || wp_doing_cron() ) && ! ( is_multisite() && class_exists( 'DT_Multisite' ) ) ) {
        // Skip update checker when GitHub org/repo are still placeholder values.
        if ( DT_CRM_SYNC_GITHUB_ORG === 'YourOrg' ) {
            return;
        }
        if ( ! class_exists( 'Puc_v4_Factory' ) ) {
            $puc_path = get_template_directory() . '/dt-core/libraries/plugin-update-checker/plugin-update-checker.php';
            if ( file_exists( $puc_path ) ) {
                require_once $puc_path;
            }
        }
        if ( class_exists( 'Puc_v4_Factory' ) ) {
            Puc_v4_Factory::buildUpdateChecker(
                'https://raw.githubusercontent.com/' . DT_CRM_SYNC_GITHUB_ORG . '/' . DT_CRM_SYNC_GITHUB_REPO . '/master/version-control.json',
                __FILE__,
                'disciple-tools-crm-sync'
            );
        }
    }
}
add_action( 'plugins_loaded', 'dt_crm_sync_load_puc' );

// Bootstrap

add_action( 'after_setup_theme', 'dt_crm_sync_plugin_bootstrap', 20 );

/**
 * Bootstrap callback — validate the DT theme requirement then return the singleton.
 *
 * Hooked to after_setup_theme at priority 20. Checks for the Disciple.Tools theme
 * and its minimum version. On failure it registers an admin notice and returns
 * false. Catches any PHP 8 Error/Exception at the top level so a bootstrap
 * failure doesn't take down the whole page.
 *
 * @return Disciple_Tools_CRM_Sync|false
 */
function dt_crm_sync_plugin_bootstrap() {
    $required_dt_version = '1.47';
    $is_theme_dt         = class_exists( 'Disciple_Tools' );
    $wp_theme            = wp_get_theme();
    $dt_version          = $wp_theme->version;

    if ( $is_theme_dt && version_compare( $dt_version, $required_dt_version, '<' ) ) {
        add_action( 'admin_notices', 'dt_crm_sync_hook_admin_notice' );
        add_action( 'wp_ajax_dismissed_notice_handler', 'dt_crm_sync_hook_ajax_notice_handler' );
        return false;
    }

    if ( ! $is_theme_dt ) {
        add_action( 'admin_notices', 'dt_crm_sync_hook_admin_notice' );
        add_action( 'wp_ajax_dismissed_notice_handler', 'dt_crm_sync_hook_ajax_notice_handler' );
        return false;
    }

    if ( ! defined( 'DT_FUNCTIONS_READY' ) ) {
        $global_functions = get_template_directory() . '/dt-core/global-functions.php';
        if ( file_exists( $global_functions ) ) {
            require_once $global_functions;
        }
    }

    try {
        return Disciple_Tools_CRM_Sync::instance();
    } catch ( \Throwable $e ) {
        // Catch any PHP 8 Error / Exception (TypeError, Error, RuntimeException, etc.)
        // so a bootstrap failure doesn't take down the whole site.
        $msg = $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
        error_log( 'disciple-tools-crm-sync bootstrap failed: ' . $msg ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged,WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging for bootstrap failures.
        add_action( 'admin_notices', function() use ( $msg ) {
            if ( ! current_user_can( 'manage_options' ) ) {
                return;
            }
            echo '<div class="notice notice-error"><p>';
            echo '<strong>Disciple.Tools – CRM Sync failed to load:</strong> ';
            echo esc_html( $msg );
            echo '</p></div>';
        } );
        return false;
    }
}

// Main Class

if ( ! class_exists( 'Disciple_Tools_CRM_Sync' ) ) :

/**
 * Main plugin class for Disciple.Tools - CRM Sync.
 *
 * Owns cron schedule registration, encryption helpers, the filter automation
 * lifecycle (create_filter / deactivation cleanup), and conditional subsystem
 * loading. Each subsystem (REST, webhook, admin, cron worker) is loaded only
 * in the request contexts where it is needed.
 *
 * @package Disciple_Tools
 */
    class Disciple_Tools_CRM_Sync {

        private static ?self $instance = null;

        /**
         * Get the shared instance.
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
         * Load all subsystems, conditionally per request context.
         *
         * The connector registry is loaded in every context so that DT source
         * slugs are always registered. Heavier subsystems (processor, REST, admin)
         * are loaded only where they are needed to keep the memory footprint small.
         */
        private function __construct() {
            $is_rest = dt_is_rest();

    // Always-on hooks (WP-Cron, REST, admin, front-end)

            $this->i18n();

            add_filter( 'cron_schedules', [ $this, 'add_cron_schedules' ] );

            add_filter( 'dt_set_roles_and_permissions', [ $this, 'set_roles_and_permissions' ], 20, 1 );

            // Grant dt_crm_sync_import to any user who already holds manage_dt so that
            // has_permission() in rest-api.php only needs to check one capability.
            add_filter( 'user_has_cap', [ $this, 'grant_import_cap_to_manage_dt' ], 10, 4 );

            add_filter( 'dt_custom_fields_settings', [ $this, 'register_connector_sources' ], 10, 2 );

            require_once DT_CRM_SYNC_PATH . 'import/class-logger.php';

    // Connector subsystem (loaded in all contexts)

            require_once DT_CRM_SYNC_PATH . 'connectors/abstract-connector.php';
            require_once DT_CRM_SYNC_PATH . 'connectors/connector-registry.php';
            require_once DT_CRM_SYNC_PATH . 'connectors/respond-io/respond-io-api-client.php';
            require_once DT_CRM_SYNC_PATH . 'connectors/respond-io/respond-io-connector.php';
            require_once DT_CRM_SYNC_PATH . 'connectors/metricool/metricool-api-client.php';
            require_once DT_CRM_SYNC_PATH . 'connectors/metricool/metricool-connector.php';

    // Translation subsystem (loaded in all contexts — pure class definitions, no side effects)

            require_once DT_CRM_SYNC_PATH . 'translation/abstract-translation-provider.php';
            require_once DT_CRM_SYNC_PATH . 'translation/gemini/gemini-translation-provider.php';
            require_once DT_CRM_SYNC_PATH . 'translation/class-translation-logger.php';
            require_once DT_CRM_SYNC_PATH . 'translation/class-translation-rate-limiter.php';
            require_once DT_CRM_SYNC_PATH . 'translation/class-translation-service.php';

    // Cron-only: processor and poll handler

            if ( wp_doing_cron() ) {
                require_once DT_CRM_SYNC_PATH . 'import/class-contact-matcher.php';
                require_once DT_CRM_SYNC_PATH . 'import/class-field-mapper.php';
                require_once DT_CRM_SYNC_PATH . 'import/class-media-sideloader.php';
                require_once DT_CRM_SYNC_PATH . 'import/class-message-importer.php';
                require_once DT_CRM_SYNC_PATH . 'import/class-activity-feed-writer.php';
                require_once DT_CRM_SYNC_PATH . 'import/import-processor.php';
                Disciple_Tools_CRM_Sync_Processor::instance();

                require_once DT_CRM_SYNC_PATH . 'import/poll-handler.php';
                add_action( 'dt_crm_sync_poll', [ $this, 'run_poll_for_filter' ] );
            }

    // REST-only hooks

            if ( $is_rest && strpos( dt_get_url_path(), 'disciple-tools-crm-sync' ) !== false ) {
                require_once DT_CRM_SYNC_PATH . 'rest-api/rest-api.php';
                Disciple_Tools_CRM_Sync_REST::instance();
            }

            if ( $is_rest && strpos( dt_get_url_path(), 'webhook' ) !== false ) {
                require_once DT_CRM_SYNC_PATH . 'webhook/webhook-listener.php';
                Disciple_Tools_CRM_Sync_Webhook::instance();
            }

    // Admin-only hooks

            if ( is_admin() ) {
                require_once DT_CRM_SYNC_PATH . 'admin/class-readme-parser.php';
                require_once DT_CRM_SYNC_PATH . 'admin/admin-menu-and-tabs.php';
                require_once DT_CRM_SYNC_PATH . 'admin/config-required-plugins.php';
                Disciple_Tools_CRM_Sync_Menu::instance();
                add_filter( 'plugin_row_meta', [ $this, 'plugin_description_links' ], 10, 4 );
                add_filter( 'plugins_api', [ $this, 'plugin_api_info' ], 10, 3 );
            }
        }

    // Cron schedules

        /**
         * Register custom WP-Cron recurrence intervals.
         *
         * Adds 2-, 4-, and 8-hour intervals so filter automations can run more
         * frequently than WP's built-in 'daily' without resorting to 'hourly'.
         *
         * @param array $schedules Existing cron schedule definitions.
         * @return array
         */
        public function add_cron_schedules( array $schedules ): array {
            $custom = [
            'every_2_hours' => [ 'interval' => 2 * HOUR_IN_SECONDS, 'display' => __( 'Every 2 Hours', 'disciple-tools-crm-sync' ) ],
            'every_4_hours' => [ 'interval' => 4 * HOUR_IN_SECONDS, 'display' => __( 'Every 4 Hours', 'disciple-tools-crm-sync' ) ],
            'every_8_hours' => [ 'interval' => 8 * HOUR_IN_SECONDS, 'display' => __( 'Every 8 Hours', 'disciple-tools-crm-sync' ) ],
            ];
            foreach ( $custom as $key => $args ) {
                if ( ! isset( $schedules[ $key ] ) ) {
                    $schedules[ $key ] = $args;
                }
            }
            return $schedules;
        }

    // Poll runner (single-hook pattern)

        /**
         * WP-Cron callback for the unified 'dt_crm_sync_poll' hook.
         * Scheduled as: wp_schedule_event( $ts, $interval, 'dt_crm_sync_poll', [ $filter_id ] )
         *
         * @param string $filter_id The sanitized filter ID to poll.
         */
        public function run_poll_for_filter( string $filter_id ): void {
            require_once DT_CRM_SYNC_PATH . 'import/poll-handler.php';
            $handler = new Disciple_Tools_CRM_Sync_Poll_Handler();
            $handler->run_poll( $filter_id );
        }

    // Roles and permissions

        /**
         * Grant the dt_crm_sync_import capability to admin and dispatcher roles.
         *
         * Hooked to dt_set_roles_and_permissions at priority 20, after core DT
         * permissions are established. Only modifies roles that already exist.
         *
         * @param array $expected_roles Role definitions from DT core.
         * @return array
         */
        public function set_roles_and_permissions( array $expected_roles ): array {
            $grant_to = [ 'administrator', 'dt_admin', 'dispatcher' ];
            foreach ( $grant_to as $role ) {
                if ( isset( $expected_roles[ $role ] ) ) {
                    $expected_roles[ $role ]['permissions']['dt_crm_sync_import'] = true;
                }
            }
            return $expected_roles;
        }

        /**
         * Dynamically grant dt_crm_sync_import to users who hold manage_dt.
         *
         * This allows has_permission() in the REST API to check only one capability
         * while keeping manage_dt admins fully authorised.
         *
         * @param array   $allcaps All capabilities the user possesses.
         * @param array   $caps    Required capabilities for the current check.
         * @param array   $args    Arguments (capability name, user ID, optional object ID).
         * @param WP_User $user    The user object being checked.
         * @return array
         */
        public function grant_import_cap_to_manage_dt( array $allcaps, array $caps, array $args, WP_User $user ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress filter signature.
            if ( ! empty( $allcaps['manage_dt'] ) ) {
                $allcaps['dt_crm_sync_import'] = true;
            }
            return $allcaps;
        }

    // Custom fields: connector sources

        /**
         * Register a DT contact source entry for each connector in the registry.
         * Each connector provides its own slug and label via get_dt_source_slug()
         * and get_dt_source_label(). Only called for the 'contacts' post type.
         *
         * @return array
         */
        public function register_connector_sources( array $fields, string $post_type ): array {
            if ( 'contacts' !== $post_type ) {
                return $fields;
            }
            foreach ( Disciple_Tools_CRM_Sync_Connector_Registry::get_connectors() as $slug => $class ) {
                if ( ! class_exists( $class ) ) {
                    continue;
                }
                if ( ! is_a( $class, 'Disciple_Tools_CRM_Sync_Abstract_Connector', true ) ) {
                    continue;
                }
                /** @var Disciple_Tools_CRM_Sync_Abstract_Connector $instance */
                $instance   = new $class( [] );
                $source_key = $instance->get_dt_source_slug();
                if ( ! isset( $fields['sources']['default'][ $source_key ] ) ) {
                    $fields['sources']['default'][ $source_key ] = [
                    'label' => $instance->get_dt_source_label(),
                    ];
                }
            }
            return $fields;
        }

    // Filter management

        /**
         * Create and schedule a saved filter.
         *
         * Called by both the admin form POST handler (Disciple_Tools_CRM_Sync_Tab_Automations)
         * and the REST endpoint (Disciple_Tools_CRM_Sync_REST::handle_create_filter()) so that
         * the envelope schema, option naming, and cron scheduling stay in sync.
         *
         * @param string $name            Human-readable filter label.
         * @param string $interval        WP cron interval: 'hourly' | 'every_2_hours' | 'every_4_hours' | 'every_8_hours' | 'daily'.
         * @param array  $filter_params   Generic filter parameters from the connector's get_filter_fields() slugs.
         * @param string $poll_time       For 'daily' interval: time-of-day in HH:MM (site timezone). Default '00:00'.
         * @param string $connector_slug  Slug of the connector that owns this filter. Defaults to active_connector.
         * @return string The generated filter ID.
         */
        public static function create_filter( string $name, string $interval, array $filter_params = [], string $poll_time = '00:00', string $connector_slug = '' ): string {
            if ( empty( $connector_slug ) ) {
                $settings       = get_option( 'dt_crm_sync_settings', [] );
                $connector_slug = is_array( $settings ) ? ( $settings['active_connector'] ?? '' ) : '';
            }

            $filter_id = sanitize_key( uniqid( 'filter_', false ) );

            // Envelope: top-level meta + generic filter_params map.
            $envelope = [
            'name'           => $name,
            'interval'       => $interval,
            'poll_time'      => $poll_time,
            'connector_slug' => $connector_slug,
            'filter_params'  => $filter_params,
            ];

            update_option( 'dt_crm_sync_saved_filter_' . $filter_id, wp_json_encode( $envelope ) );

            // Append to manifest. Stored as a plain PHP array (no JSON encoding) so all
            // consumers (main constructor, deactivation hook, uninstall) can read it with
            // a simple get_option() + is_array() check.
            $manifest   = get_option( 'dt_crm_sync_saved_filters', [] );
            $manifest   = is_array( $manifest ) ? $manifest : [];
            $manifest[] = $filter_id;
            update_option( 'dt_crm_sync_saved_filters', $manifest );

            // Calculate first-run timestamp.
            // For daily polls, honour the requested time-of-day in the site timezone.
            if ( 'daily' === $interval && preg_match( '/^(0\d|1\d|2[0-3]):[0-5]\d$/', $poll_time ) ) {
                [ $h, $m ] = array_map( 'intval', explode( ':', $poll_time ) );
                $tz_obj    = wp_timezone();
                $now       = new \DateTime( 'now', $tz_obj );
                $first_run = ( clone $now )->setTime( $h, $m, 0 );
                if ( $first_run <= $now ) {
                    $first_run->modify( '+1 day' );
                }
                $first_run_ts = $first_run->getTimestamp();
            } else {
                $first_run_ts = time();
            }

            // Schedule the recurring poll using the unified hook with filter_id as the cron arg.
            // Guard against double-scheduling for idempotency.
            if ( ! wp_next_scheduled( 'dt_crm_sync_poll', [ $filter_id ] ) ) {
                wp_schedule_event( $first_run_ts, $interval, 'dt_crm_sync_poll', [ $filter_id ] );
            }

            return $filter_id;
        }

    // Encryption helpers

        /**
         * Encrypt a plaintext string with AES-256-CBC.
         * The returned value is base64-encoded (IV prepended to ciphertext) and
         * safe to store directly in wp_options.
         *
         * @return string
         */
        public static function encrypt_value( string $plaintext ): string {
            $stored_key = get_option( 'dt_crm_sync_encryption_key', '' );
            if ( empty( $stored_key ) ) {
                throw new \RuntimeException( 'DT CRM Sync: Encryption key not found. Please deactivate and reactivate the plugin to regenerate the key.' );
            }
            $raw_key    = base64_decode( $stored_key ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding AES-256-CBC key stored in wp_options.
            $iv         = random_bytes( 16 );
            $ciphertext = openssl_encrypt( $plaintext, 'AES-256-CBC', $raw_key, OPENSSL_RAW_DATA, $iv );
            if ( false === $ciphertext ) {
                throw new \RuntimeException( 'DT CRM Sync: openssl_encrypt() failed. Check that the encryption key option exists and OpenSSL is functional.' );
            }
            return base64_encode( $iv . $ciphertext ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding AES-256-CBC IV+ciphertext for wp_options storage.
        }

        /**
         * Decrypt a stored ciphertext produced by encrypt_value().
         * Returns the plaintext string, or false on failure (corrupted data,
         * wrong key, or an empty input). Callers must check === false.
         *
         * @return string|false
         */
        public static function decrypt_value( string $stored ): string|false {
            if ( '' === $stored ) {
                return false;
            }
            $decoded = base64_decode( $stored, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding AES-256-CBC IV+ciphertext from wp_options.
            if ( false === $decoded || strlen( $decoded ) < 32 ) {
                return false;
            }
            $stored_key = get_option( 'dt_crm_sync_encryption_key', '' );
            if ( empty( $stored_key ) ) {
                return false;
            }
            $raw_key    = base64_decode( $stored_key ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding AES-256-CBC key stored in wp_options.
            $iv         = substr( $decoded, 0, 16 );
            $ciphertext = substr( $decoded, 16 );
            $plaintext  = openssl_decrypt( $ciphertext, 'AES-256-CBC', $raw_key, OPENSSL_RAW_DATA, $iv );
            return $plaintext;
        }

    // Activation

        /**
         * Create the log table and generate the encryption key on first activation.
         *
         * Aborts with wp_die() if the OpenSSL extension is unavailable — the plugin
         * cannot encrypt stored credentials without it. Uses add_option() for the
         * encryption key so re-activation never overwrites a key that is already
         * protecting saved credentials.
         */
        public static function activation(): void {
            if ( ! extension_loaded( 'openssl' ) ) {
                deactivate_plugins( plugin_basename( __FILE__ ) );
                wp_die(
                    esc_html__( 'Disciple.Tools - CRM Sync requires the PHP OpenSSL extension. Please enable it and try again.', 'disciple-tools-crm-sync' ),
                    esc_html__( 'Plugin Activation Error', 'disciple-tools-crm-sync' ),
                    [ 'back_link' => true ]
                );
            }

            global $wpdb;

            require_once ABSPATH . 'wp-admin/includes/upgrade.php';

            $table   = $wpdb->prefix . 'dt_crm_sync_logs';
            $charset = $wpdb->get_charset_collate();

            // Do NOT use DEFAULT CURRENT_TIMESTAMP — dbDelta regex can mishandle it.
            // The log writer inserts created_at explicitly via current_time('mysql').
            $sql = "CREATE TABLE $table (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at    DATETIME        NOT NULL,
            trigger_type  VARCHAR(20)     NOT NULL DEFAULT '',
            respond_id    VARCHAR(64)     NOT NULL DEFAULT '',
            dt_post_id    BIGINT UNSIGNED          DEFAULT NULL,
            status        VARCHAR(20)     NOT NULL DEFAULT '',
            message       TEXT            NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            KEY idx_status      (status),
            KEY idx_respond_id  (respond_id),
            KEY idx_created_at  (created_at)
        ) $charset;";

            dbDelta( $sql );

            // Translation logs table — same idempotent approach as above.
            require_once DT_CRM_SYNC_PATH . 'translation/class-translation-logger.php';
            Disciple_Tools_CRM_Sync_Translation_Logger::create_table();

            // Generate a plugin-specific 256-bit encryption key.
            // add_option() silently no-ops if the key already exists, so
            // re-activation never overwrites a key protecting stored credentials.
            // autoload='yes' (the default) avoids extra SELECT queries on every
            // encrypt_value() / decrypt_value() call.
            add_option( 'dt_crm_sync_encryption_key', base64_encode( random_bytes( 32 ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding a random 256-bit encryption key for wp_options storage.
        }

    // Deactivation

        /**
         * Clean up cron hooks and transient options on plugin deactivation.
         *
         * Clears every scheduled poll event (one per saved filter) plus the legacy
         * per-filter hook format for backwards compatibility with older installs.
         * Does NOT delete settings, logs, or the encryption key — those persist
         * until uninstall.
         */
        public static function deactivation(): void {
            wp_clear_scheduled_hook( 'dt_crm_sync_process_batch' );

            // Clear all instances of the unified poll hook (one per saved filter_id arg).
            $manifest = get_option( 'dt_crm_sync_saved_filters', [] );
            if ( is_array( $manifest ) ) {
                foreach ( $manifest as $filter_id ) {
                    $filter_id = sanitize_key( $filter_id );
                    // Unified poll hook — one recurring event per filter_id.
                    wp_clear_scheduled_hook( 'dt_crm_sync_poll', [ $filter_id ] );
                    // Also clear the legacy per-filter hook format for older installs.
                    wp_clear_scheduled_hook( 'dt_crm_sync_poll_' . $filter_id );
                }
            }

            delete_option( 'dismissed-disciple-tools-crm-sync' );
        }

    // Internationalisation

        /**
         * Load the plugin text domain for translations.
         */
        public function i18n(): void {
            load_plugin_textdomain(
                'disciple-tools-crm-sync',
                false,
                trailingslashit( dirname( plugin_basename( __FILE__ ) ) ) . 'languages'
            );
        }

    // Plugin description links

        /**
         * Append community and GitHub links to this plugin's row in the Plugins screen.
         *
         * @param array  $links_array     Existing row links.
         * @param string $plugin_file_name Plugin basename.
         * @param array  $plugin_data     Plugin header data.
         * @param string $status         Plugin status string.
         * @return array
         */
        public function plugin_description_links( array $links_array, string $plugin_file_name, array $plugin_data, string $status ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress plugin_row_meta hook callback signature.
            if ( strpos( $plugin_file_name, basename( __FILE__ ) ) ) {
                $links_array[] = '<a href="' . esc_url( 'https://disciple.tools' ) . '">' . esc_html__( 'Disciple.Tools Community', 'disciple-tools-crm-sync' ) . '</a>';
                $links_array[] = '<a href="' . esc_url( 'https://github.com/' . DT_CRM_SYNC_GITHUB_ORG . '/' . DT_CRM_SYNC_GITHUB_REPO ) . '">' . esc_html__( 'GitHub', 'disciple-tools-crm-sync' ) . '</a>';
            }
            return $links_array;
        }

    // Plugin API info ("View Details" modal)

        /**
         * Serve plugin information for the "View Details" modal in the Plugins screen.
         *
         * Intercepts the plugins_api call for this plugin's slug so WordPress does not
         * fall through to the WP.org API (which returns "Plugin not found." for
         * self-hosted plugins).
         *
         * @param false|object|WP_Error $result  Current result — false means not yet handled.
         * @param string                $action  Requested API action.
         * @param object                $args    Request arguments including the slug.
         * @return false|object Populated stdClass when this is our plugin, otherwise $result.
         */
        public function plugin_api_info( $result, string $action, object $args ) {
            if ( 'plugin_information' !== $action || 'disciple-tools-crm-sync' !== ( $args->slug ?? '' ) ) {
                return $result;
            }

            $json = file_get_contents( DT_CRM_SYNC_PATH . 'version-control.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file read, not a remote HTTP request.
            $vc   = $json ? json_decode( $json, true ) : [];

            // Parse README.md sections (local first, remote fallback) for modal tab content.
            $readme  = Disciple_Tools_CRM_Sync_Readme_Parser::get_sections(
                DT_CRM_SYNC_PATH . 'README.md',
                $vc['readme_url'] ?? ''
            );

            $description  = ( $readme['Purpose'] ?? '' ) . ( $readme['Usage'] ?? '' );
            $installation = ( $readme['Installation'] ?? '' ) . ( $readme['Cron Setup'] ?? '' ) . ( $readme['Webhook Setup'] ?? '' );
            $changelog    = $readme['Changelog'] ?? '';

            $info                = new stdClass();
            $info->name          = $vc['name'] ?? 'Disciple.Tools - CRM Sync';
            $info->slug          = 'disciple-tools-crm-sync';
            $info->version       = $vc['version'] ?? DT_CRM_SYNC_VERSION;
            $info->author        = '<a href="' . esc_url( $vc['author_homepage'] ?? '' ) . '">' . esc_html( $vc['author'] ?? '' ) . '</a>';
            $info->author_profile = esc_url( $vc['author_homepage'] ?? '' );
            $info->requires      = $vc['requires'] ?? '';
            $info->tested        = $vc['tested'] ?? '';
            $info->last_updated  = $vc['last_updated'] ?? '';
            $info->homepage      = esc_url( $vc['homepage'] ?? '' );
            $info->download_link = esc_url( $vc['download_url'] ?? '' );
            $info->sections      = [
            'description'  => wp_kses_post( '' !== $description ? $description : ( $vc['sections']['description'] ?? '' ) ),
            'installation' => wp_kses_post( '' !== $installation ? $installation : ( $vc['sections']['installation'] ?? '' ) ),
            'changelog'    => wp_kses_post( '' !== $changelog ? $changelog : ( $vc['sections']['changelog'] ?? '' ) ),
            ];
            $info->banners       = [
            'low'  => esc_url( $vc['banners']['low'] ?? '' ),
            'high' => esc_url( $vc['banners']['high'] ?? '' ),
            ];
            $info->icons         = [
            'default' => esc_url( $vc['icon'] ?? '' ),
            ];

            return $info;
        }

    // Magic methods

        /** @return string Plugin identifier string. */
        public function __toString(): string {
            return 'disciple-tools-crm-sync';
        }

        /** Prevent cloning the singleton. */
        public function __clone(): void {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- _doing_it_wrong() writes to PHP error log, not browser output; DT_CRM_SYNC_VERSION is a plugin constant.
            _doing_it_wrong( __FUNCTION__, __( 'Whoah, partner!', 'disciple-tools-crm-sync' ), DT_CRM_SYNC_VERSION );
        }

        /** Prevent unserializing the singleton. */
        public function __wakeup(): void {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- _doing_it_wrong() writes to PHP error log, not browser output; DT_CRM_SYNC_VERSION is a plugin constant.
            _doing_it_wrong( __FUNCTION__, __( 'Whoah, partner!', 'disciple-tools-crm-sync' ), DT_CRM_SYNC_VERSION );
        }

        /**
         * Catch calls to undefined methods and report them via _doing_it_wrong().
         *
         * @param string $method Method name.
         * @param array  $args   Arguments passed to the call.
         * @return mixed Always returns null.
         */
        public function __call( string $method, array $args ): mixed {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- _doing_it_wrong() writes to PHP error log, not browser output; DT_CRM_SYNC_VERSION is a plugin constant.
            _doing_it_wrong( 'Disciple_Tools_CRM_Sync::' . esc_html( $method ), __( 'Method does not exist.', 'disciple-tools-crm-sync' ), DT_CRM_SYNC_VERSION );
            unset( $method, $args );
            return null;
        }
    }

endif; // class_exists 'Disciple_Tools_CRM_Sync'

// Activation / Deactivation Hooks

register_activation_hook( __FILE__, [ 'Disciple_Tools_CRM_Sync', 'activation' ] );
register_deactivation_hook( __FILE__, [ 'Disciple_Tools_CRM_Sync', 'deactivation' ] );

// Admin notices (DT theme compatibility)

if ( ! function_exists( 'dt_crm_sync_hook_admin_notice' ) ) {
    /**
     * Render the admin notice when the DT theme is missing or below minimum version.
     *
     * Includes a dismissible close button that fires an AJAX request to persist
     * the dismissed state in wp_options so the notice does not reappear.
     */
    function dt_crm_sync_hook_admin_notice(): void {
        $wp_theme         = wp_get_theme();
        $current_version  = $wp_theme->version;
        $required_version = '1.47';
        $is_theme_dt      = class_exists( 'Disciple_Tools' );

        if ( $is_theme_dt && version_compare( $current_version, $required_version, '>=' ) ) {
            return;
        }

        if ( $is_theme_dt ) {
            $message = sprintf(
                /* translators: 1: installed DT version, 2: required DT version */
                esc_html__( 'Disciple.Tools - CRM Sync requires Disciple.Tools theme version %2$s or greater. Current version: %1$s.', 'disciple-tools-crm-sync' ),
                esc_html( $current_version ),
                esc_html( $required_version )
            );
        } else {
            $message = esc_html__( 'Disciple.Tools - CRM Sync requires the Disciple.Tools theme to be active.', 'disciple-tools-crm-sync' );
        }

        if ( ! get_option( 'dismissed-disciple-tools-crm-sync', false ) ) {
            wp_enqueue_script(
                'dt-crm-sync-notice-dismiss',
                plugin_dir_url( __FILE__ ) . 'admin/js/notice-dismiss.js',
                [ 'jquery' ],
                DT_CRM_SYNC_VERSION,
                true
            );
            wp_localize_script(
                'dt-crm-sync-notice-dismiss',
                'dtCrmSyncNotice',
                [ 'nonce' => wp_create_nonce( 'wp_rest_dismiss' ) ]
            );
            ?>
            <div class="notice notice-error notice-disciple-tools-crm-sync is-dismissible" data-notice="disciple-tools-crm-sync">
                <p><?php echo esc_html( $message ); ?></p>
            </div>
        <?php }
    }
}

if ( ! function_exists( 'dt_crm_sync_hook_ajax_notice_handler' ) ) {
    /**
     * AJAX handler — persist the dismissed state for the DT-requirement notice.
     *
     * Validates the nonce posted by the inline dismiss script, then sets
     * 'dismissed-{type}' in wp_options so the notice does not reappear.
     */
    function dt_crm_sync_hook_ajax_notice_handler(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die();
        }
        check_ajax_referer( 'wp_rest_dismiss', 'security' );
        if ( isset( $_POST['type'] ) ) {
            $type = sanitize_text_field( wp_unslash( $_POST['type'] ) );
            if ( 'disciple-tools-crm-sync' !== $type ) {
                return;
            }
            update_option( 'dismissed-' . $type, true );
        }
    }
}
