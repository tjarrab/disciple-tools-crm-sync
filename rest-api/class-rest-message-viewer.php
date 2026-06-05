<?php
/**
 * REST controller for the message history viewer.
 *
 * Exposes a single GET route that outputs a full HTML page so the browser can
 * open the conversation log in a new tab without any additional JavaScript.
 *
 * Route: GET /disciple-tools-crm-sync/v1/message/{contact_id}
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Disciple_Tools_CRM_Sync_REST_Message_Viewer' ) ) {
    /**
     * Renders a contact's conversation log as a standalone HTML page.
     *
     * @package Disciple_Tools
     */
    class Disciple_Tools_CRM_Sync_REST_Message_Viewer extends Disciple_Tools_CRM_Sync_Abstract_REST_Controller {

// Route registration

        /**
         * Register the message viewer route.
         */
        public function register_routes(): void {
            register_rest_route(
                self::API_NAMESPACE,
                '/message/(?P<contact_id>[\d]+)',
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'handle_get_message' ],
                    'permission_callback' => [ $this, 'has_permission' ],
                    'args'                => [
                        'contact_id' => [
                            'required'          => true,
                            'sanitize_callback' => 'absint',
                        ],
                    ],
                ]
            );
        }

        /**
         * Allow any logged-in user through the gate; DT_Posts::get_post() enforces
         * contact-level access (sharing/assignment) inside the callback.
         *
         * @return true|\WP_Error
         */
        public function has_permission(): bool|\WP_Error {
            if ( ! is_user_logged_in() ) {
                return new \WP_Error(
                    'rest_not_logged_in',
                    __( 'You must be logged in to view message history.', 'disciple-tools-crm-sync' ),
                    [ 'status' => 401 ]
                );
            }
            return true;
        }

// Route handler

        /**
         * GET /message/{contact_id}
         *
         * Reads the mapped conversation log from the contact's post meta and
         * returns it as a complete HTML page. Outputs error pages instead of
         * JSON so the browser can display them directly in the same tab.
         *
         * @param \WP_REST_Request $request
         */
        public function handle_get_message( \WP_REST_Request $request ): void {
            $contact_id = absint( $request['contact_id'] );

            // Resolve the field key where message history is written.
            $connector = Disciple_Tools_CRM_Sync_Connector_Registry::get_active_connector();
            if ( null === $connector ) {
                $this->output_error_page(
                    404,
                    __( 'Not Found', 'disciple-tools-crm-sync' ),
                    __( 'No connector is configured for this installation.', 'disciple-tools-crm-sync' )
                );
                return;
            }

            $raw_mapping  = get_option( 'dt_crm_sync_field_mapping', [] );
            $target_field = $raw_mapping[ $connector->get_messages_field_key() ]['dt_key'] ?? '';

            if ( '' === $target_field || '__dt_note__' === $target_field || '__skip__' === $target_field ) {
                $this->output_error_page(
                    404,
                    __( 'Not Found', 'disciple-tools-crm-sync' ),
                    __( 'No message history field is configured for this installation.', 'disciple-tools-crm-sync' )
                );
                return;
            }

            // Let DT enforce its own contact sharing / assignment rules.
            $post = DT_Posts::get_post( 'contacts', $contact_id, true, false );
            if ( is_wp_error( $post ) ) {
                $this->output_error_page(
                    403,
                    __( 'Access Denied', 'disciple-tools-crm-sync' ),
                    __( 'You do not have permission to view this contact.', 'disciple-tools-crm-sync' )
                );
                return;
            }

            $text = get_post_meta( $contact_id, $target_field, true );
            if ( empty( $text ) ) {
                $this->output_error_page(
                    404,
                    __( 'No Messages', 'disciple-tools-crm-sync' ),
                    __( 'No message history has been imported for this contact yet.', 'disciple-tools-crm-sync' )
                );
                return;
            }

            $title = sprintf(
                /* translators: %d: DT contact post ID */
                __( 'Message History — Contact #%d', 'disciple-tools-crm-sync' ),
                $contact_id
            );

            $this->output_html_page( $title, $this->render_message_bubbles( $text, $connector->get_label() ) );
        }

