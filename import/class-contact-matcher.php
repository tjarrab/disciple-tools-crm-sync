<?php
/**
 * Contact Matcher — duplicate detection for incoming CRM contacts.
 *
 * Provides two lookup strategies:
 *   1. Fast indexed path: match on the connector's ID meta key.
 *   2. Slow fallback: LIKE-pattern search against serialized phone/email meta.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Disciple_Tools_CRM_Sync_Contact_Matcher' ) ) {
    /**
     * Detects whether an incoming CRM contact already exists in DT.
     *
     * @package Disciple_Tools
     */
    class Disciple_Tools_CRM_Sync_Contact_Matcher {

        /**
         * Length of the trailing digit suffix used both to prefilter normalized-phone
         * candidates and as the equivalence key for the per-contact phone lock in
         * import-processor.php — the two must stay in sync or the lock can miss a pair
         * of numbers the matcher itself would consider the same.
         */
        private const PHONE_SUFFIX_LENGTH = 7;

        /**
         * @param string $meta_key_prefix Connector-specific meta key prefix,
         *                                e.g. '_respond_io_' from get_meta_key_prefix().
         */
        public function __construct( private readonly string $meta_key_prefix ) {}

        /**
         * Find a DT contact whose connector-ID meta matches $connector_id.
         *
         * @param string $connector_id The CRM contact ID to look up.
         * @return int|null Post ID on match, null if no match.
         */
        public function find_by_connector_id( string $connector_id ): int|null {
            $posts = get_posts( [
                'post_type'      => 'contacts',
                'post_status'    => 'publish',
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'no_found_rows'  => true,
                'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required for duplicate contact detection by connector ID.
                    [
                        'key'   => $this->meta_key_prefix . 'id',
                        'value' => $connector_id,
                    ],
                ],
            ] );

            return ! empty( $posts ) ? (int) $posts[0] : null;
        }

        /**
         * Sequential phone → email fallback duplicate check.
         *
         * DT stores communication channel values (phone, email) as PHP-serialized
         * arrays in wp_postmeta — a plain meta_value equality check will never match.
         *
         * We search using a LIKE pattern against the serialized string representation:
         *   s:{byte-length}:"{value}"
         * The byte-length prefix prevents a shorter value (e.g. "12") from matching
         * inside a longer serialized string (e.g. "1234567890").
         *
         * @return int|null Post ID on match, null if neither value produces a hit.
         */
        public function find_by_phone_or_email( string $phone, string $email ): int|null {
            global $wpdb;

            if ( ! empty( $phone ) ) {
                $phone_like = '%s:' . strlen( $phone ) . ':"' . $wpdb->esc_like( $phone ) . '"%';
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $post_id = $wpdb->get_var( $wpdb->prepare(
                    "SELECT pm.post_id FROM {$wpdb->postmeta} pm
                     INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                     WHERE pm.meta_key = 'contact_phone'
                       AND pm.meta_value LIKE %s
                       AND p.post_type = 'contacts'
                       AND p.post_status = 'publish'
                     LIMIT 1",
                    $phone_like
                ) );

                if ( ! empty( $post_id ) ) {
                    return (int) $post_id;
                }

                $post_id = $this->find_by_normalized_phone( $phone );
                if ( ! empty( $post_id ) ) {
                    return $post_id;
                }
            }

            if ( ! empty( $email ) ) {
                $email_like = '%s:' . strlen( $email ) . ':"' . $wpdb->esc_like( $email ) . '"%';
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $post_id = $wpdb->get_var( $wpdb->prepare(
                    "SELECT pm.post_id FROM {$wpdb->postmeta} pm
                     INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                     WHERE pm.meta_key = 'contact_email'
                       AND pm.meta_value LIKE %s
                       AND p.post_type = 'contacts'
                       AND p.post_status = 'publish'
                     LIMIT 1",
                    $email_like
                ) );

                if ( ! empty( $post_id ) ) {
                    return (int) $post_id;
                }
            }

            return null;
        }

        /**
         * Digit-only fallback match for when the exact substring check above misses
         * because the incoming number is formatted differently than what's stored
         * (spaces, dashes, parentheses, a leading "+", or a missing/extra country code).
         *
         * Prefilters with a LIKE on the trailing 7 digits — the part of a number least
         * likely to be interrupted by punctuation — then confirms each candidate in PHP
         * by comparing fully digit-stripped values.
         *
         * @return int|null Post ID on match, null if no candidate's digits agree.
         */
        private function find_by_normalized_phone( string $phone ): int|null {
            global $wpdb;

            $incoming_digits = self::normalize_phone_digits( $phone );
            if ( strlen( $incoming_digits ) < self::PHONE_SUFFIX_LENGTH ) {
                // Too short to compare without a meaningful risk of a false match.
                return null;
            }
            $suffix = substr( $incoming_digits, -self::PHONE_SUFFIX_LENGTH );

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $candidates = $wpdb->get_results( $wpdb->prepare(
                "SELECT pm.post_id, pm.meta_value FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key = 'contact_phone'
                   AND pm.meta_value LIKE %s
                   AND p.post_type = 'contacts'
                   AND p.post_status = 'publish'
                 LIMIT 50",
                '%' . $wpdb->esc_like( $suffix ) . '%'
            ) );

            foreach ( (array) $candidates as $row ) {
                $stored_values = $this->flatten_strings( maybe_unserialize( $row->meta_value ) );
                foreach ( $stored_values as $stored_value ) {
                    $stored_digits = self::normalize_phone_digits( $stored_value );
                    if ( strlen( $stored_digits ) < self::PHONE_SUFFIX_LENGTH ) {
                        continue;
                    }
                    // A suffix match (rather than strict equality) tolerates one side
                    // carrying a country code that the other omits.
                    if ( str_ends_with( $stored_digits, $incoming_digits ) || str_ends_with( $incoming_digits, $stored_digits ) ) {
                        return (int) $row->post_id;
                    }
                }
            }

            return null;
        }

        /**
         * Strips everything but digits from a phone number so differently-formatted
         * values (spaces, dashes, parentheses, a leading "+") can be compared directly.
         * Shared with import-processor.php so the per-contact phone lock uses the same
         * identity as this matching logic.
         */
        public static function normalize_phone_digits( string $phone ): string {
            return (string) preg_replace( '/\D+/', '', $phone );
        }

        /**
         * Trailing-digit-suffix key used to identify "the same phone number" for
         * locking purposes — matches the equivalence class find_by_normalized_phone()
         * uses, so two differently-formatted representations of the same number (with
         * or without a country code) always resolve to one lock, not two.
         *
         * @return string The suffix key, or '' if the number is too short to use safely.
         */
        public static function phone_lock_key( string $phone ): string {
            $digits = self::normalize_phone_digits( $phone );
            if ( strlen( $digits ) < self::PHONE_SUFFIX_LENGTH ) {
                return '';
            }
            return substr( $digits, -self::PHONE_SUFFIX_LENGTH );
        }

        /**
         * Recursively pulls every string leaf out of an unserialized meta value,
         * regardless of the channel field's exact array shape.
         *
         * @return string[]
         */
        private function flatten_strings( mixed $data ): array {
            if ( is_string( $data ) ) {
                return [ $data ];
            }
            if ( ! is_array( $data ) ) {
                return [];
            }
            $strings = [];
            foreach ( $data as $value ) {
                $strings = array_merge( $strings, $this->flatten_strings( $value ) );
            }
            return $strings;
        }
    }
}
