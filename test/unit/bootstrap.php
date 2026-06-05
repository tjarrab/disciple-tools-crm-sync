<?php
/**
 * Bootstrap for Brain Monkey unit tests.
 *
 * Defines only the minimum constants, stub functions, and stub classes needed
 * for the plugin PHP files to be required without loading WordPress. Brain
 * Monkey intercepts WordPress function calls at runtime inside each test;
 * these stubs handle file-load-time calls and WP types used in type hints.
 */

// Minimum WP constants
define( 'ABSPATH', '/' );
define( 'WPINC', 'wp-includes' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

// WP hook stubs (called at file-load time by some plugin files)
// Brain Monkey overrides these per-test via Patchwork; stubs here keep the
// require_once calls clean before any test setUp() runs.
if ( ! function_exists( 'add_filter' ) ) {
    function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ): void {} // phpcs:ignore
}
if ( ! function_exists( 'add_action' ) ) {
    function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ): void {} // phpcs:ignore
}
// Translation stub: return the string unchanged.
// NOTE: defined BEFORE the Composer autoloader (and therefore before Patchwork)
// so Brain Monkey cannot re-mock it.  Tests that need __() to behave differently
// should not use Functions\when('__') and instead rely on this pass-through.
if ( ! function_exists( '__' ) ) {
    function __( string $text, string $domain = 'default' ): string { // phpcs:ignore
        return $text;
    }
}

// WP_Error stub class
if ( ! class_exists( 'WP_Error' ) ) {
    /**
     * Minimal WP_Error stub for unit tests.
     * Stores one error code, message, and data payload.
     */
    class WP_Error {
        private string $code;
        private string $message;
        private mixed $data;

        public function __construct( string $code = '', string $message = '', mixed $data = [] ) {
            $this->code    = $code;
            $this->message = $message;
            $this->data    = $data;
        }

        public function get_error_code(): string   { return $this->code; }
        public function get_error_message(): string { return $this->message; }
        public function get_error_data(): mixed     { return $this->data; }
    }
}

if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( mixed $thing ): bool { // phpcs:ignore
        return $thing instanceof WP_Error;
    }
}

if ( ! function_exists( 'plugin_dir_path' ) ) {
    function plugin_dir_path( string $file ): string { // phpcs:ignore
        return dirname( $file ) . '/';
    }
}

if ( ! function_exists( 'plugin_dir_url' ) ) {
    function plugin_dir_url( string $file ): string { // phpcs:ignore
        return 'http://localhost/';
    }
}

if ( ! function_exists( 'get_file_data' ) ) {
    function get_file_data( string $file, array $headers ): array { // phpcs:ignore
        return array_fill_keys( array_keys( $headers ), '' );
    }
}

if ( ! function_exists( 'register_activation_hook' ) ) {
    function register_activation_hook( $file, $callback ): void {} // phpcs:ignore
}
if ( ! function_exists( 'register_deactivation_hook' ) ) {
    function register_deactivation_hook( $file, $callback ): void {} // phpcs:ignore
}

