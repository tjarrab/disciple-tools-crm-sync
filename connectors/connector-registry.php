<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Disciple_Tools_CRM_Sync_Connector_Registry' ) ) {
    /**
     * Central registry for CRM connector discovery and instantiation.
     *
     * Connectors add themselves via the 'dt_crm_sync_connectors' filter.
     * The registry resolves the currently active connector from plugin settings
     * and handles credential decryption before handing the instance to callers.
     *
     * @package Disciple_Tools
     */
    class Disciple_Tools_CRM_Sync_Connector_Registry {

        /**
         * Returns all registered connector class names, keyed by slug.
         *
         * @return array<string, class-string<Disciple_Tools_CRM_Sync_Abstract_Connector>>
         */
        public static function get_connectors(): array {
            return apply_filters( 'dt_crm_sync_connectors', [] );
        }

        /**
         * Instantiate a connector by slug with the provided (already-decrypted) credentials.
         *
         * @param string               $slug        Connector slug (e.g. 'respond_io').
         * @param array<string,string> $credentials Decrypted credentials for this connector.
         * @return Disciple_Tools_CRM_Sync_Abstract_Connector|null
         *         Returns null if the slug is not registered.
         */
        public static function make( string $slug, array $credentials ): ?Disciple_Tools_CRM_Sync_Abstract_Connector {
            $connectors = self::get_connectors();

            if ( ! isset( $connectors[ $slug ] ) ) {
                return null;
            }

            $class = $connectors[ $slug ];

            if ( ! class_exists( $class ) ) {
                return null;
            }

            if ( ! is_a( $class, 'Disciple_Tools_CRM_Sync_Abstract_Connector', true ) ) {
                return null;
            }

            return new $class( $credentials );
        }

        /**
         * Returns a [ slug => label ] map for all registered connectors.
         * Used to populate the admin connector-selector dropdown.
         *
         * Each connector class is instantiated with an empty credentials array;
         * only get_label() is called — no network requests are made.
         *
         * @return array<string, string>
         */
        public static function get_labels(): array {
            $labels     = [];
            $connectors = self::get_connectors();

            foreach ( $connectors as $slug => $class ) {
                if ( ! class_exists( $class ) ) {
                    continue;
                }
                if ( ! is_a( $class, 'Disciple_Tools_CRM_Sync_Abstract_Connector', true ) ) {
                    continue;
                }
                // get_label() MUST NOT access $this->credentials — connectors are
                // instantiated here with an empty array solely to retrieve their label.
                // A connector whose constructor or get_label() accesses credentials will
                // throw here and be skipped rather than breaking the entire dropdown.
                try {
                    /** @var Disciple_Tools_CRM_Sync_Abstract_Connector $instance */
                    $instance        = new $class( [] );
                    $labels[ $slug ] = $instance->get_label();
                } catch ( \Throwable $e ) {
                    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                        error_log( '[DT CRM Sync] Connector "' . $slug . '" failed to load: ' . $e->getMessage() );
                    }
                    continue;
                }
            }

            return $labels;
        }

        /**
         * Resolve and return the currently active connector, fully initialised
         * with its stored (decrypted) credentials.
         *
         * Reads `dt_crm_sync_settings`:
         *   - `active_connector` — slug of the chosen connector
         *   - `connectors[$slug]` — stored credential values (encrypted)
         *
         * Credentials are decrypted here before being passed to make(), so
         * connector implementations always receive plaintext values.
         *
         * Returns null when:
         *   - No connector is configured
         *   - The configured slug is not registered
         *   - Credential decryption fails
         *
         * @return Disciple_Tools_CRM_Sync_Abstract_Connector|null
         */
        public static function get_active_connector(): ?Disciple_Tools_CRM_Sync_Abstract_Connector {
            $settings = get_option( 'dt_crm_sync_settings', [] );
            if ( ! is_array( $settings ) ) {
                $settings = [];
            }

            $slug = sanitize_key( $settings['active_connector'] ?? '' );
            if ( empty( $slug ) ) {
                return null;
            }

            $stored_credentials = $settings['connectors'][ $slug ] ?? [];
            if ( ! is_array( $stored_credentials ) ) {
                $stored_credentials = [];
            }

            // Decrypt any credential values that look like encrypted ciphertext
            // (non-empty strings that are not already plaintext URLs/keys).
            // Connectors declare which fields are sensitive via get_credential_fields()
            // type='password'. We decrypt all stored credential values and let each
            // connector decide which ones it needs as plaintext.
            $credentials = [];
            foreach ( $stored_credentials as $key => $value ) {
                if ( is_string( $value ) && '' !== $value ) {
                    $decrypted = Disciple_Tools_CRM_Sync::decrypt_value( $value );
                    // decrypt_value() returns false if the value is not encrypted
                    // ciphertext (e.g. a plain URL). In that case keep original.
                    $credentials[ $key ] = ( false !== $decrypted ) ? $decrypted : $value;
                } else {
                    $credentials[ $key ] = $value;
                }
            }

            return self::make( $slug, $credentials );
        }
    }
}
