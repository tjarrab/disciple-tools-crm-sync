<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Disciple_Tools_CRM_Sync_Connector_Respond_IO' ) ) {
    /**
     * Respond.io connector. API calls go through Disciple_Tools_CRM_Sync_API_Client,
     * which is lazily instantiated from the decrypted credentials passed at construction.
     *
     * @package Disciple_Tools
     */
    class Disciple_Tools_CRM_Sync_Connector_Respond_IO extends Disciple_Tools_CRM_Sync_Abstract_Connector {

        /**
         * Lazily-initialised API client instance.
         * Populated on first call to get_client().
         *
         * @var Disciple_Tools_CRM_Sync_API_Client|null
         */
        private ?Disciple_Tools_CRM_Sync_API_Client $client = null;

        /** {@inheritDoc} */
        public function get_slug(): string {
            return 'respond_io';
        }

        /** {@inheritDoc} */
        public function get_label(): string {
            return 'Respond.io';
        }

        /**
         * Returns the credential field definitions rendered by the admin settings form.
         *
         * Three fields are required: the base API URL, the API access token, and the
         * webhook signing key. Fields typed 'password' are rendered as masked inputs
         * and stored encrypted via Disciple_Tools_CRM_Sync::encrypt_value().
         *
         * @return array
         */
        public function get_credential_fields(): array {
            return [
                [
                    'slug'    => 'api_url',
                    'label'   => __( 'Base URL', 'disciple-tools-crm-sync' ),
                    'type'    => 'url',
                    'default' => 'https://api.respond.io',
                ],
                [
                    'slug'  => 'api_token',
                    'label' => __( 'API Access Token', 'disciple-tools-crm-sync' ),
                    'type'  => 'password',
                ],
                [
                    'slug'  => 'webhook_signing_key',
                    'label' => __( 'Webhook Signing Key', 'disciple-tools-crm-sync' ),
                    'type'  => 'password',
                ],
            ];
        }

        /** {@inheritDoc} */
        public function get_dt_source_slug(): string {
            return 'respond_io';
        }

        /** {@inheritDoc} */
        public function get_dt_source_label(): string {
            return 'Respond.io';
        }

        /** {@inheritDoc} */
        public function get_meta_key_prefix(): string {
            return '_respond_io_';
        }

        /**
         * Returns the filter parameter field definitions exposed in the filter-creation UI.
         *
         * Three fields are supported: a free-text search query, a tag filter, and a
         * lifecycle filter. Tag and lifecycle share the same exclusive_group value so
         * the UI knows to clear one when the other is filled in — only one can be sent
         * per request. Both are translated into Respond.io API filter conditions in
         * get_contacts().
         *
         * @return array
         */
        public function get_filter_fields(): array {
            return [
                [
                    'slug'  => 'search',
                    'label' => __( 'Search Query', 'disciple-tools-crm-sync' ),
                    'type'  => 'text',
                ],
                [
                    'slug'            => 'tag',
                    'label'           => __( 'Tag', 'disciple-tools-crm-sync' ),
                    'type'            => 'text',
                    'exclusive_group' => 'contact_filter',
                    'group_label'     => __( 'Contact Filter', 'disciple-tools-crm-sync' ),
                ],
                [
                    'slug'            => 'lifecycle',
                    'label'           => __( 'Lifecycle', 'disciple-tools-crm-sync' ),
                    'type'            => 'text',
                    'description'     => __( 'e.g. F2F Ready', 'disciple-tools-crm-sync' ),
                    'exclusive_group' => 'contact_filter',
                ],
            ];
        }

        /** {@inheritDoc} */
        public function test_connection(): bool|\WP_Error {
            $client = $this->get_client();
            if ( is_wp_error( $client ) ) {
                return $client;
            }
            return $client->test_connection();
        }

        /**
         * Returns the Respond.io custom attribute schema, reading from transient cache
         * first and falling back to a live API call if the cache is cold or stale.
         *
         * Appends synthetic entries for top-level contact profile fields not included
         * in /v2/space/custom_field but returned on every contact record.
         *
         * {@inheritDoc}
         *
         * @return array|\WP_Error
         */
        public function get_field_schema(): array|\WP_Error {
            $client = $this->get_client();
            if ( is_wp_error( $client ) ) {
                return $client;
            }
            $schema = $client->get_field_schema();
            if ( is_wp_error( $schema ) ) {
                return $schema;
            }
            // Merge in synthetic top-level profile fields (e.g. Lifecycle) so they
            // appear in drift-detection and import alongside real API custom fields.
            return array_merge( $schema, $this->get_synthetic_schema_fields() );
        }

        /** {@inheritDoc} */
        public function get_synthetic_schema_fields(): array {
            return [
                [
                    'name'        => 'Lifecycle',
                    'type'        => 'text',
                    '__synthetic' => true,
                ],
            ];
        }

        /** {@inheritDoc} */
        public function refresh_schema_cache(): void {
            $client = $this->get_client();
            if ( ! is_wp_error( $client ) ) {
                $client->refresh_schema_cache();
            }
        }

        /**
         * Translates generic filter_params into the Respond.io API filter body
         * and forwards the call to the API client.
         *
         * Tag and lifecycle are mutually exclusive — the UI enforces this, so only one
         * will be present per request. Tag maps to a contactTag hasAnyOf condition;
         * lifecycle maps to a lifecycle isEqualTo condition with a plain string value
         * (not an array, unlike tag).
         *
         * @return array|WP_Error
         */
        public function get_contacts( array $filter_params, ?string $cursor = null, int $limit = 50 ): array|\WP_Error {
            // Resolve IANA timezone — wp_timezone_string() may return a UTC offset.
            $tz = wp_timezone_string();
            if ( preg_match( '/^[+-]/', $tz ) ) {
                $tz = wp_timezone()->getName();
            }

            $and_conditions = [];

            if ( ! empty( $filter_params['tag'] ) ) {
                $and_conditions[] = [
                    'category' => 'contactTag',
                    'field'    => null,
                    'operator' => 'hasAnyOf',
                    'value'    => [ $filter_params['tag'] ],
                ];
            }

            if ( ! empty( $filter_params['lifecycle'] ) ) {
                $and_conditions[] = [
                    'category' => 'lifecycle',
                    'field'    => null,
                    'operator' => 'isEqualTo',
                    'value'    => $filter_params['lifecycle'],
                ];
            }

            $api_filter_body = [
                'search'   => $filter_params['search'] ?? '',
                'filter'   => [ '$and' => $and_conditions ],
                'timezone' => $tz,
            ];

            $client = $this->get_client();
            if ( is_wp_error( $client ) ) {
                return $client;
            }
            return $client->get_contacts( $api_filter_body, $cursor, $limit );
        }

        /** {@inheritDoc} */
        public function get_contact( string $id ): array|\WP_Error {
            $client = $this->get_client();
            if ( is_wp_error( $client ) ) {
                return $client;
            }
            return $client->get_contact( $id );
        }

        /**
         * Fetches a page of messages for a contact using cursor-based pagination.
         *
         * @param string      $contact_id Respond.io contact ID.
         * @param string|null $cursor     Pagination cursor, or null for the first page.
         * @param int         $limit      Maximum messages to return per page.
         * @return array|\WP_Error
         */
        public function get_messages( string $contact_id, ?string $cursor = null, int $limit = 100 ): array|\WP_Error {
            $client = $this->get_client();
            if ( is_wp_error( $client ) ) {
                return $client;
            }
            return $client->get_message_list( $contact_id, $cursor, $limit );
        }

        /**
         * Fetches the social media channels connected to a contact.
         * Delegates to the API client's channel endpoint.
         *
         * @param string $contact_id Respond.io contact ID.
         * @return array|\WP_Error Normalised: { data: [ { source: string, ... }, ... ] }
         */
        public function get_contact_channels( string $contact_id ): array|\WP_Error {
            $client = $this->get_client();
            if ( is_wp_error( $client ) ) {
                return $client;
            }
            return $client->get_contact_channels( $contact_id );
        }

        /**
         * Returns the map of Respond.io channel source slugs to display labels.
         *
         * These are registered as DT contact source options so the Sources dropdown
         * in the DT admin shows readable names. Any slug not listed here falls back
         * to a generic ucwords() transform at registration time.
         *
         * @return array<string, string>
         */
        public function get_platform_source_labels(): array {
            return [
                'facebook'    => 'Facebook',
                'instagram'   => 'Instagram',
                'tiktok'      => 'TikTok',
                'whatsapp'    => 'WhatsApp',
                'telegram'    => 'Telegram',
                'line'        => 'LINE',
                'viber'       => 'Viber',
                'wechat'      => 'WeChat',
                'twitter'     => 'Twitter / X',
                'sms'         => 'SMS',
                'email'       => 'Email',
                'webchat'     => 'Webchat',
                'gb_messages' => 'Google Business Messages',
                'youtube'     => 'YouTube',
            ];
        }

        /**
         * Returns the WP-normalised header name carrying the HMAC signature.
         *
         * WP_REST_Request::get_header() normalises incoming header names to lowercase
         * with underscores, so the Respond.io header 'X-Webhook-Signature' becomes
         * 'x_webhook_signature' by the time it reaches the callback.
         *
         * @return string
         */
        public function get_webhook_header(): string {
            return 'x_webhook_signature';
        }

        /**
         * Verify HMAC-SHA256 signature of an incoming Respond.io webhook payload.
         * The signing key is taken from $this->credentials['webhook_signing_key']
         * (already decrypted by Connector_Registry::get_active_connector()).
         *
         * hash_equals() provides constant-time comparison to prevent timing attacks.
         *
         * @param string $raw_body  Raw request body bytes.
         * @param string $signature Value from the webhook signature header.
         * @return bool
         */
        public function verify_webhook( string $raw_body, string $signature ): bool {
            $signing_key = $this->credentials['webhook_signing_key'] ?? '';

            if ( empty( $signing_key ) || empty( $signature ) ) {
                return false;
            }

            $computed = hash_hmac( 'sha256', $raw_body, $signing_key );
            return hash_equals( $computed, $signature );
        }

        /**
         * Returns the underlying Disciple_Tools_CRM_Sync_API_Client instance,
         * creating it on first call. The API client receives plaintext credentials
         * (decryption is handled upstream by Connector_Registry::get_active_connector()).
         *
         * @return Disciple_Tools_CRM_Sync_API_Client|\WP_Error
         */
        private function get_client(): Disciple_Tools_CRM_Sync_API_Client|\WP_Error {
            if ( null === $this->client ) {
                if ( empty( $this->credentials['api_url'] ) || empty( $this->credentials['api_token'] ) ) {
                    return new \WP_Error(
                        'connector_misconfigured',
                        'Respond.io connector: api_url and api_token are required.'
                    );
                }
                $this->client = new Disciple_Tools_CRM_Sync_API_Client(
                    $this->credentials['api_url'],
                    $this->credentials['api_token']
                );
            }
            return $this->client;
        }
    }
}

/**
 * Register the Respond.io connector with the plugin's connector registry.
 */
if ( ! function_exists( 'dt_crm_sync_register_respond_io_connector' ) ) {
    function dt_crm_sync_register_respond_io_connector( array $connectors ): array {
        $connectors['respond_io'] = 'Disciple_Tools_CRM_Sync_Connector_Respond_IO';
        return $connectors;
    }
    add_filter( 'dt_crm_sync_connectors', 'dt_crm_sync_register_respond_io_connector' );
}
