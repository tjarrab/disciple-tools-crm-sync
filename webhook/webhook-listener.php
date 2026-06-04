<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Disciple_Tools_CRM_Sync_Webhook' ) ) {
    /**
     * Handles incoming Respond.io webhook payloads.
     *
     * Registers POST disciple-tools-crm-sync/v1/webhook, verifies the
     * HMAC-SHA256 signature, and schedules a batch import for the
     * identified contact.
     *
     * @package Disciple_Tools
     */
    class Disciple_Tools_CRM_Sync_Webhook {

        private static ?self $instance = null;

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
         * Registers the webhook REST route via the rest_api_init hook.
         *
         * @return void
         */
        private function __construct() {
            add_action( 'rest_api_init', [ $this, 'register_route' ] );
        }

        /**
         * Registers POST disciple-tools-crm-sync/v1/webhook.
         *
         * permission_callback is '__return_true' because Respond.io delivers
         * payloads without any WordPress credentials. All authentication is
         * performed inside handle_webhook() via HMAC-SHA256.
         */
        public function register_route(): void {
            register_rest_route( 'disciple-tools-crm-sync/v1', '/webhook', [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'handle_webhook' ],
                'permission_callback' => '__return_true',
            ] );
        }

        /**
         * Authenticates the incoming payload via HMAC-SHA256, extracts the
         * contact ID, and schedules a batch import. Returns 200 before any
         * import work begins so Respond.io never times out waiting for a reply.
         *
         * @param WP_REST_Request $request Incoming REST request.
         * @return WP_REST_Response
         */
        public function handle_webhook( WP_REST_Request $request ): WP_REST_Response {

            $connector = Disciple_Tools_CRM_Sync_Connector_Registry::get_active_connector();

            if ( null === $connector ) {
                return new WP_REST_Response( __( 'Service Unavailable', 'disciple-tools-crm-sync' ), 503 );
            }

            //
            // $request->get_body() returns the raw bytes WP captured from
            // php://input before constructing the request object.
            //
            // The connector declares the normalised header name to expect
            // (WP normalises headers to lowercase + underscores).
            $raw_body   = $request->get_body();
            $header_key = $connector->get_webhook_header();
            $sig        = ! empty( $header_key ) ? $request->get_header( $header_key ) : '';

            if ( empty( $sig ) ) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged,WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging gated on WP_DEBUG.
                    error_log(
                        'DT CRM Sync webhook: signature header not found. Expected: ' . $header_key
                        . '. Headers received: ' . wp_json_encode( $request->get_headers() )
                    );
                }
                return new WP_REST_Response( __( 'Unauthorized', 'disciple-tools-crm-sync' ), 401 );
            }

            //
            // verify_webhook() uses hash_equals() internally for constant-time
            // comparison — no timing-based side-channel attack is possible.
            if ( ! $connector->verify_webhook( $raw_body, $sig ) ) {
                return new WP_REST_Response( __( 'Unauthorized', 'disciple-tools-crm-sync' ), 401 );
            }

            // Per-IP rate limit enforced after HMAC verification so forged
            // requests can't drain the quota of legitimate senders.
            // Limit: 30 verified deliveries per 60-second window per IP.
            // The remote IP is hashed with wp_hash() so no raw address is
            // persisted in the options table.
            $remote_ip = '';
            if ( isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
                // Take only the first (client-supplied) IP from a proxy chain.
                $forwarded = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
                $remote_ip = trim( explode( ',', $forwarded )[0] );
            } elseif ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
                $remote_ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
            }

            $rl_limit  = 30;
            $rl_window = 60;
            $rl_key    = 'dt_crm_sync_wh_rl_' . wp_hash( $remote_ip );
            $rl_data   = get_transient( $rl_key );

            if ( ! is_array( $rl_data ) ) {
                $rl_data = [
                    'count'   => 0,
                    'timeout' => time() + $rl_window,
                ];
            }

            if ( $rl_data['count'] >= $rl_limit ) {
                // Determine remaining time for Retry-After header
                $remaining = max( 1, $rl_data['timeout'] - time() );
                $response  = new WP_REST_Response( __( 'Too Many Requests', 'disciple-tools-crm-sync' ), 429 );
                $response->header( 'Retry-After', (string) $remaining );
                return $response;
            }

            // Increment counter; preserve the existing window expiry on subsequent calls
            $rl_data['count']++;
            $remaining = max( 1, $rl_data['timeout'] - time() );
            set_transient( $rl_key, $rl_data, $remaining );

            $payload = $request->get_json_params();
            if ( ! is_array( $payload ) ) {
                // Payload is not valid JSON or Content-Type was not application/json.
                // Return 200 so Respond.io does not retry delivery indefinitely.
                return new WP_REST_Response( [ 'status' => 'ignored', 'reason' => 'invalid_payload' ], 200 );
            }

            $event_type = $payload['event'] ?? '';
            if ( ! is_string( $event_type ) ) {
                $event_type = '';
            }

            $respond_id = absint( $payload['contact']['id'] ?? 0 );

            if ( empty( $respond_id ) ) {
                // No contact ID in the payload — nothing to import.
                return new WP_REST_Response( [ 'status' => 'ignored', 'reason' => 'no_contact_id' ], 200 );
            }

            //
            // Message events (new_incoming_message, new_outgoing_message,
            // new_comment) are included so that real-time delivery triggers a
            // full contact sync, which imports the latest message history.
            $handled_events = [
                'contact_tag_updated',
                'new_contact',
                'contact_updated',
                'contact_assignee_updated',
                'contact_lifecycle_updated',
                'new_incoming_message',
                'new_outgoing_message',
                'new_comment',
            ];

            if ( ! in_array( $event_type, $handled_events, true ) ) {
                return new WP_REST_Response( [ 'status' => 'ignored', 'reason' => 'unhandled_event' ], 200 );
            }

            //
            // The listener does no import work itself — it only validates and
            // enqueues, then returns immediately. The 5-second offset gives the
            // database transaction time to commit before WP-Cron picks up the job.
            //
            // _token is a unique value added to every wp_schedule_single_event
            // args array. WP-Cron deduplicates events that share the same hook
            // name AND identical args; the token ensures rapid back-to-back
            // webhooks for the same contact each get their own scheduled event.
            //
            // _trigger is read by Disciple_Tools_CRM_Sync_Processor and passed through to
            // Disciple_Tools_CRM_Sync_Logger::write() so logs correctly show 'webhook'.
            //
            // If scheduling fails we return 500 so Respond.io retries delivery
            // rather than treating the event as successfully processed.
            $scheduled = wp_schedule_single_event(
                time() + 5,
                'dt_crm_sync_process_batch',
                [
                [
                    'ids'            => [ (int) $respond_id ],
                    '_token'         => bin2hex( random_bytes( 8 ) ),
                    '_trigger'       => 'webhook',
                    '_skip_existing' => false,
                ]
                ]
            );

            if ( false === $scheduled ) {
                return new WP_REST_Response( [ 'status' => 'error', 'reason' => 'scheduling_failed' ], 500 );
            }

            return new WP_REST_Response( [ 'status' => 'queued' ], 200 );
        }
    }
}
