<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Disciple_Tools_CRM_Sync_Abstract_Translation_Provider' ) ) {
    /**
     * Base class every AI translation provider must extend.
     *
     * Concrete implementations supply the provider slug, label, model list, and
     * the actual API call. The Translation_Service calls translate_with_meta() so
     * it can record the HTTP status and a response preview in the translation log
     * without coupling to provider internals.
     *
     * @package Disciple_Tools
     */
    abstract class Disciple_Tools_CRM_Sync_Abstract_Translation_Provider {

        /**
         * Machine-readable slug that identifies this provider.
         * Must be a valid sanitize_key() value (lowercase, no spaces).
         *
         * @return string e.g. 'gemini'
         */
        abstract public function get_slug(): string;

        /**
         * Human-readable label shown in the admin provider dropdown.
         *
         * @return string e.g. 'Google Gemini'
         */
        abstract public function get_label(): string;

        /**
         * Return the list of models available for this provider.
         *
         * Implementations should use transient caching so repeated calls do not
         * hit the provider API on every page load. The transient key and TTL are
         * left to the concrete class.
         *
         * Each entry is an associative array:
         *   [ 'value' => string, 'label' => string ]
         *
         * @return array<int, array<string, string>>|WP_Error
         */
        abstract public function get_models(): array|WP_Error;

        /**
         * Translate $text using this provider and return translation metadata.
         *
         * The returned array contains:
         *   'translation'      — The translated string (or the original on failure).
         *   'http_status'      — Raw HTTP response code from the provider API.
         *   'response_preview' — First 20 characters of the raw response body.
         *
         * Returns WP_Error on hard failure (network down, auth error, etc.).
         *
         * @param string $text   The message text to translate.
         * @param string $prompt The instruction prepended to the text.
         * @return array{ translation: string, http_status: int, response_preview: string }|WP_Error
         */
        abstract public function translate_with_meta( string $text, string $prompt ): array|WP_Error;

        /**
         * Translate an array of texts in a single provider call.
         *
         * Returns a result array with three keys:
         *   'translations'     — Keyed by the same keys as the input $texts. Any text
         *                        that couldn't be translated should be left as-is so the
         *                        caller can fall back to the original. Do not re-key the
         *                        array with sequential integers — the service maps results
         *                        back to source strings by key, so mismatched keys will
         *                        silently assign translations to the wrong messages.
         *   'http_status'      — HTTP status code from the provider, or null when
         *                        this default loop is used (no single batch request).
         *   'response_preview' — First 20 chars of the raw response body, or null.
         *
         * This default implementation calls translate_with_meta() once per text
         * and is suitable as a fallback for providers that haven't overridden it.
         * Concrete providers should override this to send a single batched request.
         *
         * @param array<int, string> $texts  Indexed array of message texts.
         * @param string             $prompt The instruction prepended to each text.
         * @return array{ translations: array<int, string>, http_status: int|null, response_preview: string|null }|WP_Error
         */
        public function translate_batch( array $texts, string $prompt ): array|WP_Error {
            $translations = [];
            foreach ( $texts as $i => $text ) {
                $result = $this->translate_with_meta( $text, $prompt );
                $translations[ $i ] = is_wp_error( $result ) ? $text : $result['translation'];
            }
            return [
                'translations'     => $translations,
                'http_status'      => null,
                'response_preview' => null,
            ];
        }
    }
}
