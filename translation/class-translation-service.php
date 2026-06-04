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
         * @param string $contact_id The connector's contact ID, passed through to the translation log row.
         * @return string Translated text, or the original on failure.
         */
        public function translate( string $text, string $contact_id ): string {
            if ( ! $this->rate_limiter->is_allowed( $this->daily_limit ) ) {
                Disciple_Tools_CRM_Sync_Translation_Logger::write(
                    $contact_id,
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
                    $contact_id,
                    is_array( $error_data ) ? ( $error_data['http_status'] ?? null ) : null,
                    is_array( $error_data ) ? ( $error_data['response_preview'] ?? null ) : null,
                    'failed',
                    $result->get_error_message()
                );
                return $text;
            }

            $this->rate_limiter->increment();

            Disciple_Tools_CRM_Sync_Translation_Logger::write(
                $contact_id,
                $result['http_status'],
                $result['response_preview'],
                'success'
            );

            return $result['translation'];
        }

        /**
         * Translate a batch of messages in a single provider call.
         *
         * Handles rate limiting across the whole batch: if the daily cap would be
         * exceeded mid-batch, only as many texts as the remaining allowance are
         * sent — the rest come back as originals. One log entry is written per
         * contact regardless of how many messages were in the batch.
         *
         * Returns an array indexed the same way as $texts so the caller can apply
         * translations by key without tracking positions.
         *
         * @param array<int, string> $texts      Indexed array of message texts to translate.
         * @param string             $contact_id The connector's contact ID, passed through to the translation log row.
         * @return array<int, string> Translated strings, or originals on any failure.
         */
        public function translate_batch( array $texts, string $contact_id ): array {
            if ( empty( $texts ) ) {
                return [];
            }

            $remaining = $this->rate_limiter->get_remaining( $this->daily_limit );

            if ( 0 === $remaining ) {
                Disciple_Tools_CRM_Sync_Translation_Logger::write(
                    $contact_id,
                    null,
                    null,
                    'rate_limited',
                    'Daily translation limit reached — batch of ' . count( $texts ) . ' messages skipped.'
                );
                return $texts;
            }

            // Determine how many we can actually send this call.
            $all_keys      = array_keys( $texts );
            $translate_keys = array_slice( $all_keys, 0, $remaining );
            $skipped_keys   = array_slice( $all_keys, $remaining );

            $batch = array_intersect_key( $texts, array_flip( $translate_keys ) );

            $result = $this->provider->translate_batch( $batch, $this->prompt );

            if ( is_wp_error( $result ) ) {
                $error_data = $result->get_error_data();
                Disciple_Tools_CRM_Sync_Translation_Logger::write(
                    $contact_id,
                    is_array( $error_data ) ? ( $error_data['http_status'] ?? null ) : null,
                    is_array( $error_data ) ? ( $error_data['response_preview'] ?? null ) : null,
                    'failed',
                    $result->get_error_message()
                );
                return $texts;
            }

            $this->rate_limiter->increment( count( $translate_keys ) );

            $translated_count = count( $translate_keys );
            $total_count      = count( $texts );

            if ( count( $skipped_keys ) > 0 ) {
                $detail = sprintf(
                    '%d of %d messages translated — daily limit reached mid-batch.',
                    $translated_count,
                    $total_count
                );
                $status = 'partial';
            } else {
                $detail = $translated_count . ' messages translated.';
                $status = 'success';
            }

            Disciple_Tools_CRM_Sync_Translation_Logger::write(
                $contact_id,
                $result['http_status'],
                $result['response_preview'],
                $status,
                $detail
            );

            // Re-map provider results back to the original batch keys positionally.
            // Providers may return sequential integer keys rather than the original
            // sparse keys, so we strip the provider's keys entirely and zip by position
            // against the keys we sent. Anything that falls outside the returned range
            // keeps the original text from the $texts base.
            $output    = $texts;
            $returned  = array_values( $result['translations'] );
            foreach ( array_values( $translate_keys ) as $pos => $key ) {
                if ( array_key_exists( $pos, $returned ) ) {
                    $output[ $key ] = $returned[ $pos ];
                }
            }

            return $output;
        }
    }
}
