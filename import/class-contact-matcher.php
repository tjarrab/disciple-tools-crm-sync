<?php
/**
 * Contact Matcher — duplicate detection for incoming CRM contacts.
 *
 * Provides two lookup strategies:
 *   1. Fast indexed path: match on the connector's ID meta key.
 *   2. Slow fallback: LIKE-pattern search against DT's per-channel phone/email meta.
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
         * Shortest run of digits we'll treat as a usable phone identity. Anything with
         * fewer significant digits is ignored for matching and locking — comparing such
         * short numbers risks tying unrelated contacts together.
         */
        private const MIN_PHONE_DIGITS = 7;

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
         * DT never stores a communication channel value under the bare field key.
         * Each value gets its own postmeta row under a randomly-suffixed key (e.g.
         * 'contact_phone_a1b', built by DT_Posts::create_channel_metakey()), with the
         * raw value as a plain string — plus a sibling 'contact_phone_a1b_details' row
         * holding extra data (verified flag, etc). So matching has to key off a LIKE
         * prefix rather than an exact key, and explicitly exclude the details rows.
         *
         * @return int|null Post ID on match, null if neither value produces a hit.
         */
        public function find_by_phone_or_email( string $phone, string $email ): int|null {
            global $wpdb;

            if ( ! empty( $phone ) ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $post_id = $wpdb->get_var( $wpdb->prepare(
                    "SELECT pm.post_id FROM {$wpdb->postmeta} pm
                     INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                     WHERE pm.meta_key LIKE %s
                       AND pm.meta_key NOT LIKE %s
                       AND pm.meta_value = %s
                       AND p.post_type = 'contacts'
                       AND p.post_status = 'publish'
                     LIMIT 1",
                    $wpdb->esc_like( 'contact_phone' ) . '%',
                    '%_details',
                    $phone
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
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $post_id = $wpdb->get_var( $wpdb->prepare(
                    "SELECT pm.post_id FROM {$wpdb->postmeta} pm
                     INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                     WHERE pm.meta_key LIKE %s
                       AND pm.meta_key NOT LIKE %s
                       AND pm.meta_value = %s
                       AND p.post_type = 'contacts'
                       AND p.post_status = 'publish'
                     LIMIT 1",
                    $wpdb->esc_like( 'contact_email' ) . '%',
                    '%_details',
                    $email
                ) );

                if ( ! empty( $post_id ) ) {
                    return (int) $post_id;
                }
            }

            return null;
        }

        /**
         * Digit-only fallback for when the exact-substring check above misses because
         * the incoming number is formatted differently from what's stored — different
         * punctuation, a leading "+", or a country code that one side carries and the
         * other drops.
         *
         * Both sides are reduced to a canonical form (see canonical_phone()) and
         * compared for exact equality, so two genuinely different local numbers can't
         * collapse into one the way a loose suffix comparison would. The SQL LIKE only
         * narrows the candidate set; the canonical comparison in PHP is what decides.
         *
         * @return int|null Post ID on match, null if no candidate's canonical form agrees.
         */
        private function find_by_normalized_phone( string $phone ): int|null {
            global $wpdb;

            $region             = self::default_phone_region();
            $incoming_canonical = self::canonical_phone( $phone, $region['cc'], $region['len'], $region['trunk'] );
            if ( strlen( $incoming_canonical ) < self::MIN_PHONE_DIGITS ) {
                // Too few digits to compare without a meaningful risk of a false match.
                return null;
            }

            // The national significant digits are the part that shows up verbatim in a
            // stored value whether or not it carries a country code, so they make the
            // most reliable LIKE prefilter.
            $token = self::national_token( $phone, $region );
            if ( strlen( $token ) < self::MIN_PHONE_DIGITS ) {
                return null;
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $candidates = $wpdb->get_results( $wpdb->prepare(
                "SELECT pm.post_id, pm.meta_value FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key LIKE %s
                   AND pm.meta_key NOT LIKE %s
                   AND pm.meta_value LIKE %s
                   AND p.post_type = 'contacts'
                   AND p.post_status = 'publish'
                 LIMIT 50",
                $wpdb->esc_like( 'contact_phone' ) . '%',
                '%_details',
                '%' . $wpdb->esc_like( $token ) . '%'
            ) );

            // Values are stored as plain strings; maybe_unserialize()/flatten_strings()
            // are a defensive no-op here in case a value ever legitimately is an array.
            foreach ( (array) $candidates as $row ) {
                $stored_values = $this->flatten_strings( maybe_unserialize( $row->meta_value ) );
                foreach ( $stored_values as $stored_value ) {
                    if ( self::canonical_phone( $stored_value, $region['cc'], $region['len'], $region['trunk'] ) === $incoming_canonical ) {
                        return (int) $row->post_id;
                    }
                }
            }

            return null;
        }

        /**
         * Strips everything but digits from a phone number so differently-formatted
         * values (spaces, dashes, parentheses, a leading "+") can be compared directly.
         */
        public static function normalize_phone_digits( string $phone ): string {
            return (string) preg_replace( '/\D+/', '', $phone );
        }

        /**
         * Reduce a phone number to a comparable canonical form.
         *
         * Everything but digits is dropped, a leading international access code (00) is
         * removed, and — when a default region is configured — a bare local number is
         * expanded to its full international form so it lines up with the same number
         * stored with its country code. Numbers that don't fit the configured region
         * (foreign ones) are left as their raw digits so we never mangle them or
         * falsely treat two different numbers as one.
         *
         * @param string      $phone        Raw number in any format.
         * @param string|null $cc           Default country dial code; null reads settings.
         * @param int|null    $national_len Local (national) number length; null reads settings.
         * @param string|null $trunk        National trunk prefix, e.g. "0"; null reads settings.
         */
        public static function canonical_phone( string $phone, ?string $cc = null, ?int $national_len = null, ?string $trunk = null ): string {
            if ( null === $cc || null === $national_len || null === $trunk ) {
                $region       = self::default_phone_region();
                $cc           = $cc ?? $region['cc'];
                $national_len = $national_len ?? $region['len'];
                $trunk        = $trunk ?? $region['trunk'];
            }

            $digits = self::normalize_phone_digits( $phone );

            // "00" is the international call prefix across most of the world; dropping it
            // makes 0033... read the same as +33... once the "+" is already gone.
            if ( str_starts_with( $digits, '00' ) ) {
                $digits = substr( $digits, 2 );
            }

            if ( '' === $cc || $national_len <= 0 ) {
                return $digits;
            }

            $national = self::national_part( $digits, $cc, $national_len, $trunk );

            return null === $national ? $digits : $cc . $national;
        }

        /**
         * Pull the national significant digits out of a number for the configured
         * region, or return null when it doesn't fit that region's shape — a foreign
         * number, or simply the wrong length.
         */
        private static function national_part( string $digits, string $cc, int $national_len, string $trunk ): ?string {
            // Already a full international number for the default country.
            if ( str_starts_with( $digits, $cc ) && strlen( $digits ) === strlen( $cc ) + $national_len ) {
                return substr( $digits, strlen( $cc ) );
            }

            // Local number written with a trunk prefix — the leading digit(s) domestic
            // dialling uses but the international form drops (e.g. the 0 in 06 12 34...).
            if ( '' !== $trunk && str_starts_with( $digits, $trunk ) && strlen( $digits ) - strlen( $trunk ) === $national_len ) {
                return substr( $digits, strlen( $trunk ) );
            }

            // Bare local number, no country code and no trunk prefix.
            if ( strlen( $digits ) === $national_len ) {
                return $digits;
            }

            return null;
        }

        /**
         * Digit string used to prefilter candidate rows in SQL: the national part when
         * the number fits the configured region, otherwise the trailing digits as a
         * best-effort narrow for foreign numbers.
         */
        private static function national_token( string $phone, array $region ): string {
            $digits = self::normalize_phone_digits( $phone );
            if ( str_starts_with( $digits, '00' ) ) {
                $digits = substr( $digits, 2 );
            }

            if ( '' !== $region['cc'] && $region['len'] > 0 ) {
                $national = self::national_part( $digits, $region['cc'], $region['len'], $region['trunk'] );
                if ( null !== $national ) {
                    return $national;
                }
            }

            return strlen( $digits ) <= self::MIN_PHONE_DIGITS ? $digits : substr( $digits, -self::MIN_PHONE_DIGITS );
        }

        /**
         * Read the configured default phone region from settings. Blank/zero values
         * mean "no region", in which case matching falls back to a plain digit compare.
         *
         * @return array{cc:string,len:int,trunk:string}
         */
        private static function default_phone_region(): array {
            $settings = get_option( 'dt_crm_sync_settings', [] );
            $settings = is_array( $settings ) ? $settings : [];

            return [
                'cc'    => self::normalize_phone_digits( (string) ( $settings['default_country_code'] ?? '' ) ),
                'len'   => max( 0, (int) ( $settings['national_number_length'] ?? 0 ) ),
                'trunk' => self::normalize_phone_digits( (string) ( $settings['national_trunk_prefix'] ?? '' ) ),
            ];
        }

        /**
         * Canonical key used to identify "the same phone number" for locking. Matches
         * the equivalence class find_by_normalized_phone() compares on, so two
         * differently-formatted representations of one number — with or without a
         * country code — always resolve to a single lock rather than two.
         *
         * @return string The canonical number, or '' if it's too short to use safely.
         */
        public static function phone_lock_key( string $phone ): string {
            $canonical = self::canonical_phone( $phone );
            return strlen( $canonical ) < self::MIN_PHONE_DIGITS ? '' : $canonical;
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
