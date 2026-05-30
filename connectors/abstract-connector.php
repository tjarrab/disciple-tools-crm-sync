<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Disciple_Tools_CRM_Sync_Abstract_Connector' ) ) {
    /**
     * Base class every CRM connector must extend.
     *
     * Implement all abstract methods. Optional capabilities (webhooks, messages,
     * schema caching) have default no-op implementations so connectors that
     * don't need them don't have to stub them out.
     *
     * @package Disciple_Tools
     */
    abstract class Disciple_Tools_CRM_Sync_Abstract_Connector {

        /**
         * Decrypted connector credentials, keyed by field slug.
         * e.g. [ 'api_url' => 'https://…', 'api_token' => 'plaintext_token' ]
         *
         * @var array<string, string>
         */
        protected array $credentials;

        /**
         * @param array<string, string> $credentials Decrypted credentials for this connector instance.
         * @throws \LogicException If the concrete class returns a malformed meta key prefix.
         */
        public function __construct( array $credentials ) {
            $this->credentials = $credentials;

            // Validate the meta key prefix contract at instantiation time so a
            // misconfigured subclass fails fast with a clear error rather than
            // silently corrupting post-meta keys throughout the import lifecycle.
            $prefix = $this->get_meta_key_prefix();
            if ( ! preg_match( '/^_.*_$/', $prefix ) ) {
                throw new \LogicException(
                    static::class . '::get_meta_key_prefix() must return a string that starts and ends with "_"; got "' . $prefix . '".'
                );
            }
        }

        /**
         * Machine-readable slug that uniquely identifies this connector.
         * Used as array key in the `dt_crm_sync_connectors` filter and in
         * `dt_crm_sync_settings['active_connector']`.
         * Must be a valid `sanitize_key()` value (lowercase, no spaces).
         *
         * @return string e.g. 'respond_io'
         */
        abstract public function get_slug(): string;

        /**
         * Human-readable label shown in the admin connector dropdown.
         *
         * @return string e.g. 'Respond.io'
         */
        abstract public function get_label(): string;

        /**
         * Returns an ordered list of credential field definitions to render on
         * the Configuration tab. Each entry is an associative array:
         *
         *   [ 'slug' => string, 'label' => string, 'type' => 'text|password|url' ]
         *
         * @return array<int, array<string, string>>
         */
        abstract public function get_credential_fields(): array;

        /**
         * Value stored in the DT contact 'sources' field for contacts imported
         * by this connector.
         *
         * @return string e.g. 'respond_io'
         */
        abstract public function get_dt_source_slug(): string;

        /**
         * Human-readable label for the DT contact source entry.
         *
         * @return string e.g. 'Respond.io'
         */
        abstract public function get_dt_source_label(): string;

        /**
         * Prefix prepended to all post-meta keys written by the import processor.
         * Must start with '_' and end with '_'.
         * Used by the processor to build keys like: prefix . 'id', prefix . 'last_sync'.
         *
         * @return string e.g. '_respond_io_'
         */
        abstract public function get_meta_key_prefix(): string;

        /**
         * Verify that the stored credentials are valid by making a lightweight
         * authenticated request to the remote API.
         *
         * @return bool|WP_Error true on success, WP_Error on failure.
         */
        abstract public function test_connection(): bool|\WP_Error;

        /**
         * Fetch the remote CRM's custom field schema.
         * Implementations are encouraged to cache the result (e.g. with a transient).
         *
         * @return array|WP_Error Schema data on success, WP_Error on failure.
         */
        abstract public function get_field_schema(): array|\WP_Error;

        /**
         * Fetch a paginated list of contacts matching the provided filter parameters.
         *
         * The $filter_params array contains generic key→value pairs defined by
         * get_filter_fields(). Each concrete connector translates them into the
         * API request format it requires.
         *
         * Return shape (normalised across all connectors):
         *   [
         *     'data'   => array,       // array of raw contact objects/arrays
         *     'cursor' => [ 'next' => int|null ],  // null = no more pages
         *   ]
         *
         * @param array       $filter_params Generic filter values, e.g. ['search'=>'…','tag'=>'…'].
         * @param string|null $cursor        Opaque cursor value from the previous page, or null for page 1.
         * @param int         $limit         Page size.
         * @return array|WP_Error
         */
        abstract public function get_contacts( array $filter_params, ?string $cursor = null, int $limit = 50 ): array|\WP_Error;

        /**
         * Fetch a single contact's full profile by its connector-native ID.
         *
         * @param string $id Connector-native contact identifier.
         * @return array|WP_Error Raw contact profile on success, WP_Error on failure.
         */
        abstract public function get_contact( string $id ): array|\WP_Error;

        /**
         * Returns an ordered list of filter field definitions rendered by both
         * the Automations tab (PHP) and the FilterBar React component (JS via
         * wp_localize_script).
         *
         * Each entry is an associative array:
         *   [
         *     'slug'    => string,           // HTML name attribute + filter_params key
         *     'label'   => string,           // Input label text
         *     'type'    => 'text|select',    // Input type
         *     'options' => array (optional), // For 'select' type: [ value => label ]
         *   ]
         *
         * @return array<int, array<string, mixed>>
         */
        abstract public function get_filter_fields(): array;

        /**
         * Fetch a paginated list of messages for a contact.
         * Returns an empty array by default for connectors that do not support
         * message history.
         *
         * Return shape (normalised):
         *   [ 'data' => array, 'cursor' => [ 'next' => int|null ] ]
         *
         * @param string      $contact_id Connector-native contact identifier.
         * @param string|null $cursor     Cursor for pagination.
         * @param int         $limit      Page size.
         * @return array|WP_Error
         */
        public function get_messages( string $contact_id, ?string $cursor = null, int $limit = 100 ): array|\WP_Error { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Default stub; parameters required by subclass contract.
            return [ 'data' => [], 'cursor' => [ 'next' => null ] ];
        }

        /**
         * Return the HTTP header name carrying the webhook HMAC signature.
         * WP_REST_Request::get_header() normalises names to lowercase with
         * underscores, so return the normalised form (e.g. 'x_webhook_signature').
         * Return an empty string for connectors that do not support webhooks.
         *
         * @return string
         */
        public function get_webhook_header(): string {
            return '';
        }

        /**
         * Verify the HMAC signature of an incoming webhook payload.
         * Returns false by default (reject all) for connectors that do not
         * support webhooks.
         *
         * @param string $raw_body  Raw request body bytes.
         * @param string $signature Signature value from the webhook header.
         * @return bool
         */
        public function verify_webhook( string $raw_body, string $signature ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Default stub; parameters required by subclass contract.
            return false;
        }

        /**
         * Invalidate any locally cached field schema so the next call to
         * get_field_schema() performs a live fetch.
         * No-op by default for connectors without schema caching.
         */
        public function refresh_schema_cache(): void {}

        /**
         * Returns synthetic field definitions to include in the field-mapping UI
         * alongside the fields returned by the remote API.
         *
         * Synthetic fields represent top-level contact profile properties that the
         * remote API does not expose through its custom-field schema endpoint
         * (e.g. Lifecycle stage on Respond.io).
         *
         * This method must not require credentials and must never call
         * get_client(). It is invoked on display-only connector instances that are
         * constructed with empty credentials for admin UI rendering only.
         *
         * Each entry must match the shape of a single item from get_field_schema():
         *   [ 'name' => string, 'type' => string, '__synthetic' => true ]
         *
         * @return array<int, array<string, mixed>>
         */
        public function get_synthetic_schema_fields(): array {
            return [];
        }
    }
}
