<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Disciple_Tools_CRM_Sync_Translation_Rate_Limiter' ) ) {
    /**
     * Rolling 24-hour rate limiter for translation API calls -
     * someday AI models should have cost cutoffs.
     *
     * State is persisted in WP option `dt_crm_sync_translation_usage` as:
     *   [ 'window_start' => int (unix timestamp), 'count' => int ]
     *
     *
     * @package Disciple_Tools
     */
    class Disciple_Tools_CRM_Sync_Translation_Rate_Limiter {

        private const OPTION_KEY  = 'dt_crm_sync_translation_usage';
        private const WINDOW_SECS = DAY_IN_SECONDS;

        /**
         * In-memory cache of the current window state.
         * Loaded once per request and written back on increment().
         *
         * @var array{ window_start: int, count: int }
         */
        private array $state;

        public function __construct() {
            $saved = get_option( self::OPTION_KEY, [] );
            $this->state = [
                'window_start' => isset( $saved['window_start'] ) ? (int) $saved['window_start'] : time(),
                'count'        => isset( $saved['count'] ) ? (int) $saved['count'] : 0,
            ];
        }

        /**
         * Check whether another translation is permitted.
         *
         * Resets the rolling window if 24 hours have elapsed.
         * A limit of 0 means unlimited
         *
         * @param int $limit Maximum translations per 24-hour window. 0 = unlimited.
         * @return bool True when the translation is allowed.
         */
        public function is_allowed( int $limit ): bool {
            $this->maybe_reset_window();

            if ( 0 === $limit ) {
                return true;
            }

            return $this->state['count'] < $limit;
        }

        /**
         * Increment the translation count for the current window.
         *
         * Pass a value greater than 1 to record a batch of translations in a
         * single call — keeps the count meaningful when we send multiple texts
         * to the provider at once.
         *
         * @param int $by Number of translations to add. Defaults to 1.
         */
        public function increment( int $by = 1 ): void {
            $this->maybe_reset_window();
            $this->state['count'] += max( 1, $by );
            $this->save();
        }

        /**
         * Return how many translations can still be sent before hitting the daily cap.
         *
         * Returns PHP_INT_MAX when $limit is 0 (unlimited), so callers can always
         * compare the result against their batch size without special-casing the
         * unlimited branch.
         *
         * @param int $limit The configured daily cap. 0 = unlimited.
         * @return int
         */
        public function get_remaining( int $limit ): int {
            if ( 0 === $limit ) {
                return PHP_INT_MAX;
            }
            $this->maybe_reset_window();
            return max( 0, $limit - $this->state['count'] );
        }

        /**
         * Return the number of translations sent in the current window.
         *
         * @return int
         */
        public function get_count(): int {
            $this->maybe_reset_window();
            return $this->state['count'];
        }

        /**
         * Return the unix timestamp when the current window started.
         *
         * Useful for showing "resets in X hours" in the admin UI.
         *
         * @return int
         */
        public function get_window_start(): int {
            return $this->state['window_start'];
        }

        /**
         * Reset the window to now if 24 hours have elapsed since window_start.
         */
        private function maybe_reset_window(): void {
            if ( ( time() - $this->state['window_start'] ) >= self::WINDOW_SECS ) {
                $this->state = [
                    'window_start' => time(),
                    'count'        => 0,
                ];
                $this->save();
            }
        }

        /**
         * Save the current state to WP options.
         */
        private function save(): void {
            update_option( self::OPTION_KEY, $this->state, false );
        }
    }
}
