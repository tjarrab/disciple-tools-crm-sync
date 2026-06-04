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

            $this->output_html_page( $title, nl2br( esc_html( $text ) ) );
        }

// HTML output helpers

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
         * Using nl2br(esc_html()) on the caller side keeps this method agnostic
         * about whether $body is already HTML.
         *
         * @param string $title Page <title> text.
         * @param string $body  HTML body content (trusted — already escaped by caller).
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
            echo '<style>';
            echo 'body{margin:0;padding:2rem;font-family:system-ui,-apple-system,sans-serif;font-size:1rem;line-height:1.7;background:#f9f9f9;color:#1d1d1d}';
            echo '.container{max-width:780px;margin:0 auto;background:#fff;padding:2rem 2.5rem;border-radius:6px;box-shadow:0 1px 4px rgba(0,0,0,.1)}';
            echo 'h1{font-size:1.2rem;margin-top:0;color:#1e1e1e;border-bottom:1px solid #e5e5e5;padding-bottom:0.75rem;margin-bottom:1.5rem}';
            echo '.log{white-space:pre-wrap;word-break:break-word}';
            echo '</style>';
            echo '</head>';
            echo '<body>';
            echo '<div class="container">';
            echo '<h1>' . esc_html( $title ) . '</h1>';
            echo '<div class="log">' . $body . '</div>';
            echo '</div>';
            echo '</body>';
            echo '</html>';
            // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
            exit;
        }
    }
}
