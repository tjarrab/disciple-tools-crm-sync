<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Disciple_Tools_CRM_Sync_Gemini_Translation_Provider' ) ) {
    /**
     * Google Gemini translation provider.
     *
     * Uses the Gemini generative language REST API:
     *   - GET  /v1beta/models?key={key}                             - list models
     *   - POST /v1beta/models/{model}:generateContent?key={key}     - generate translation
     *
     * Model list is cached in transient `dt_crm_sync_gemini_models` for 24 hours and
     * can be flushed via the REST endpoint DELETE /translation/models-cache.
     *
     * @package Disciple_Tools
     */
    class Disciple_Tools_CRM_Sync_Gemini_Translation_Provider extends Disciple_Tools_CRM_Sync_Abstract_Translation_Provider {

        private const API_BASE        = 'https://generativelanguage.googleapis.com/v1beta';
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

            $url      = add_query_arg( 'key', $this->api_key, self::API_BASE . '/models' );
            $response = wp_safe_remote_get( $url, [ 'timeout' => self::REQUEST_TIMEOUT ] );

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
            $url  = add_query_arg( 'key', $this->api_key, self::API_BASE . '/' . $this->model . ':generateContent' );
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
                'headers' => [ 'Content-Type' => 'application/json' ],
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
    }
}
