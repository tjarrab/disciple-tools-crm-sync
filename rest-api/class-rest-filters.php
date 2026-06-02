<?php
/**
 * REST controller for filter automation routes.
 *
 * Routes:
 *   GET    /saved-filters
 *   POST   /saved-filters
 *   DELETE /saved-filters/purge
 *   DELETE /saved-filters/(?P<id>[a-z0-9_]+)
 *   POST   /saved-filters/(?P<id>[a-z0-9_]+)/run
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Disciple_Tools_CRM_Sync_REST_Filters' ) ) {
    /**
     * REST routes for saved-filter CRUD and on-demand runs.
     *
     * @package Disciple_Tools
     */
    class Disciple_Tools_CRM_Sync_REST_Filters extends Disciple_Tools_CRM_Sync_Abstract_REST_Controller {

        /**
         * Register filter automation routes.
         */
        public function register_routes(): void {
            $ns   = self::API_NAMESPACE;
            $perm = [ $this, 'has_permission' ];

            register_rest_route( $ns, '/saved-filters', [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'handle_list_filters' ],
                    'permission_callback' => $perm,
                ],
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => [ $this, 'handle_create_filter' ],
                    'permission_callback' => $perm,
                ],
            ] );

            // Purge must be registered before the wildcard route so WP does not
            // try to match the literal string "purge" as a filter ID.
            register_rest_route( $ns, '/saved-filters/purge', [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [ $this, 'handle_purge_all_filters' ],
                'permission_callback' => $perm,
            ] );

            register_rest_route( $ns, '/saved-filters/(?P<id>[a-z0-9_]+)', [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [ $this, 'handle_delete_filter' ],
                'permission_callback' => $perm,
            ] );

            register_rest_route( $ns, '/saved-filters/(?P<id>[a-z0-9_]+)/run', [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'handle_run_filter' ],
                'permission_callback' => $perm,
            ] );
        }

        /**
         * GET /saved-filters
         * Returns all saved filter envelopes from the manifest, each augmented
         * with the next scheduled run timestamp.
         *
         * @return WP_REST_Response
         */
        public function handle_list_filters( WP_REST_Request $request ): WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- WordPress REST API callback signature.
            // Manifest is always stored as a plain PHP array (never JSON-encoded).
            $manifest = get_option( 'dt_crm_sync_saved_filters', [] );
            if ( ! is_array( $manifest ) ) {
                $manifest = [];
            }

            $filters = [];
            foreach ( $manifest as $filter_id ) {
                $filter_id = sanitize_key( $filter_id );
                if ( empty( $filter_id ) ) {
                    continue;
                }

                $envelope = json_decode(
                    get_option( 'dt_crm_sync_saved_filter_' . $filter_id, '{}' ),
                    true
                );

                if ( ! is_array( $envelope ) ) {
                    continue;
                }

                $envelope['id'] = $filter_id;
                // The unified poll hook passes filter_id as the cron arg.
                $next_run = wp_next_scheduled( 'dt_crm_sync_poll', [ $filter_id ] );
                // Fall back to the legacy per-filter hook name for older saved filters.
                if ( false === $next_run ) {
                    $next_run = wp_next_scheduled( 'dt_crm_sync_poll_' . $filter_id );
                }
                $envelope['next_run'] = $next_run;

                $filters[] = $envelope;
            }

            return new WP_REST_Response( [ 'filters' => $filters ], 200 );
        }

        /**
         * POST /saved-filters
         * Creates a new saved filter, persists it, and schedules a recurring cron poll.
         *
         * Expected JSON body:
         *   name      (string, required)   — human-readable label
         *   interval  (string, required)   — 'hourly' | 'every_2_hours' | 'every_4_hours' | 'every_8_hours' | 'daily'
         *   poll_time (string, optional)   — for daily only: HH:MM in site timezone, e.g. '14:00'. Default '00:00'.
         *   search    (string, optional)   — free-text contact search
         *   tag       (string, optional)   — free-text tag filter
         *
         * @return WP_REST_Response
         */
        public function handle_create_filter( WP_REST_Request $request ): WP_REST_Response {
            $params = $request->get_json_params() ?? [];

            $name = sanitize_text_field( wp_unslash( $params['name'] ?? '' ) );
            if ( empty( $name ) ) {
                return new WP_REST_Response(
                    [ 'error' => __( 'name is required.', 'disciple-tools-crm-sync' ) ],
                    400
                );
            }

            $valid_intervals = [ 'hourly', 'every_2_hours', 'every_4_hours', 'every_8_hours', 'daily' ];
            $interval        = sanitize_key( $params['interval'] ?? '' );
            if ( ! in_array( $interval, $valid_intervals, true ) ) {
                return new WP_REST_Response(
                    [ 'error' => __( 'interval must be one of: hourly, every_2_hours, every_4_hours, every_8_hours, daily.', 'disciple-tools-crm-sync' ) ],
                    400
                );
            }

            $poll_time = sanitize_text_field( wp_unslash( $params['poll_time'] ?? '00:00' ) );
            if ( ! preg_match( '/^(0\d|1\d|2[0-3]):[0-5]\d$/', $poll_time ) ) {
                $poll_time = '00:00';
            }

            // Accept a generic filter_params map; fall back to legacy search/tag fields.
            $filter_params = isset( $params['filter_params'] ) && is_array( $params['filter_params'] )
                ? self::sanitize_filter_params_recursive( $params['filter_params'] )
                : [
                    'search' => sanitize_text_field( wp_unslash( $params['search'] ?? '' ) ),
                    'tag'    => sanitize_text_field( wp_unslash( $params['tag'] ?? '' ) ),
                ];

            $filter_id = Disciple_Tools_CRM_Sync::create_filter( $name, $interval, $filter_params, $poll_time );

            return new WP_REST_Response( [ 'id' => $filter_id, 'status' => 'created' ], 201 );
        }

        /**
         * DELETE /saved-filters/{id}
         * Clears the cron hook, deletes the option, and removes the ID from the manifest.
         *
         * @return WP_REST_Response
         */
        public function handle_delete_filter( WP_REST_Request $request ): WP_REST_Response {
            $filter_id = sanitize_key( $request->get_param( 'id' ) );

            // Manifest is always stored as a plain PHP array (never JSON-encoded).
            $manifest = get_option( 'dt_crm_sync_saved_filters', [] );
            if ( ! is_array( $manifest ) ) {
                $manifest = [];
            }

            // Validate the ID exists in the manifest before touching any options or cron hooks.
            if ( ! in_array( $filter_id, $manifest, true ) ) {
                return new WP_REST_Response(
                    [ 'error' => __( 'Filter not found.', 'disciple-tools-crm-sync' ) ],
                    404
                );
            }

            wp_clear_scheduled_hook( 'dt_crm_sync_poll', [ $filter_id ] );
            wp_clear_scheduled_hook( 'dt_crm_sync_poll_' . $filter_id ); // legacy cleanup

            // Confirm the event is actually gone. In some environments wp_clear_scheduled_hook
            // can silently fail (e.g. serialization quirks in the cron option), so fall back
            // to removing the entry from the cron array directly.
            if ( wp_next_scheduled( 'dt_crm_sync_poll', [ $filter_id ] ) ) {
                self::force_remove_cron_hook( 'dt_crm_sync_poll', [ $filter_id ] );
            }

            delete_option( 'dt_crm_sync_saved_filter_' . $filter_id );

            $manifest = array_values( array_diff( $manifest, [ $filter_id ] ) );
            update_option( 'dt_crm_sync_saved_filters', $manifest );

            return new WP_REST_Response( [ 'status' => 'deleted' ], 200 );
        }

        /**
         * POST /saved-filters/{id}/run
         * Schedules an immediate one-off poll for the given filter.
         * Returns HTTP 200 immediately; the actual poll runs in WP-Cron.
         *
         * @return WP_REST_Response
         */
        public function handle_run_filter( WP_REST_Request $request ): WP_REST_Response {
            $filter_id = sanitize_key( $request->get_param( 'id' ) );

            $manifest = get_option( 'dt_crm_sync_saved_filters', [] );
            if ( ! is_array( $manifest ) ) {
                $manifest = [];
            }

            if ( ! in_array( $filter_id, $manifest, true ) ) {
                return new WP_REST_Response(
                    [ 'success' => false, 'message' => __( 'Filter not found.', 'disciple-tools-crm-sync' ) ],
                    404
                );
            }

            // Guard against duplicate scheduling from rapid clicks: if a poll for this
            // filter is already queued to run within the next 30 seconds, skip re-scheduling.
            $next = wp_next_scheduled( 'dt_crm_sync_poll', [ $filter_id ] );
            if ( $next && $next <= ( time() + 30 ) ) {
                return new WP_REST_Response(
                    [ 'success' => true, 'message' => __( 'Poll already queued.', 'disciple-tools-crm-sync' ) ],
                    200
                );
            }

            wp_schedule_single_event( time() + 5, 'dt_crm_sync_poll', [ $filter_id ] );

            return new WP_REST_Response(
                [ 'success' => true, 'message' => __( 'Poll queued.', 'disciple-tools-crm-sync' ) ],
                200
            );
        }

        /**
         * DELETE /saved-filters/purge
         * Removes all scheduled dt_crm_sync_poll events, clears the manifest, and
         * deletes all saved filter options. Use this when the normal delete flow
         * leaves orphaned cron events behind.
         *
         * @return WP_REST_Response
         */
        public function handle_purge_all_filters(): WP_REST_Response {
            $manifest = get_option( 'dt_crm_sync_saved_filters', [] );
            $manifest = is_array( $manifest ) ? $manifest : [];

            // Pick up any dt_crm_sync_poll events that are still in the cron table
            // but no longer have a manifest entry (orphans). We have to do this
            // before the loop, not after, because wp_clear_scheduled_hook() is what
            // removes them and we need the IDs to call it correctly.
            $cron_ids = [];
            $cron_all = _get_cron_array();
            if ( is_array( $cron_all ) ) {
                foreach ( $cron_all as $hooks ) {
                    if ( ! isset( $hooks['dt_crm_sync_poll'] ) ) {
                        continue;
                    }
                    foreach ( $hooks['dt_crm_sync_poll'] as $hook_data ) {
                        $args      = $hook_data['args'] ?? [];
                        $filter_id = is_array( $args ) && isset( $args[0] ) ? sanitize_key( $args[0] ) : '';
                        if ( '' !== $filter_id ) {
                            $cron_ids[] = $filter_id;
                        }
                    }
                }
            }

            $all_ids = array_unique( array_merge( $manifest, $cron_ids ) );

            $cleared = 0;
            foreach ( $all_ids as $filter_id ) {
                $filter_id = sanitize_key( $filter_id );
                wp_clear_scheduled_hook( 'dt_crm_sync_poll', [ $filter_id ] );
                wp_clear_scheduled_hook( 'dt_crm_sync_poll_' . $filter_id ); // legacy
                if ( wp_next_scheduled( 'dt_crm_sync_poll', [ $filter_id ] ) ) {
                    self::force_remove_cron_hook( 'dt_crm_sync_poll', [ $filter_id ] );
                }
                delete_option( 'dt_crm_sync_saved_filter_' . $filter_id );
                $cleared++;
            }

            wp_clear_scheduled_hook( 'dt_crm_sync_process_batch' );
            update_option( 'dt_crm_sync_saved_filters', [] );

            return new WP_REST_Response( [ 'status' => 'purged', 'filters_cleared' => $cleared ], 200 );
        }

        /**
         * Walk the raw cron array and remove a specific hook+args pair directly.
         * Only called when wp_clear_scheduled_hook() didn't do the job — usually
         * a sign the cron option is in an unexpected state on this environment.
         */
        private static function force_remove_cron_hook( string $hook, array $args ): void {
            $cron = _get_cron_array();
            if ( ! is_array( $cron ) ) {
                return;
            }

            // WP keys each scheduled event internally by the md5 of the serialized args.
            $key = md5( serialize( $args ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Reproducing WP's internal cron key scheme.
            foreach ( $cron as $timestamp => $hooks ) {
                if ( isset( $hooks[ $hook ][ $key ] ) ) {
                    unset( $cron[ $timestamp ][ $hook ][ $key ] );
                    if ( empty( $cron[ $timestamp ][ $hook ] ) ) {
                        unset( $cron[ $timestamp ][ $hook ] );
                    }
                    if ( empty( $cron[ $timestamp ] ) ) {
                        unset( $cron[ $timestamp ] );
                    }
                }
            }
            _set_cron_array( $cron );

            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'dt-crm-sync: force_remove_cron_hook fired for ' . $hook . ' — wp_clear_scheduled_hook did not remove the event.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only logging for a genuinely unexpected cron state.
            }
        }

        /**
         * Recursively sanitize a filter_params array so that nested arrays do not
         * cause a TypeError when sanitize_text_field() is applied to a non-string.
         *
         * Scalar values are unslashed then passed through sanitize_text_field().
         * Array values are recursed into so that arbitrarily nested structures are
         * fully sanitized without crashing on PHP 8.
         *
         * @param array $data Raw filter params from the REST request body.
         * @return array Sanitized copy of $data.
         */
        private static function sanitize_filter_params_recursive( array $data ): array {
            $sanitized = [];
            foreach ( $data as $key => $value ) {
                $key = sanitize_key( (string) $key );
                if ( is_array( $value ) ) {
                    $sanitized[ $key ] = self::sanitize_filter_params_recursive( $value );
                } else {
                    $sanitized[ $key ] = sanitize_text_field( wp_unslash( (string) $value ) );
                }
            }
            return $sanitized;
        }
    }
}