// Main plugin class stub (provides encrypt/decrypt used by Connector_Registry)
if ( ! class_exists( 'Disciple_Tools_CRM_Sync' ) ) {
    class Disciple_Tools_CRM_Sync {
        /**
         * Override hook for unit tests. When set to a Closure, decrypt_value() delegates
         * to it so tests can exercise the real AES-256-CBC logic with a mocked key.
         * Reset to null in BrainMonkeyTestCase::setUp() between every test.
         *
         * @var \Closure|null
         */
        public static ?\Closure $test_decrypt_fn = null;

        /**
         * Encrypt plaintext with AES-256-CBC (same algorithm as production).
         * Reads the key from get_option('dt_crm_sync_encryption_key') — mock in tests.
         */
        public static function encrypt_value( string $plaintext ): string {
            $stored_key = get_option( 'dt_crm_sync_encryption_key', '' );
            if ( empty( $stored_key ) ) {
                throw new \RuntimeException( 'DT CRM Sync: Encryption key not found.' );
            }
            $raw_key    = base64_decode( $stored_key, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
            $iv         = random_bytes( 16 );
            $ciphertext = openssl_encrypt( $plaintext, 'AES-256-CBC', $raw_key, OPENSSL_RAW_DATA, $iv );
            if ( false === $ciphertext ) {
                throw new \RuntimeException( 'DT CRM Sync: openssl_encrypt() failed.' );
            }
            return base64_encode( $iv . $ciphertext ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
        }

        /**
         * Decrypt a stored ciphertext. When $test_decrypt_fn is set, delegates to it
         * so tests can exercise the real AES-256-CBC logic. Otherwise returns false so
         * the registry treats all stored credential values as already-decrypted plaintext.
         */
        public static function decrypt_value( string $value ): string|false {
            if ( null !== self::$test_decrypt_fn ) {
                return ( self::$test_decrypt_fn )( $value );
            }
            return false;
        }

        /**
         * Stub implementation of create_filter() matching the production class.
         * WP functions (get_option, update_option, wp_next_scheduled, wp_schedule_event)
         * are intercepted by Brain Monkey in tests that need to inspect them.
         */
        public static function create_filter(
            string $name,
            string $interval,
            array $filter_params = [],
            string $poll_time = '00:00',
            string $connector_slug = ''
        ): string {
            if ( empty( $connector_slug ) ) {
                $settings       = get_option( 'dt_crm_sync_settings', [] );
                $connector_slug = is_array( $settings ) ? ( $settings['active_connector'] ?? '' ) : '';
            }

            $filter_id = sanitize_key( uniqid( 'filter_', false ) );

            $envelope = [
                'name'           => $name,
                'interval'       => $interval,
                'poll_time'      => $poll_time,
                'connector_slug' => $connector_slug,
                'filter_params'  => $filter_params,
            ];

            update_option( 'dt_crm_sync_saved_filter_' . $filter_id, wp_json_encode( $envelope ) );

            $manifest   = get_option( 'dt_crm_sync_saved_filters', [] );
            $manifest   = is_array( $manifest ) ? $manifest : [];
            $manifest[] = $filter_id;
            update_option( 'dt_crm_sync_saved_filters', $manifest );

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

            if ( ! wp_next_scheduled( 'dt_crm_sync_poll', [ $filter_id ] ) ) {
                wp_schedule_event( $first_run_ts, $interval, 'dt_crm_sync_poll', [ $filter_id ] );
            }

            return $filter_id;
        }

        /**
         * Stub implementation of add_cron_schedules() matching the production class.
         */
        public function add_cron_schedules( array $schedules ): array {
            $custom = [
                'every_2_hours' => [ 'interval' => 2 * HOUR_IN_SECONDS, 'display' => 'Every 2 Hours' ],
                'every_4_hours' => [ 'interval' => 4 * HOUR_IN_SECONDS, 'display' => 'Every 4 Hours' ],
                'every_8_hours' => [ 'interval' => 8 * HOUR_IN_SECONDS, 'display' => 'Every 8 Hours' ],
            ];
            foreach ( $custom as $key => $args ) {
                if ( ! isset( $schedules[ $key ] ) ) {
                    $schedules[ $key ] = $args;
                }
            }
            return $schedules;
        }
    }
}

// $wpdb stub (used by Disciple_Tools_CRM_Sync_Logger::write())
global $wpdb;
$wpdb = new class() {
    public string $prefix = 'wp_';

    /**
     * All insert() calls recorded as [ 'table' => string, 'data' => array ].
     * Reset in BrainMonkeyTestCase::setUp() between every test.
     *
     * @var array<int, array{table: string, data: array}>
     */
    public array $insert_calls = [];

    /** WP table name properties used in SQL queries. */
    public string $postmeta  = 'wp_postmeta';
    public string $posts     = 'wp_posts';
    public string $comments  = 'wp_comments';
    public string $commentmeta = 'wp_commentmeta';
    public string $options   = 'wp_options';

    /**
     * Return value for the next insert() call; resets to 1 after each call.
     * Set to false in a test to simulate an insert failure.
     */
    public int|false $next_insert_result = 1;

    public function insert( $table, $data, $format = null ): int|false { // phpcs:ignore
        $this->insert_calls[]     = [ 'table' => $table, 'data' => $data ];
        $result                   = $this->next_insert_result;
        $this->next_insert_result = 1;
        return $result;
    }

    /**
     * Basic %s/%d/%f substitution so format-string bugs produce visibly wrong SQL.
     * %% is replaced with a literal percent sign.
     */
    public function prepare( $sql, ...$args ): string { // phpcs:ignore
        if ( empty( $args ) ) {
            return $sql;
        }
        $i = 0;
        return preg_replace_callback(
            '/%%|%[sdf]/',
            function ( array $match ) use ( $args, &$i ): string {
                if ( '%%' === $match[0] ) {
                    return '%';
                }
                $val = $args[ $i++ ] ?? '';
                if ( '%d' === $match[0] ) {
                    return null === $val ? 'NULL' : (string) (int) $val;
                }
                if ( '%f' === $match[0] ) {
                    return (string) (float) $val;
                }
                return "'" . addslashes( (string) $val ) . "'";
            },
            $sql
        );
    }

    public function esc_like( string $s ): string { return addcslashes( $s, '%_\\' ); }

    /**
     * Return value for the next get_var() call; resets to null after each call.
     * Set this before calling code that triggers a $wpdb->get_var() query.
     */
    public mixed $next_get_var_result = null;

    public function get_var( $query ): mixed { // phpcs:ignore
        $this->last_get_var_sql    = $query;
        $result                    = $this->next_get_var_result;
        $this->next_get_var_result = null;
        return $result;
    }

    /**
     * Return value for the next get_results() call; resets to [] after each call.
     * Set $next_get_results_result in a test to control what get_results() returns.
     */
    public array $next_get_results_result = [];

    public function get_results( $query = null, $output = 'OBJECT' ): array { // phpcs:ignore
        $result                        = $this->next_get_results_result;
        $this->next_get_results_result = [];
        return $result;
    }

    /** Captured SQL from the last query() call. */
    public ?string $last_query_sql = null;

    /** Captured SQL from the last get_var() call. Reset in BrainMonkeyTestCase::setUp(). */
    public ?string $last_get_var_sql = null;

    /** Return value for the next query() call. */
    public int|false $next_query_result = 0;

    public function query( string $sql ): int|false { // phpcs:ignore
        $this->last_query_sql        = $sql;
        $result                      = $this->next_query_result;
        $this->next_query_result     = 0;
        return $result;
    }
};

// DT_Posts stub
if ( ! class_exists( 'DT_Posts' ) ) {
    /**
     * Stub for DT_Posts static methods used by ImportProcessor.
     * Call DT_Posts::reset() in BrainMonkeyTestCase::setUp() between tests.
     */
    class DT_Posts {
        public static mixed $create_post_result   = null;
        public static mixed $update_post_result   = null;
        public static mixed $add_comment_result   = 1;
        public static array $create_post_calls    = [];
        public static array $update_post_calls    = [];
        public static array $add_comment_calls    = [];

        public static function reset(): void {
            self::$create_post_result = null;
            self::$update_post_result = null;
            self::$add_comment_result = 1;
            self::$create_post_calls  = [];
            self::$update_post_calls  = [];
            self::$add_comment_calls  = [];
        }

        public static function create_post( $type, $fields, $silent = false, $check_perms = true ): mixed { // phpcs:ignore
            self::$create_post_calls[] = [ 'type' => $type, 'fields' => $fields ];
            return self::$create_post_result ?? [ 'ID' => 1 ];
        }

        public static function update_post( $type, $id, $fields, $silent = false, $check_perms = true ): mixed { // phpcs:ignore
            self::$update_post_calls[] = [ 'type' => $type, 'id' => $id, 'fields' => $fields ];
            return self::$update_post_result ?? [ 'ID' => $id ];
        }

        public static function add_post_comment( $type, $post_id, $content, $comment_type = 'comment', $args = [], $check_perms = false, $silent = false ): mixed { // phpcs:ignore
            self::$add_comment_calls[] = [ 'post_id' => $post_id, 'content' => $content, 'args' => $args ];
            return self::$add_comment_result;
        }

        public static function get_post_settings( $type ): array { // phpcs:ignore
            return [ 'fields' => [] ];
        }
    }
}

// WP REST API stubs
if ( ! class_exists( 'WP_REST_Server' ) ) {
    class WP_REST_Server {
        const READABLE  = 'GET';
        const CREATABLE = 'POST';
        const DELETABLE = 'DELETE';
    }
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
    class WP_REST_Request {
        private array $params;
        public function __construct( string $method = 'GET', string $route = '', array $params = [] ) { // phpcs:ignore
            $this->params = $params;
        }
        public function get_param( string $key ): mixed  { return $this->params[ $key ] ?? null; }
        public function get_json_params(): array          { return $this->params; }
        public function get_query_params(): array         { return $this->params; }
    }
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
    class WP_REST_Response {
        private mixed $data;
        private int $status;
        private array $headers = [];
        public function __construct( mixed $data = null, int $status = 200 ) {
            $this->data   = $data;
            $this->status = $status;
        }
        public function get_data(): mixed    { return $this->data; }
        public function get_status(): int    { return $this->status; }
        public function get_headers(): array { return $this->headers; }
        public function header( string $header, string $value, bool $replace = true ): void { // phpcs:ignore
            $this->headers[ $header ] = $value;
        }
    }
}

// Simple pass-through stubs used at runtime (not mocked by any test)
// These are defined BEFORE the autoloader so they exist when plugin files need
// them, but since no test uses Functions\when() on them there is no conflict.
if ( ! function_exists( 'esc_url' ) ) {
    function esc_url( $url ): string { return (string) $url; } // phpcs:ignore
}
if ( ! function_exists( 'esc_html' ) ) {
    function esc_html( $text ): string { return htmlspecialchars( (string) $text, ENT_QUOTES ); } // phpcs:ignore
}
if ( ! function_exists( 'wp_kses_post' ) ) {
    function wp_kses_post( $data ): string { return (string) $data; } // phpcs:ignore
}
if ( ! function_exists( 'current_time' ) ) {
    function current_time( $type, $gmt = 0 ): string { return gmdate( 'Y-m-d H:i:s' ); } // phpcs:ignore
}
if ( ! function_exists( 'add_comment_meta' ) ) {
    function add_comment_meta( $comment_id, $meta_key, $meta_value, $unique = false ): mixed { return 1; } // phpcs:ignore
}

// Composer autoloader (includes Brain Monkey + Mockery)
require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

// Bootstrap Patchwork now, before any re-mockable stubs are defined
// Brain Monkey loads Patchwork lazily on the first test setUp() call; at that
// point every file already required is "defined too early" for Patchwork's
// stream-wrapper.  By loading Patchwork explicitly here we register the stream
// wrapper immediately, so the require_once calls below are intercepted and
// their functions become re-definable by Brain Monkey.
require_once dirname( __DIR__, 2 ) . '/vendor/antecedent/patchwork/Patchwork.php';

// Unit test base class
require_once __DIR__ . '/BrainMonkeyTestCase.php';

// Plugin files under test
$_plugin_root = dirname( __DIR__, 2 );

require_once $_plugin_root . '/connectors/abstract-connector.php';
require_once $_plugin_root . '/connectors/connector-registry.php';
require_once $_plugin_root . '/connectors/respond-io/respond-io-api-client.php';
require_once $_plugin_root . '/connectors/respond-io/respond-io-connector.php';
require_once $_plugin_root . '/import/class-logger.php';
require_once $_plugin_root . '/import/class-contact-matcher.php';
require_once $_plugin_root . '/import/class-field-mapper.php';
require_once $_plugin_root . '/import/class-media-sideloader.php';
require_once $_plugin_root . '/import/class-message-importer.php';
require_once $_plugin_root . '/import/import-processor.php';
require_once $_plugin_root . '/import/poll-handler.php';
require_once $_plugin_root . '/rest-api/abstract-rest-controller.php';
require_once $_plugin_root . '/rest-api/class-rest-config.php';
require_once $_plugin_root . '/rest-api/class-rest-contacts.php';
require_once $_plugin_root . '/rest-api/class-rest-filters.php';
require_once $_plugin_root . '/rest-api/class-rest-message-viewer.php';
require_once $_plugin_root . '/rest-api/rest-api.php';
require_once $_plugin_root . '/translation/abstract-translation-provider.php';
require_once $_plugin_root . '/translation/gemini/gemini-translation-provider.php';
require_once $_plugin_root . '/translation/class-translation-logger.php';
require_once $_plugin_root . '/translation/class-translation-rate-limiter.php';
require_once $_plugin_root . '/translation/class-translation-service.php';

// Runtime WP function stubs (defined AFTER Patchwork so tests can re-mock)
// These live in a separate file so Patchwork's stream-wrapper intercepts the
// require_once call and instruments each function to be re-definable by Brain
// Monkey.  Inlining them here would bypass the stream-wrapper and cause
// Patchwork\Exceptions\DefinedTooEarly.
require_once __DIR__ . '/wp-function-stubs.php';
