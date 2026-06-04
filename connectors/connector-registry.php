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

            // Walk the stored credentials and decrypt anything that looks like it
            // came through encrypt_value(). Plain values (URLs, unencrypted tokens)
            // pass straight through. If a value matches the ciphertext format but
            // decryption fails — corrupt key, tampered data, key rotation — we bail
            // out entirely rather than passing garbage to the connector.
            $credentials = [];
            foreach ( $stored_credentials as $key => $value ) {
                if ( is_string( $value ) && '' !== $value ) {
                    if ( self::is_encrypted_value( $value ) ) {
                        $decrypted = Disciple_Tools_CRM_Sync::decrypt_value( $value );
                        if ( false === $decrypted ) {
                            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                                error_log( '[DT CRM Sync] Credential "' . $key . '" could not be decrypted. Re-save credentials in the plugin settings.' );
                            }
                            return null;
                        }
                        $credentials[ $key ] = $decrypted;
                    } else {
                        $credentials[ $key ] = $value;
                    }
                } else {
                    $credentials[ $key ] = $value;
                }
            }

            return self::make( $slug, $credentials );
        }

        /**
         * Returns true if a stored credential value matches the ciphertext format
         * produced by encrypt_value(): strict base64 that decodes to at least 32 bytes
         * (16-byte IV + at least one AES block).
         *
         * Used to tell encrypted credentials apart from plain-text values like API URLs
         * or tokens that happen to be base64-safe strings.
         *
         * @param string $value The stored credential value to inspect.
         */
        private static function is_encrypted_value( string $value ): bool {
            $decoded = base64_decode( $value, true );
            return false !== $decoded && strlen( $decoded ) >= 32;
        }
    }
}
