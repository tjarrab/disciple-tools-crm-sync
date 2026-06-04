<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Disciple_Tools_CRM_Sync_Gemini_Translation_Provider' ) ) {
    /**
     * Google Gemini translation provider.
     *
     * Uses the Gemini generative language REST API:
     *   - GET  /v1beta/models                             - list models
     *   - POST /v1beta/models/{model}:generateContent     - generate translation
     *
     * The API key is passed via the x-goog-api-key request header rather than
     * as a URL query parameter, so it doesn't show up in server or proxy logs.
     *
     * Model list is cached in transient `dt_crm_sync_gemini_models` for 24 hours and
     * can be flushed via the REST endpoint DELETE /translation/models-cache.
     *
     * @package Disciple_Tools
     */
    class Disciple_Tools_CRM_Sync_Gemini_Translation_Provider extends Disciple_Tools_CRM_Sync_Abstract_Translation_Provider {

        private const API_BASE        = 'https://generativelanguage.googleapis.com/v1beta';
        private const AUTH_HEADER      = 'x-goog-api-key';
        private const MODELS_TRANSIENT = 'dt_crm_sync_gemini_models';
        private const TRANSIENT_TTL    = DAY_IN_SECONDS;
        private const REQUEST_TIMEOUT  = 30;

        /**
         * @param string $api_key Plaintext (decrypted) Gemini API key.
         * @param string $model   Model identifier, e.g. 'models/gemini-2.0-flash'.
         */
        public function __construct(
            private readonly string $api_key,
            private readonly string $model
        ) {}

        /**
         * @return string
         */
        public function get_slug(): string {
            return 'gemini';
        }

        /**
         * @return string
         */
        public function get_label(): string {
            return 'Google Gemini';
        }

        /**
         * Return a list of Gemini models that support generateContent.
         *
         * Caches results in a transient. Returns a flat array of
         * [ 'value' => 'models/gemini-2.0-flash', 'label' => 'Gemini 2.0 Flash' ] entries,
         * sorted alphabetically by label.
         *
         * @return array<int, array<string, string>>|WP_Error
         */
        public function get_models(): array|WP_Error {
            $cached = get_transient( self::MODELS_TRANSIENT );
            if ( is_array( $cached ) ) {
                return $cached;
            }

            $url      = self::API_BASE . '/models';
            $response = wp_safe_remote_get( $url, [
                'timeout' => self::REQUEST_TIMEOUT,
                'headers' => [ self::AUTH_HEADER => $this->api_key ],
            ] );

            if ( is_wp_error( $response ) ) {
                return $response;
            }

            $http_code = wp_remote_retrieve_response_code( $response );
            $body      = wp_remote_retrieve_body( $response );
            $data      = json_decode( $body, true );

            if ( 200 !== $http_code ) {
                $error_msg = $data['error']['message'] ?? "HTTP $http_code";
                return new WP_Error( 'gemini_models_error', $error_msg, [ 'status' => $http_code ] );
            }

            $raw_models = $data['models'] ?? [];
            $models     = [];

            foreach ( $raw_models as $m ) {
                $supported = $m['supportedGenerationMethods'] ?? [];
                if ( ! in_array( 'generateContent', $supported, true ) ) {
                    continue;
                }
                $model_id    = $m['name'] ?? '';
                $model_label = $m['displayName'] ?? $model_id;
                if ( empty( $model_id ) ) {
                    continue;
                }
                $models[] = [
                    'value' => $model_id,
                    'label' => sanitize_text_field( $model_label ),
                ];
            }

            usort( $models, static fn( $a, $b ) => strcmp( $a['label'], $b['label'] ) );

            set_transient( self::MODELS_TRANSIENT, $models, self::TRANSIENT_TTL );

            return $models;
        }

        /**
         * Call the Gemini generateContent endpoint and return translation metadata.
         *
         * On success returns:
         *   [ 'translation' => string, 'http_status' => int, 'response_preview' => string ]
         *
         * On failure returns a WP_Error.
         *
         * @param string $text   Message text to translate.
         * @param string $prompt Instruction prepended to the text.
         * @return array{ translation: string, http_status: int, response_preview: string }|WP_Error
         */
        public function translate_with_meta( string $text, string $prompt ): array|WP_Error {
            $url  = self::API_BASE . '/' . $this->model . ':generateContent';
            $body = wp_json_encode( [
                'contents' => [
                    [
                        'parts' => [
                            [ 'text' => $prompt . $text ],
                        ],
                    ],
                ],
            ] );

            $response = wp_safe_remote_post( $url, [
                'timeout' => self::REQUEST_TIMEOUT,
                'headers' => [
                    'Content-Type'     => 'application/json',
                    self::AUTH_HEADER  => $this->api_key,
                ],
                'body'    => $body,
            ] );

            if ( is_wp_error( $response ) ) {
                return $response;
            }

            $http_status     = (int) wp_remote_retrieve_response_code( $response );
            $raw_body        = wp_remote_retrieve_body( $response );
            $response_preview = substr( $raw_body, 0, 20 );
            $data            = json_decode( $raw_body, true );

            if ( $http_status < 200 || $http_status >= 300 ) {
                $error_msg = $data['error']['message'] ?? "HTTP $http_status";
                return new WP_Error(
                    'gemini_translate_error',
                    $error_msg,
                    [ 'http_status' => $http_status, 'response_preview' => $response_preview ]
                );
            }

            $translation = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
            if ( '' === $translation ) {
                return new WP_Error(
                    'gemini_empty_response',
                    'Gemini returned an empty translation.',
                    [ 'http_status' => $http_status, 'response_preview' => $response_preview ]
                );
            }

            return [
                'translation'      => $translation,
                'http_status'      => $http_status,
                'response_preview' => $response_preview,
            ];
        }

        /**
         * Translate multiple texts in a single Gemini API call.
         *
         * Encodes all texts as a JSON array and asks Gemini to return a JSON array
         * of the same length in the same order. If the response can't be decoded as
         * a matching array (Gemini occasionally wraps JSON in markdown fences or
         * returns fewer items than expected), the method falls back to the parent's
         * one-call-per-text loop so no messages are silently dropped.
         *
         * @param array<int, string> $texts  Indexed array of message texts.
         * @param string             $prompt The instruction prepended to the request.
         * @return array{ translations: array<int, string>, http_status: int|null, response_preview: string|null }|WP_Error
         */
        public function translate_batch( array $texts, string $prompt ): array|WP_Error {
            if ( empty( $texts ) ) {
                return [ 'translations' => [], 'http_status' => null, 'response_preview' => null ];
            }

            $batch_prompt = $prompt
                . 'Translate each item in the following JSON array. '
                . "Return ONLY a valid JSON array of translated strings in exactly the same order, with no extra text:\n"
                . wp_json_encode( array_values( $texts ) );

            $url  = self::API_BASE . '/' . $this->model . ':generateContent';
            $body = wp_json_encode( [
                'contents' => [
                    [
                        'parts' => [
                            [ 'text' => $batch_prompt ],
                        ],
                    ],
                ],
            ] );

            $response = wp_safe_remote_post( $url, [
                'timeout' => self::REQUEST_TIMEOUT,
                'headers' => [
                    'Content-Type'     => 'application/json',
                    self::AUTH_HEADER  => $this->api_key,
                ],
                'body'    => $body,
            ] );

            if ( is_wp_error( $response ) ) {
                return $response;
            }

            $http_status = (int) wp_remote_retrieve_response_code( $response );
            $raw_body    = wp_remote_retrieve_body( $response );
            $data        = json_decode( $raw_body, true );

            if ( $http_status < 200 || $http_status >= 300 ) {
                $error_msg = $data['error']['message'] ?? "HTTP $http_status";
                return new WP_Error(
                    'gemini_batch_error',
                    $error_msg,
                    [ 'http_status' => $http_status, 'response_preview' => substr( $raw_body, 0, 20 ) ]
                );
            }

            $raw_translation = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

            // Strip markdown fences that Gemini sometimes wraps around JSON output.
            $clean = preg_replace( '/^```(?:json)?\s*/i', '', trim( $raw_translation ) );
            $clean = preg_replace( '/\s*```$/i', '', $clean );

            $translated = json_decode( trim( $clean ), true );

            // If Gemini didn't return a matching array, fall back to individual calls.
            if ( ! is_array( $translated ) || count( $translated ) !== count( $texts ) ) {
                return parent::translate_batch( $texts, $prompt );
            }

            // Re-key the result to match the original $texts keys.
            $keys         = array_keys( $texts );
            $translations = [];
            foreach ( $keys as $position => $original_key ) {
                $translations[ $original_key ] = (string) ( $translated[ $position ] ?? $texts[ $original_key ] );
            }

            return [
                'translations'     => $translations,
                'http_status'      => $http_status,
                'response_preview' => substr( $raw_body, 0, 20 ),
            ];
        }
    }
}