// HTML output helpers

        /**
         * Turn the plain-text log stored in post meta into a series of chat bubbles.
         *
         * Each line in the log follows the format written by Message_Importer:
         *   [YYYY-MM-DD, Weekday, HH:MM:SS UTC] Sender: content
         *
         * Lines that don't match (e.g. legacy entries or the header line written
         * by format_html_log) are rendered as neutral system notes so nothing
         * is silently dropped.
         *
         * @param string $text             Plain-text conversation log from post meta.
         * @param string $connector_label  e.g. "Respond.io" — used to identify agent-side senders.
         * @return string HTML string of bubble elements, ready to embed in the page body.
         */
        protected function render_message_bubbles( string $text, string $connector_label ): string {
            $agent_senders = [ 'Agent', 'Internal Note', sanitize_text_field( $connector_label ) ];
            $html          = '';

            // Pass 1 — reassemble entries.
            //
            // Older stored logs (written before the importer normalised embedded
            // newlines) can have a single message spread across several physical
            // lines. Any physical line that doesn't start with '[' is treated as a
            // continuation of the previous entry and joined back on with a space,
            // exactly reversing what the un-normalised storage did.
            $entries = [];
            $current = '';
            foreach ( explode( "\n", $text ) as $raw ) {
                $line = trim( $raw );
                if ( '' === $line ) {
                    continue;
                }
                if ( '[' === $line[0] ) {
                    if ( '' !== $current ) {
                        $entries[] = $current;
                    }
                    $current = $line;
                } else {
                    $current = '' !== $current ? $current . ' ' . $line : $line;
                }
            }
            if ( '' !== $current ) {
                $entries[] = $current;
            }

            // Pass 2 — render each reassembled entry as a chat bubble.
            foreach ( $entries as $entry ) {
                // Expected format: [timestamp] Sender: content
                if ( preg_match( '/^\[([^\]]+)\] ([^:]+): (.+)$/s', $entry, $m ) ) {
                    $timestamp = esc_html( $m[1] );
                    $sender    = esc_html( trim( $m[2] ) );
                    $content   = nl2br( esc_html( trim( $m[3] ) ) );
                    $is_agent  = in_array( trim( $m[2] ), $agent_senders, true );
                    $is_note   = 'Internal Note' === trim( $m[2] );

                    if ( $is_note ) {
                        $bubble_class = 'self-start max-w-prose bg-amber-50 dark:bg-amber-900/30 text-gray-700 dark:text-gray-200 border border-amber-200 dark:border-amber-700 italic rounded-xl px-4 py-2 text-sm';
                        $label_class  = 'text-xs text-amber-600 dark:text-amber-400 mb-1';
                    } elseif ( $is_agent ) {
                        $bubble_class = 'self-end max-w-prose bg-blue-500 text-white rounded-2xl rounded-tr-sm px-4 py-2';
                        $label_class  = 'text-xs text-blue-200 mb-1 text-right';
                    } else {
                        $bubble_class = 'self-start max-w-prose bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 rounded-2xl rounded-tl-sm px-4 py-2 shadow-sm';
                        $label_class  = 'text-xs text-gray-400 dark:text-gray-500 mb-1';
                    }

                    $align = $is_agent ? 'items-end' : 'items-start';

                    $html .= '<div class="flex flex-col ' . $align . ' gap-0.5">';
                    $html .= '<span class="' . $label_class . '">' . $sender . ' &middot; ' . $timestamp . '</span>';
                    $html .= '<div class="' . $bubble_class . '">' . $content . '</div>';
                    $html .= '</div>';
                } else {
                    // Unrecognised entry — render as a centred system note so it's visible but unobtrusive.
                    $html .= '<div class="self-center text-xs text-gray-400 dark:text-gray-500 italic py-1">' . esc_html( $entry ) . '</div>';
                }
            }

            return $html;
        }

        /**
         * Send a self-contained HTML error page and exit.
         *
         * @param int    $status  HTTP status code.
         * @param string $heading Short heading shown on the page.
         * @param string $detail  Human-readable explanation.
         */
        private function output_error_page( int $status, string $heading, string $detail ): void {
            status_header( $status );
            $body = '<p style="font-size:1.1em;margin-bottom:0.5em"><strong>' . esc_html( $heading ) . '</strong></p>'
                . '<p style="color:#555">' . esc_html( $detail ) . '</p>';
            $this->output_html_page( esc_html( $heading ), $body );
        }

        /**
         * Emit a complete HTML page, set the Content-Type header, and exit.
         *
         * $body is trusted HTML — callers are responsible for escaping before
         * passing it in. render_message_bubbles() escapes all user-derived content
         * before building its output; output_error_page() escapes inline.
         *
         * @param string $title Page <title> text.
         * @param string $body  HTML body content (already escaped by caller).
         */
        private function output_html_page( string $title, string $body ): void {
            header( 'Content-Type: text/html; charset=UTF-8' );
            // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- $title and $body are escaped before this point.
            echo '<!DOCTYPE html>';
            echo '<html lang="' . esc_attr( get_locale() ) . '">';
            echo '<head>';
            echo '<meta charset="UTF-8">';
            echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
            echo '<title>' . esc_html( $title ) . '</title>';
            echo '<script src="https://cdn.tailwindcss.com"></script>'; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- standalone REST page, no WP head available
            echo '<script>tailwind.config = { darkMode: "media" }</script>';
            echo '</head>';
            echo '<body class="bg-gray-100 dark:bg-gray-900 text-gray-900 dark:text-gray-100 min-h-screen p-4 sm:p-8">';
            echo '<div class="max-w-2xl mx-auto">';
            echo '<h1 class="text-base font-semibold text-gray-700 dark:text-gray-300 border-b border-gray-200 dark:border-gray-700 pb-3 mb-6">' . esc_html( $title ) . '</h1>';
            echo '<div class="flex flex-col gap-3">' . $body . '</div>';
            echo '</div>';
            echo '</body>';
            echo '</html>';
            // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
            exit;
        }
    }
}
