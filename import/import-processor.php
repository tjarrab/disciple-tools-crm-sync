<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Disciple_Tools_CRM_Sync_Processor' ) ) {
    /**
     * Executes the per-contact import lifecycle for a batch of Respond.io IDs.
     *
     * @package Disciple_Tools
     */
    class Disciple_Tools_CRM_Sync_Processor {

        private static ?self $instance = null;

        /**
         * Active connector instance for the current batch, set at the top of process_batch()
         * and valid for the lifetime of that WP-Cron invocation.
         *
         * @var Disciple_Tools_CRM_Sync_Abstract_Connector|null
         */
        protected ?Disciple_Tools_CRM_Sync_Abstract_Connector $connector = null;

        /**
         * Collaborator for duplicate contact detection. Instantiated per-batch in process_batch()
         * once the connector (and therefore its meta key prefix) is resolved.
         */
        protected ?Disciple_Tools_CRM_Sync_Contact_Matcher $matcher = null;

        /**
         * Collaborator for profile-to-DT field mapping. Instantiated per-batch in process_batch()
         * once the connector (needed for get_dt_source_slug()) is resolved.
         */
        protected ?Disciple_Tools_CRM_Sync_Field_Mapper $mapper = null;

        /**
         * Collaborator for message history import. Instantiated per-batch in process_batch()
         * after the connector and sideloader are available.
         */
        protected ?Disciple_Tools_CRM_Sync_Message_Importer $message_importer = null;

        /**
         * Collaborator for activity-feed note upsert. Instantiated per-batch in process_batch()
         * once the connector (and therefore its meta key prefix) is resolved.
         */
        protected ?Disciple_Tools_CRM_Sync_Activity_Feed_Writer $activity_feed_writer = null;

        /**
         * Lock names acquired for the contact currently being processed (the pre-merge
         * connector ID, plus a canonical-ID and/or phone lock if those identities come
         * into play) — process_single_contact() releases every entry here, not just one.
         */
        private array $held_contact_locks = [];

        /**
         * Returns the singleton instance, creating it on first call.
         *
         * @return self
         */
        public static function instance(): self {
            if ( is_null( self::$instance ) ) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        /**
         * Registers the WP-Cron action hook that drives batch processing.
         *
         * @return void
         */
        protected function __construct() {
            add_action( 'dt_crm_sync_process_batch', [ $this, 'process_batch' ], 10, 1 );
        }

        /**
         * WP-Cron callback. Processes a batch of Respond.io contact IDs.
         *
         * @param array $args {
         *     @type int[]  $ids      Respond.io contact IDs to import.
         *     @type string $_token   Unique deduplication token (ignored by processor).
         *     @type string $_trigger 'manual' | 'scheduled' | 'webhook'
         * }
         */
        public function process_batch( array $args ): void {
            // Prevent PHP timeout on large batches with message history + media sideloading.
            if ( function_exists( 'set_time_limit' ) ) {
                set_time_limit( 300 );
            }

            $ids          = array_values( array_filter( $args['ids'] ?? [], 'is_scalar' ) );
            $trigger_type = sanitize_key( $args['_trigger'] ?? 'manual' );
            $skip_existing = (bool) ( $args['_skip_existing'] ?? true );

            if ( empty( $ids ) ) {
                return;
            }

            $this->connector = null;
            $connector_result = $this->get_active_connector();
            if ( is_wp_error( $connector_result ) ) {
                Disciple_Tools_CRM_Sync_Logger::write(
                    $trigger_type, 'batch', null, 'failed',
                    $connector_result->get_error_message()
                );
                return;
            }
            $this->connector      = $connector_result;
            $this->matcher        = new Disciple_Tools_CRM_Sync_Contact_Matcher( $this->connector->get_meta_key_prefix() );
            $this->mapper         = new Disciple_Tools_CRM_Sync_Field_Mapper( $this->connector );

            // Wire up the translation service when enabled and the API key decrypts cleanly.
            $translation_settings = get_option( 'dt_crm_sync_translation_settings', [] );
            $translation_service  = null;
            if ( ! empty( $translation_settings['enabled'] ) && ! empty( $translation_settings['api_key'] ) ) {
                $api_key = Disciple_Tools_CRM_Sync::decrypt_value( $translation_settings['api_key'] );
                if ( false !== $api_key ) {
                    $provider            = new Disciple_Tools_CRM_Sync_Gemini_Translation_Provider(
                        $api_key,
                        $translation_settings['model'] ?? '',
                        absint( $translation_settings['request_timeout'] ?? 120 ),
                        absint( $translation_settings['batch_chunk_size'] ?? 10 )
                    );
                    $translation_service = new Disciple_Tools_CRM_Sync_Translation_Service(
                        $provider,
                        new Disciple_Tools_CRM_Sync_Translation_Rate_Limiter(),
                        $translation_settings['prompt'] ?? '',
                        absint( $translation_settings['daily_limit'] ?? 0 )
                    );
                } else {
                    // Key is present in settings but OpenSSL could not decrypt it — the
                    // encryption key was likely regenerated. Admin must re-enter and save.
                    Disciple_Tools_CRM_Sync_Logger::write(
                        $trigger_type, 'batch', null, 'failed',
                        'Translation skipped — API key could not be decrypted. Re-enter and save the key on the Translation tab.'
                    );
                }
            }

            $this->message_importer = new Disciple_Tools_CRM_Sync_Message_Importer(
                $this->connector,
                new Disciple_Tools_CRM_Sync_Media_Sideloader(),
                $translation_service
            );
            $this->activity_feed_writer = new Disciple_Tools_CRM_Sync_Activity_Feed_Writer();

            $processed_count = 0;

            foreach ( $ids as $respond_id ) {
                $respond_id = (string) $respond_id;
                ++$processed_count;

                $result = $this->process_single_contact( $respond_id, $trigger_type, $skip_existing );

                if ( true === $result ) {
                    // Success or intentional skip — continue to next contact.
                    continue;
                }

                if ( is_wp_error( $result ) ) {
                    $code = $result->get_error_code();

                    if ( 'rate_limited' === $code ) {
                        $retry_after = (int) ( $result->get_error_data()['retry_after'] ?? 60 );
                        // Use $processed_count - 1 so the contact that triggered the 429
                        // is included in the reschedule, not dropped.
                        $remaining = array_slice( $ids, $processed_count - 1 );
                        if ( ! empty( $remaining ) ) {
                            $scheduled = wp_schedule_single_event(
                                time() + $retry_after,
                                'dt_crm_sync_process_batch',
                                [ [ 'ids' => $remaining, '_token' => uniqid( '', true ), '_trigger' => $trigger_type ] ]
                            );
                            if ( false === $scheduled ) {
                                Disciple_Tools_CRM_Sync_Logger::write(
                                    $trigger_type, $respond_id, null, 'failed',
                                    'Rate-limited batch could not be rescheduled — ' . count( $remaining ) . ' contact(s) will not be retried.'
                                );
                            }
                        }
                        break;
                    }

                    if ( 'resource_pending' === $code ) {
                        // Include the pending contact itself in the reschedule.
                        $remaining = array_slice( $ids, $processed_count - 1 );
                        if ( ! empty( $remaining ) ) {
                            $scheduled = wp_schedule_single_event(
                                time() + 180,
                                'dt_crm_sync_process_batch',
                                [ [ 'ids' => $remaining, '_token' => uniqid( '', true ), '_trigger' => $trigger_type ] ]
                            );
                            if ( false === $scheduled ) {
                                Disciple_Tools_CRM_Sync_Logger::write(
                                    $trigger_type, $respond_id, null, 'failed',
                                    'Resource-pending batch could not be rescheduled — ' . count( $remaining ) . ' contact(s) will not be retried.'
                                );
                            }
                        }
                        break;
                    }

                    // Any other per-contact error: log and continue with remaining contacts.
                    Disciple_Tools_CRM_Sync_Logger::write(
                        $trigger_type, $respond_id, null, 'failed',
                        $result->get_error_message()
                    );
                }
            }
        }

        /**
         * Process a single contact: match or create the DT post, map fields,
         * and import message history.
         *
         * Wraps do_process_single_contact() with a per-contact mutex so that two
         * overlapping batches (e.g. a slow batch still running when WP-Cron's
         * doing_cron lock expires and starts another) can't both decide the
         * contact doesn't exist yet and both create a duplicate DT post. Only the
         * pre-merge connector-ID lock is acquired here — do_process_single_contact()
         * acquires additional locks (canonical ID, phone) as those identities are
         * discovered, and every lock taken is released below regardless of which
         * ones ended up in play.
         *
         * @param string $respond_id    The Respond.io contact ID.
         * @param string $trigger_type  'scheduled', 'manual', or 'webhook'.
         * @param bool   $skip_existing When true, contacts already fully imported are skipped.
         * @return WP_Error|bool
         */
        protected function process_single_contact(
            string $respond_id,
            string $trigger_type,
            bool $skip_existing = true
        ): WP_Error|bool {
            $this->held_contact_locks = [];

            if ( ! $this->try_acquire_lock( $this->contact_lock_key( $respond_id ) ) ) {
                Disciple_Tools_CRM_Sync_Logger::write(
                    $trigger_type, $respond_id, null, 'skipped', 'locked by concurrent import'
                );
                return true;
            }

            try {
                return $this->do_process_single_contact( $respond_id, $trigger_type, $skip_existing );
            } finally {
                foreach ( $this->held_contact_locks as $held_lock ) {
                    $this->release_contact_lock( $held_lock );
                }
                $this->held_contact_locks = [];
            }
        }

        /**
         * Namespaces a lock suffix (a connector ID or a "phone_{digits}" key) under
         * the active connector's meta key prefix so different identity kinds — and
         * different connectors — never collide on the same option name.
         */
        private function contact_lock_key( string $suffix ): string {
            return $this->connector->get_meta_key_prefix() . $suffix;
        }

        /**
         * Acquires a lock and, on success, tracks it so process_single_contact()'s
         * finally block releases every lock taken for this contact — a single import
         * can end up holding one for the pre-merge ID, one for the canonical ID, and
         * one for the phone number, all at once.
         *
         * @return bool True if the lock was acquired.
         */
        private function try_acquire_lock( string $lock_name ): bool {
            if ( ! $this->acquire_contact_lock( $lock_name ) ) {
                return false;
            }
            $this->held_contact_locks[] = $lock_name;
            return true;
        }

        /**
         * Acquires a mutex for a connector ID using an atomic add_option() —
         * unlike get_transient()/set_transient(), the option table's unique key
         * makes the "does it exist yet" check and the write a single DB operation,
         * closing the race window a transient-based lock would still have.
         *
         * @return bool True if the lock was acquired.
         */
        private function acquire_contact_lock( string $lock_name ): bool {
            $option_name = 'dt_crm_sync_contact_lock_' . $lock_name;

            if ( add_option( $option_name, time(), '', 'no' ) ) {
                return true;
            }

            // Lock is held — if it's older than a batch's max runtime, the process
            // that took it almost certainly crashed without releasing it. Reclaim it.
            $held_since = (int) get_option( $option_name );
            if ( $held_since > 0 && ( time() - $held_since ) > 300 ) {
                delete_option( $option_name );
                return add_option( $option_name, time(), '', 'no' );
            }

            return false;
        }

        /**
         * Releases a lock acquired by acquire_contact_lock().
         */
        private function release_contact_lock( string $lock_name ): void {
            delete_option( 'dt_crm_sync_contact_lock_' . $lock_name );
        }

        /**
         * Match or create the DT post, map fields, and import message history
         * for a single contact. See process_single_contact() for the public entry
         * point — this method assumes the caller already holds the contact lock.
         *
         * Returns true on success or intentional skip, WP_Error on failure or
         * 429/449 (caller reschedules the remaining batch).
         *
         * @param string $respond_id    The Respond.io contact ID.
         * @param string $trigger_type  'scheduled', 'manual', or 'webhook'.
         * @param bool   $skip_existing When true, contacts already fully imported are skipped.
         * @return WP_Error|bool
         */
        private function do_process_single_contact(
            string $respond_id,
            string $trigger_type,
            bool $skip_existing = true
        ): WP_Error|bool {
            try {
                // Check by connector ID meta first — fastest path.
                $dt_post_id = $this->matcher->find_by_connector_id( $respond_id );
                $action     = $dt_post_id ? 'update' : 'create';

                // Skip existing contacts before making any API calls so that large
                // scheduled runs don't waste API quota re-importing contacts that
                // are already up to date. A contact whose history import never
                // finished (e.g. a prior run was cut off mid-batch) is not skipped,
                // so it gets a chance to complete instead of being stuck forever.
                $history_synced = $dt_post_id
                    && get_post_meta( $dt_post_id, $this->connector->get_meta_key_prefix() . 'history_synced', true );
                if ( $skip_existing && 'update' === $action && $history_synced ) {
                    Disciple_Tools_CRM_Sync_Logger::write(
                        $trigger_type, $respond_id, $dt_post_id, 'skipped', 'skip_existing'
                    );
                    return true;
                }

                $profile = $this->connector->get_contact( $respond_id );
                if ( is_wp_error( $profile ) ) {
                    return $profile;
                }

                // Fetch the social platform channels for this contact. We use the
                // result to tag the DT source with the originating platform(s)
                // (e.g. facebook, tiktok) in addition to the connector-level source.
                $channels = $this->connector->get_contact_channels( $respond_id );
                if ( is_wp_error( $channels ) ) {
                    return $channels;
                }

                $phone = sanitize_text_field( $profile['phone'] ?? '' );
                $email = sanitize_email( $profile['email'] ?? '' );

                // A phone number is a shared identity across Respond.io IDs — two
                // different (never-merged) contacts with the same number must be
                // serialized too, or the phone/email fallback below can be defeated
                // by the same race the connector-ID lock above already closes.
                //
                // Uses the matcher's own trailing-digit-suffix key (not the full
                // digit string) so two differently-formatted representations of the
                // same number — e.g. with vs. without a country code — contend for
                // the same lock instead of sailing past each other on different keys.
                $phone_lock_suffix = Disciple_Tools_CRM_Sync_Contact_Matcher::phone_lock_key( $phone );
                if ( '' !== $phone_lock_suffix ) {
                    if ( ! $this->try_acquire_lock( $this->contact_lock_key( 'phone_' . $phone_lock_suffix ) ) ) {
                        Disciple_Tools_CRM_Sync_Logger::write(
                            $trigger_type, $respond_id, $dt_post_id, 'skipped', 'locked by concurrent import (phone)'
                        );
                        return true;
                    }
                }

                // Same reasoning as the phone lock above, for the email fallback.
                // Lowercased because the DB's LIKE comparison in find_by_phone_or_email()
                // relies on WordPress's default case-insensitive table collation — the
                // lock must be at least as broad as that comparison or it can miss.
                if ( '' !== $email ) {
                    if ( ! $this->try_acquire_lock( $this->contact_lock_key( 'email_' . strtolower( $email ) ) ) ) {
                        Disciple_Tools_CRM_Sync_Logger::write(
                            $trigger_type, $respond_id, $dt_post_id, 'skipped', 'locked by concurrent import (email)'
                        );
                        return true;
                    }
                }

                // Fall back to phone/email lookup if meta hasn't been written yet.
                if ( ! $dt_post_id ) {
                    $dt_post_id = $this->matcher->find_by_phone_or_email( $phone, $email );
                    $action     = $dt_post_id ? 'update' : 'create';
                }

                // Handle merged contacts: if the returned profile ID differs from
                // what we requested, the original contact was absorbed by another.
                if ( ! empty( $profile['id'] ) && (string) $profile['id'] !== (string) $respond_id ) {
                    $canonical_id = (string) $profile['id'];

                    // The create/update decision below is made against the canonical
                    // ID, not the one we were locked on when this method started — so
                    // a second contact that merges into the same canonical ID needs to
                    // be blocked here too, before either one reads or writes against it.
                    if ( ! $this->try_acquire_lock( $this->contact_lock_key( $canonical_id ) ) ) {
                        Disciple_Tools_CRM_Sync_Logger::write(
                            $trigger_type, $canonical_id, $dt_post_id, 'skipped', 'locked by concurrent import (merge)'
                        );
                        return true;
                    }

                    $canonical_dt_post_id = $this->matcher->find_by_connector_id( $canonical_id );
                    $meta_key             = $this->connector->get_meta_key_prefix() . 'id';

                    if ( $canonical_dt_post_id && $dt_post_id && $canonical_dt_post_id !== $dt_post_id ) {
                        // Both posts already exist. Drop the connector-ID meta from the abandoned
                        // post so future polls don't route back to it, then switch to the canonical.
                        $old_dt_post_id = $dt_post_id;
                        delete_post_meta( $old_dt_post_id, $meta_key );
                        $dt_post_id = $canonical_dt_post_id;
                        $action     = 'update';
                        Disciple_Tools_CRM_Sync_Logger::write(
                            $trigger_type, $canonical_id, $canonical_dt_post_id, 'merged',
                            sprintf( 'absorbed: %s (post %d unreferenced)', $respond_id, $old_dt_post_id )
                        );
                    } elseif ( $canonical_dt_post_id && ! $dt_post_id ) {
                        // The canonical post already exists but this ID has no phone/email of
                        // its own to have matched it above (e.g. a bare social-media channel) —
                        // adopt the canonical post instead of falling through to create a
                        // redundant one.
                        $dt_post_id = $canonical_dt_post_id;
                        $action     = 'update';
                        Disciple_Tools_CRM_Sync_Logger::write(
                            $trigger_type, $canonical_id, $canonical_dt_post_id, 'merged',
                            sprintf( 'absorbed: %s (matched via canonical ID only)', $respond_id )
                        );
                    } elseif ( $dt_post_id ) {
                        // Old post exists, canonical doesn't yet. Repoint the meta to the new ID.
                        update_post_meta( $dt_post_id, $meta_key, sanitize_text_field( $canonical_id ) );
                        $action = 'update';
                        Disciple_Tools_CRM_Sync_Logger::write(
                            $trigger_type, $canonical_id, $dt_post_id, 'merged',
                            sprintf( 'absorbed: %s', $respond_id )
                        );
                    }
                    $respond_id = $canonical_id;
                }

                $fields = array_merge(
                    $this->mapper->map_core_fields( $profile, 'create' === $action ),
                    $this->mapper->map_custom_fields( $profile )
                );

                // Append platform-level source tags (e.g. facebook, tiktok) to the
                // connector-level source already set by map_core_fields(). DT's
                // multiselect field appends values, so this is safe on re-sync.
                $platform_sources = $this->mapper->map_platform_sources( $channels );
                if ( ! empty( $platform_sources['values'] ) ) {
                    $existing = $fields['sources']['values'] ?? [];
                    $fields['sources']['values'] = array_merge( $existing, $platform_sources['values'] );
                }

                if ( 'create' === $action ) {
                    $result = DT_Posts::create_post( 'contacts', $fields, true, false );
                } else {
                    $result = DT_Posts::update_post( 'contacts', $dt_post_id, $fields, true, false );
                }

                if ( is_wp_error( $result ) ) {
                    return new WP_Error(
                        'dt_write_failed',
                        $result->get_error_message(),
                        [ 'respond_id' => $respond_id ]
                    );
                }

                // On create: capture the new post ID from the result.
                // On update via phone/email fallback: the meta was never written
                // (find_existing_post() checks meta, not phone/email), so write it
                // now so subsequent polls use the fast indexed meta lookup instead
                // of the expensive LIKE query.
                //
                // If the meta write fails we bail here rather than continuing —
                // completing the import against a post with no connector ID means
                // the next run will create a duplicate instead of updating it.
                if ( 'create' === $action ) {
                    $dt_post_id   = (int) $result['ID'];
                    $meta_saved   = add_post_meta( $dt_post_id, $this->connector->get_meta_key_prefix() . 'id', $respond_id, true );
                    if ( false === $meta_saved ) {
                        return new WP_Error(
                            'meta_write_failed',
                            sprintf( 'Connector ID meta could not be written for new DT post %d (connector ID: %s).', $dt_post_id, $respond_id )
                        );
                    }
                } elseif ( ! get_post_meta( $dt_post_id, $this->connector->get_meta_key_prefix() . 'id', true ) ) {
                    $meta_saved = add_post_meta( $dt_post_id, $this->connector->get_meta_key_prefix() . 'id', $respond_id, true );
                    if ( false === $meta_saved ) {
                        return new WP_Error(
                            'meta_write_failed',
                            sprintf( 'Connector ID meta could not be written for existing DT post %d (connector ID: %s).', $dt_post_id, $respond_id )
                        );
                    }
                }

                // Upsert activity-feed note for mapped fields.
                $activity_fields = $this->mapper->get_activity_feed_fields( $profile );
                if ( ! empty( $activity_fields ) ) {
                    $this->activity_feed_writer->upsert(
                        $dt_post_id,
                        $activity_fields,
                        $this->connector->get_meta_key_prefix(),
                        $this->connector->get_label()
                    );
                }

                // Import message history.
                // Build the display name for the contact side of the conversation log.
                // Priority: Respond.io profile name → DT post title → generic fallback.
                $first        = sanitize_text_field( $profile['firstName'] ?? '' );
                $last         = sanitize_text_field( $profile['lastName'] ?? '' );
                $contact_name = trim( $first . ' ' . $last );
                if ( '' === $contact_name && $dt_post_id ) {
                    $contact_name = get_the_title( $dt_post_id );
                }
                if ( '' === $contact_name ) {
                    $contact_name = 'Contact';
                }

                $msg_target = $this->mapper->get_message_history_target();
                $msg_error  = $this->message_importer->import( $respond_id, $dt_post_id, 0, $msg_target, $trigger_type, $contact_name );
                if ( is_wp_error( $msg_error ) ) {
                    // Propagate 429 / 449 for batch rescheduling.
                    return $msg_error;
                }

                update_post_meta( $dt_post_id, $this->connector->get_meta_key_prefix() . 'history_synced', '1' );

                Disciple_Tools_CRM_Sync_Logger::write(
                    $trigger_type, $respond_id, $dt_post_id, 'success', $action
                );

                return true;

            } catch ( \Throwable $e ) {
                Disciple_Tools_CRM_Sync_Logger::write(
                    $trigger_type, $respond_id, null, 'failed',
                    get_class( $e ) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine()
                );
                return new WP_Error(
                    'unexpected_exception',
                    $e->getMessage(),
                    [ 'file' => $e->getFile(), 'line' => $e->getLine() ]
                );
            }
        }

        /**
         * Resolve the active connector from the registry.
         *
         * @return Disciple_Tools_CRM_Sync_Abstract_Connector|WP_Error
         */
        private function get_active_connector(): Disciple_Tools_CRM_Sync_Abstract_Connector|\WP_Error {
            $connector = Disciple_Tools_CRM_Sync_Connector_Registry::get_active_connector();

            if ( null === $connector ) {
                return new \WP_Error(
                    'missing_credentials',
                    __( 'No CRM connector is configured or credentials could not be decrypted. Please configure a connector on the Configuration tab.', 'disciple-tools-crm-sync' )
                );
            }

            return $connector;
        }
    }
}
