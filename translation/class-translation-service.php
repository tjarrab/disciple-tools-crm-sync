<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Disciple_Tools_CRM_Sync_Translation_Service' ) ) {
    /**
     * Controls message translation during import
     *
     * Wraps a provider, a rate limiter, and the configured prompt and limit so that
     * Message_Importer only needs to call translate(). Translation is just the
     * best-effort: any failure returns the original text and logs the error so
     * the import is not blocked.
     *
     * @package Disciple_Tools
     */
    class Disciple_Tools_CRM_Sync_Translation_Service {

        /**
         * @param Disciple_Tools_CRM_Sync_Abstract_Translation_Provider $provider     Active provider.
         * @param Disciple_Tools_CRM_Sync_Translation_Rate_Limiter       $rate_limiter Rolling 24h window tracker.
         * @param string                                                  $prompt       Instruction prepended to each message.
         * @param int                                                     $daily_limit  Max translations per 24h window. 0 = unlimited.
         */
        public function __construct(
            private readonly Disciple_Tools_CRM_Sync_Abstract_Translation_Provider $provider,
            private readonly Disciple_Tools_CRM_Sync_Translation_Rate_Limiter $rate_limiter,
            private readonly string $prompt,
            private readonly int $daily_limit
        ) {}

        /**
         * Translate a message, log the outcome, and return the translated string.
         *
         * Returns the original $text on any failure so the import comment is never empty.
         *
         * @param string $text       Message text to translate.
         * @param string $respond_id Respond.io contact ID (used for logging only).
         * @return string Translated text, or the original on failure.
         */
        public function translate( string $text, string $respond_id ): string {
            if ( ! $this->rate_limiter->is_allowed( $this->daily_limit ) ) {
                Disciple_Tools_CRM_Sync_Translation_Logger::write(
                    $respond_id,
                    null,
                    null,
                    'rate_limited',
                    'Daily translation limit reached.'
                );
                return $text;
            }

            $result = $this->provider->translate_with_meta( $text, $this->prompt );

            if ( is_wp_error( $result ) ) {
                $error_data = $result->get_error_data();
                Disciple_Tools_CRM_Sync_Translation_Logger::write(
                    $respond_id,
                    is_array( $error_data ) ? ( $error_data['http_status'] ?? null ) : null,
                    is_array( $error_data ) ? ( $error_data['response_preview'] ?? null ) : null,
                    'failed',
                    $result->get_error_message()
                );
                return $text;
            }

            $this->rate_limiter->increment();

            Disciple_Tools_CRM_Sync_Translation_Logger::write(
                $respond_id,
                $result['http_status'],
                $result['response_preview'],
                'success'
            );

            return $result['translation'];
        }
    }
}
