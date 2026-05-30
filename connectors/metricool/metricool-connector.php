<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Disciple_Tools_CRM_Sync_Connector_Metricool' ) ) {
    /**
     * Metricool connector. Contacts come from conversation participants
     * since Metricool has no dedicated contacts endpoint.
     *
     * @package Disciple_Tools
     */
    class Disciple_Tools_CRM_Sync_Connector_Metricool extends Disciple_Tools_CRM_Sync_Abstract_Connector {

        /**
         * Lazily-initialised API client instance.
         * Populated on first call to get_client().
         *
         * @var Disciple_Tools_CRM_Sync_Metricool_API_Client|null
         */
        private ?Disciple_Tools_CRM_Sync_Metricool_API_Client $client = null;

        /** {@inheritDoc} */
        public function get_slug(): string {
            return 'metricool';
        }

        /** {@inheritDoc} */
        public function get_label(): string {
            return 'Metricool';
        }

        /**
         * Returns the credential field definitions rendered by the admin settings form.
         *
         * Four fields are required: user token (encrypted), user ID, blog/brand ID,
         * and the social platform provider. The provider drives which inbox conversations
         * are fetched and is also required by get_contact() which has no filter context.
         *
         * @return array
         */
        public function get_credential_fields(): array {
            return [
                [
                    'slug'  => 'user_token',
                    'label' => __( 'API User Token', 'disciple-tools-crm-sync' ),
                    'type'  => 'password',
                ],
                [
                    'slug'  => 'user_id',
                    'label' => __( 'User ID', 'disciple-tools-crm-sync' ),
                    'type'  => 'text',
                ],
                [
                    'slug'  => 'blog_id',
                    'label' => __( 'Brand / Blog ID', 'disciple-tools-crm-sync' ),
                    'type'  => 'text',
                ],
                [
                    'slug'    => 'provider',
                    'label'   => __( 'Social Platform', 'disciple-tools-crm-sync' ),
                    'type'    => 'select',
                    'options' => [
                        'INSTAGRAM'        => __( 'Instagram (Personal)', 'disciple-tools-crm-sync' ),
                        'INSTAGRAMBUSINESS' => __( 'Instagram (Business)', 'disciple-tools-crm-sync' ),
                        'TWITTER'          => __( 'X / Twitter', 'disciple-tools-crm-sync' ),
                        'FACEBOOK'         => __( 'Facebook', 'disciple-tools-crm-sync' ),
                        'GMB'              => __( 'Google Business Profile', 'disciple-tools-crm-sync' ),
                        'TIKTOKBUSINESS'   => __( 'TikTok Business', 'disciple-tools-crm-sync' ),
                        'YOUTUBE'          => __( 'YouTube', 'disciple-tools-crm-sync' ),
                        'LINKEDIN'         => __( 'LinkedIn', 'disciple-tools-crm-sync' ),
                    ],
                ],
            ];
        }

        /** {@inheritDoc} */
        public function get_dt_source_slug(): string {
            return 'metricool';
        }

        /** {@inheritDoc} */
        public function get_dt_source_label(): string {
            return 'Metricool';
        }

        /** {@inheritDoc} */
        public function get_meta_key_prefix(): string {
            return '_metricool_';
        }

        /**
         * Returns the filter parameter fields exposed in the filter-creation UI.
         *
         * One field: conversation status. Because `status` is not an accepted query
         * parameter in the Metricool API (the spec only documents `provider`), this
         * filter is applied client-side in get_contacts() after fetching all
         * conversations. An empty / unset status means "return all conversations".
         *
         * @return array
         */
        public function get_filter_fields(): array {
            return [
                [
                    'slug'    => 'status',
                    'label'   => __( 'Conversation Status', 'disciple-tools-crm-sync' ),
                    'type'    => 'select',
                    'options' => [
                        ''         => __( '(Any)', 'disciple-tools-crm-sync' ),
                        'PENDING'  => __( 'Pending', 'disciple-tools-crm-sync' ),
                        'READ'     => __( 'Read', 'disciple-tools-crm-sync' ),
                        'RESOLVED' => __( 'Resolved', 'disciple-tools-crm-sync' ),
                    ],
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
         * Metricool has no custom attribute schema.
         * The field-mapping UI will display "No fields available to map."
         *
         * {@inheritDoc}
         */
        public function get_field_schema(): array|\WP_Error {
            return [];
        }

        /**
         * Fetch all unique conversation participants for the configured provider.
         * Paginates through all API pages in a single call; $cursor and $limit
         * follow the abstract contract but Metricool cursor-paginates internally.
         *
         * {@inheritDoc}
         */
        public function get_contacts( array $filter_params, ?string $cursor = null, int $limit = 50 ): array|\WP_Error {
            $client = $this->get_client();
            if ( is_wp_error( $client ) ) {
                return $client;
            }

            // Clear the cache so get_contact() calls during this session use fresh data.
            $client->clear_conversation_cache();

            $status_filter = $filter_params['status'] ?? '';

            // Collect all conversations across all pages.
            $all_conversations = [];
            $api_cursor        = null;

            do {
                $response = $client->get_conversations( $api_cursor );
                if ( is_wp_error( $response ) ) {
                    return $response;
                }

                $conversations = $response['data'] ?? [];
                foreach ( $conversations as $conversation ) {
                    // Apply client-side status filter.
                    if ( ! empty( $status_filter ) && ( $conversation['status'] ?? '' ) !== $status_filter ) {
                        continue;
                    }
                    $all_conversations[] = $conversation;
                }

                $api_cursor = $response['cursor']['next'] ?? null;
            } while ( ! empty( $api_cursor ) );

            // Extract unique participants across all retained conversations.
            $seen_ids     = [];
            $participants = [];
            foreach ( $all_conversations as $conversation ) {
                foreach ( $conversation['participants'] ?? [] as $participant ) {
                    $pid = $participant['id'] ?? '';
                    if ( empty( $pid ) || isset( $seen_ids[ $pid ] ) ) {
                        continue;
                    }
                    $seen_ids[ $pid ] = true;
                    $participants[]   = $this->normalize_participant( $participant );
                }
            }

            return [
                'data'   => $participants,
                'cursor' => [ 'next' => null ], // All pages consumed above; no further cursor.
            ];
        }

        /**
         * Return a single normalized contact profile by participant ID.
         *
         * There is no single-participant endpoint in the Metricool API.
         * This method fetches the conversation list (reading from the 5-minute
         * transient cache when available) and scans all participants to find
         * the one whose `id` matches `$id`.
         *
         * {@inheritDoc}
         */
        public function get_contact( string $id ): array|\WP_Error {
            $client = $this->get_client();
            if ( is_wp_error( $client ) ) {
                return $client;
            }

            // Fetch the first page only — the cache is keyed to first-page calls.
            // If the participant list spans multiple pages they will all have been
            // cached by get_contacts() earlier in the same import session.
            $response = $client->get_conversations( null );
            if ( is_wp_error( $response ) ) {
                return $response;
            }

            // Scan all conversations on the cached page.
            foreach ( $response['data'] ?? [] as $conversation ) {
                foreach ( $conversation['participants'] ?? [] as $participant ) {
                    if ( ( $participant['id'] ?? '' ) === $id ) {
                        return $this->normalize_participant( $participant );
                    }
                }
            }

            return new \WP_Error(
                'contact_not_found',
                sprintf(
                    /* translators: %s: participant ID */
                    __( 'Metricool participant %s not found in the conversation list.', 'disciple-tools-crm-sync' ),
                    esc_html( $id )
                )
            );
        }

        /**
         * Normalize a raw Metricool Participant object into the shared contact shape.
         * Splits participant.name into firstName/lastName; phone is unavailable.
         *
         * @param array $participant Raw participant object from the API.
         * @return array Normalized contact profile.
         */
        private function normalize_participant( array $participant ): array {
            $full_name  = trim( $participant['name'] ?? '' );
            $name_parts = preg_split( '/\s+/', $full_name, 2 );
            $first_name = $name_parts[0] ?? '';
            $last_name  = $name_parts[1] ?? '';

            return [
                'id'            => $participant['id'] ?? '',
                'firstName'     => $first_name,
                'lastName'      => $last_name,
                'phone'         => '',
                'email'         => $participant['email'] ?? '',
                'tags'          => [],
                'custom_fields' => [],
            ];
        }

        /**
         * Return the lazily-initialised API client, creating it on first call.
         *
         * Credentials are received already decrypted by
         * Connector_Registry::get_active_connector().
         *
         * @return Disciple_Tools_CRM_Sync_Metricool_API_Client|WP_Error
         */
        private function get_client(): Disciple_Tools_CRM_Sync_Metricool_API_Client|\WP_Error {
            if ( null === $this->client ) {
                $required = [ 'user_token', 'user_id', 'blog_id', 'provider' ];
                foreach ( $required as $field ) {
                    if ( empty( $this->credentials[ $field ] ) ) {
                        return new \WP_Error(
                            'connector_misconfigured',
                            sprintf(
                                /* translators: %s: missing credential field name */
                                __( 'Metricool connector: "%s" credential is required.', 'disciple-tools-crm-sync' ),
                                $field
                            )
                        );
                    }
                }
                $this->client = new Disciple_Tools_CRM_Sync_Metricool_API_Client(
                    $this->credentials['user_token'],
                    $this->credentials['user_id'],
                    $this->credentials['blog_id'],
                    $this->credentials['provider']
                );
            }
            return $this->client;
        }
    }
}

// Connector registration

/**
 * Register the Metricool connector with the plugin's connector registry.
 * Using a named function (not a closure) so it can be removed via remove_filter().
 */
if ( ! function_exists( 'dt_crm_sync_register_metricool_connector' ) ) {
    function dt_crm_sync_register_metricool_connector( array $connectors ): array {
        $connectors['metricool'] = 'Disciple_Tools_CRM_Sync_Connector_Metricool';
        return $connectors;
    }
    add_filter( 'dt_crm_sync_connectors', 'dt_crm_sync_register_metricool_connector' );
}
