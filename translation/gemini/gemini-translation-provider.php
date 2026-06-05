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
     * Batch translation splits large message sets into chunks before sending so
     * that individual requests stay well within the configured timeout. Contacts
     * with very long conversation histories were hitting cURL error 28 (operation
     * timed out) when all messages were packed into a single API call.
     *
     * @package Disciple_Tools
     */
    class Disciple_Tools_CRM_Sync_Gemini_Translation_Provider extends Disciple_Tools_CRM_Sync_Abstract_Translation_Provider {

        private const API_BASE         = 'https://generativelanguage.googleapis.com/v1beta';
        private const AUTH_HEADER      = 'x-goog-api-key';
        private const MODELS_TRANSIENT = 'dt_crm_sync_gemini_models';
        private const TRANSIENT_TTL    = DAY_IN_SECONDS;

        // How many times to retry a request that times out before giving up.
        // Two retries gives a reasonable second and third chance without holding
        // the cron worker for an unreasonable amount of time.
        private const MAX_RETRIES = 2;

        /**
         * @param string $api_key          Plaintext (decrypted) Gemini API key.
         * @param string $model            Model identifier, e.g. 'models/gemini-2.0-flash'.
         * @param int    $request_timeout  HTTP timeout in seconds for each Gemini API call.
         * @param int    $batch_chunk_size Number of messages per chunk when batching. Smaller
         *                                values mean more API calls but each one finishes faster.
         */
        public function __construct(
            private readonly string $api_key,
            private readonly string $model,
            private readonly int $request_timeout  = 120,
            private readonly int $batch_chunk_size = 10,
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
                'timeout' => $this->request_timeout,
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
         * Wrapper around wp_safe_remote_post() that retries on cURL timeout errors.
         *
         * cURL error 28 ("Operation timed out") means the server never responded within
         * the timeout window — it's worth retrying because Gemini can occasionally be
         * slow rather than actually down. We sleep briefly between attempts so we're
         * not hammering the API while it's under load.
         *
         * Other errors (auth failures, 4xx/5xx responses) are not retried here; that's
         * handled by the callers based on the HTTP status code.
         *
         * @param string               $url  Full URL to POST to.
         * @param array<string, mixed> $args wp_safe_remote_post() args array.
         * @return array|WP_Error The last response received, or a WP_Error on final failure.
         */
        private function post_with_retry( string $url, array $args ): array|WP_Error {
            $response = wp_safe_remote_post( $url, $args );

            $attempt = 0;
            while ( is_wp_error( $response ) && $attempt < self::MAX_RETRIES ) {
                $message = $response->get_error_message();
                // Only retry on timeout — other errors won't go away by waiting.
                if ( str_contains( $message, 'cURL error 28' ) || str_contains( $message, 'timed out' ) ) {
                    ++$attempt;
                    sleep( 2 ** $attempt ); // 2s after first timeout, 4s after second.
                    $response = wp_safe_remote_post( $url, $args );
                } else {
                    break;
                }
            }

            return $response;
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

            $response = $this->post_with_retry( $url, [
                'timeout' => $this->request_timeout,
                'headers' => [
                    'Content-Type'    => 'application/json',
                    self::AUTH_HEADER => $this->api_key,
                ],
                'body'    => $body,
            ] );

            if ( is_wp_error( $response ) ) {
                return $response;
            }

            $http_status      = (int) wp_remote_retrieve_response_code( $response );
            $raw_body         = wp_remote_retrieve_body( $response );
            $response_preview = substr( $raw_body, 0, 20 );
            $data             = json_decode( $raw_body, true );

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
         * Translate multiple texts by splitting them into chunks and calling Gemini once per chunk.
         *
         * Sending all messages in a single request works fine for short conversations, but
         * contacts with long histories produce payloads that Gemini can take well over 30 seconds
         * to process. Chunking keeps each individual request small enough to complete comfortably
         * within the configured timeout.
         *
         * Each chunk is encoded as a JSON array and Gemini is asked to return a matching JSON
         * array. If Gemini's response can't be decoded as an array of the right length (it
         * occasionally wraps output in markdown fences or returns fewer items), the chunk falls
         * back to the parent's one-call-per-text loop. If a chunk fails entirely after retries,
         * originals are kept for those messages so the rest of the batch is not affected.
         *
         * The last successful chunk's HTTP status and response preview are returned so the
         * translation log has something useful to record.
         *
         * @param array<int, string> $texts  Indexed array of message texts.
         * @param string             $prompt The instruction prepended to the request.
         * @return array{ translations: array<int, string>, http_status: int|null, response_preview: string|null }|WP_Error
         */
        public function translate_batch( array $texts, string $prompt ): array|WP_Error {
            if ( empty( $texts ) ) {
                return [ 'translations' => [], 'http_status' => null, 'response_preview' => null ];
            }

            $url          = self::API_BASE . '/' . $this->model . ':generateContent';
            $output       = $texts; // Start with originals; overwrite as chunks succeed.
            $last_status  = null;
            $last_preview = null;

            $chunks = array_chunk( array_keys( $texts ), $this->batch_chunk_size );

            foreach ( $chunks as $key_slice ) {
                $chunk = array_intersect_key( $texts, array_flip( $key_slice ) );

                $batch_prompt = $prompt
                    . 'Translate each item in the following JSON array. '
                    . "Return ONLY a valid JSON array of translated strings in exactly the same order, with no extra text:\n"
                    . wp_json_encode( array_values( $chunk ) );

                $body = wp_json_encode( [
                    'contents' => [
                        [
                            'parts' => [
                                [ 'text' => $batch_prompt ],
                            ],
                        ],
                    ],
                ] );

                $response = $this->post_with_retry( $url, [
                    'timeout' => $this->request_timeout,
                    'headers' => [
                        'Content-Type'    => 'application/json',
                        self::AUTH_HEADER => $this->api_key,
                    ],
                    'body'    => $body,
                ] );

                if ( is_wp_error( $response ) ) {
                    // Timeout or network error — keep originals for this chunk and move on.
                    continue;
                }

                $http_status = (int) wp_remote_retrieve_response_code( $response );
                $raw_body    = wp_remote_retrieve_body( $response );
                $data        = json_decode( $raw_body, true );

                $last_status  = $http_status;
                $last_preview = substr( $raw_body, 0, 20 );

                if ( $http_status < 200 || $http_status >= 300 ) {
                    // API error for this chunk — keep originals and move on.
                    continue;
                }

                $raw_translation = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

                // Strip markdown fences that Gemini sometimes wraps around JSON output.
                $clean = preg_replace( '/^```(?:json)?\s*/i', '', trim( $raw_translation ) );
                $clean = preg_replace( '/\s*```$/i', '', $clean );

                $translated = json_decode( trim( $clean ), true );

                if ( ! is_array( $translated ) || count( $translated ) !== count( $chunk ) ) {
                    // Gemini didn't return a matching array — fall back to individual calls for this chunk.
                    $fallback = parent::translate_batch( $chunk, $prompt );
                    if ( ! is_wp_error( $fallback ) ) {
                        foreach ( $fallback['translations'] as $k => $v ) {
                            $output[ $k ] = $v;
                        }
                    }
                    continue;
                }

                // Map translated values back onto the original keys.
                foreach ( array_values( $key_slice ) as $position => $original_key ) {
                    $output[ $original_key ] = (string) ( $translated[ $position ] ?? $texts[ $original_key ] );
                }
            }

            return [
                'translations'     => $output,
                'http_status'      => $last_status,
                'response_preview' => $last_preview,
            ];
        }
    }
}
