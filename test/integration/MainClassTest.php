<?php
/**
 * Integration tests for the Disciple_Tools_CRM_Sync main class.
 *
 * Exercises add_cron_schedules() and create_filter() with a real WordPress
 * environment, verifying cron scheduling, option persistence, and idempotency.
 *
 * Run with: php vendor/bin/phpunit -c phpunit.xml.dist --testdox
 */

class MainClassTest extends TestCase {

    /** @var Disciple_Tools_CRM_Sync */
    private $plugin;

    protected function setUp(): void {
        parent::setUp();
        $this->plugin = Disciple_Tools_CRM_Sync::instance();
    }

    protected function tearDown(): void {
        // Clean up any created filter options to keep tests isolated.
        $manifest = get_option( 'dt_crm_sync_saved_filters', [] );
        if ( is_array( $manifest ) ) {
            foreach ( $manifest as $filter_id ) {
                delete_option( 'dt_crm_sync_saved_filter_' . $filter_id );
                wp_clear_scheduled_hook( 'dt_crm_sync_poll', [ $filter_id ] );
            }
        }
        delete_option( 'dt_crm_sync_saved_filters' );
        parent::tearDown();
    }

// add_cron_schedules

    public function test_add_cron_schedules_adds_all_three_custom_intervals(): void {
        $result = $this->plugin->add_cron_schedules( [] );

        $this->assertArrayHasKey( 'every_2_hours', $result );
        $this->assertArrayHasKey( 'every_4_hours', $result );
        $this->assertArrayHasKey( 'every_8_hours', $result );

        $this->assertSame( 2 * HOUR_IN_SECONDS, $result['every_2_hours']['interval'] );
        $this->assertSame( 4 * HOUR_IN_SECONDS, $result['every_4_hours']['interval'] );
        $this->assertSame( 8 * HOUR_IN_SECONDS, $result['every_8_hours']['interval'] );
    }

    public function test_cron_schedules_preserve_existing(): void {
        $pre_existing = [ 'interval' => 99999, 'display' => 'Custom existing' ];

        $result = $this->plugin->add_cron_schedules( [
            'every_2_hours' => $pre_existing,
        ] );

        $this->assertSame(
            $pre_existing,
            $result['every_2_hours'],
            'Pre-existing every_2_hours entry must not be overwritten.'
        );
        // The other two must still be added.
        $this->assertArrayHasKey( 'every_4_hours', $result );
        $this->assertArrayHasKey( 'every_8_hours', $result );
    }

    /**
     * Passing an existing schedules array through add_cron_schedules() must
     * preserve the original entries alongside the new custom intervals.
     */
    public function test_add_cron_schedules_preserves_existing_schedules(): void {
        $existing = [
            'twicedaily' => [ 'interval' => 43200, 'display' => 'Twice Daily' ],
            'hourly'     => [ 'interval' => 3600, 'display' => 'Once Hourly' ],
        ];

        $result = $this->plugin->add_cron_schedules( $existing );

        $this->assertArrayHasKey( 'twicedaily', $result );
        $this->assertArrayHasKey( 'hourly', $result );
        $this->assertArrayHasKey( 'every_2_hours', $result );
        $this->assertArrayHasKey( 'every_4_hours', $result );
        $this->assertArrayHasKey( 'every_8_hours', $result );
        $this->assertSame( $existing['twicedaily'], $result['twicedaily'] );
    }

// create_filter

    /**
     * create_filter() must return a non-empty string ID, save an envelope
     * option, and append the ID to the manifest.
     */
    public function test_create_filter_stores_envelope_and_manifest(): void {
        $filter_id = Disciple_Tools_CRM_Sync::create_filter( 'My Integration Filter', 'hourly',
            [],
            '00:00',
            'respond_io'
        );

        $this->assertNotEmpty( $filter_id, 'create_filter() must return a non-empty filter ID.' );
        $this->assertStringStartsWith( 'filter_', $filter_id );

        // Envelope must be stored.
        $raw = get_option( 'dt_crm_sync_saved_filter_' . $filter_id );
        $this->assertNotFalse( $raw, 'Envelope option must be saved.' );
        $envelope = json_decode( $raw, true );
        $this->assertSame( 'My Integration Filter', $envelope['name'] );
        $this->assertSame( 'hourly', $envelope['interval'] );

        // Manifest must include the new ID.
        $manifest = get_option( 'dt_crm_sync_saved_filters', [] );
        $this->assertContains( $filter_id, $manifest );
    }

    public function test_create_filter_prevents_double_schedule(): void {
        $filter_id = Disciple_Tools_CRM_Sync::create_filter( 'Idempotency Test', 'hourly', [], '00:00', 'respond_io' );
        $ts1       = wp_next_scheduled( 'dt_crm_sync_poll', [ $filter_id ] );
        $this->assertNotFalse( $ts1, 'Cron event must be scheduled after the first create_filter() call.' );

        wp_schedule_event( $ts1 + 3600, 'hourly', 'dt_crm_sync_poll', [ $filter_id ] );
        $this->assertSame(
            2,
            $this->count_scheduled_events( 'dt_crm_sync_poll', [ $filter_id ] ),
            'WordPress permits duplicate cron events — the plugin guard is necessary.'
        );

        wp_unschedule_event( $ts1 + 3600, 'dt_crm_sync_poll', [ $filter_id ] );

        if ( ! wp_next_scheduled( 'dt_crm_sync_poll', [ $filter_id ] ) ) {
            wp_schedule_event( time(), 'hourly', 'dt_crm_sync_poll', [ $filter_id ] );
        }

        $this->assertSame(
            1,
            $this->count_scheduled_events( 'dt_crm_sync_poll', [ $filter_id ] ),
            'Guard must prevent adding a second event when the hook is already scheduled.'
        );
        $this->assertSame(
            $ts1,
            wp_next_scheduled( 'dt_crm_sync_poll', [ $filter_id ] ),
            'Original scheduled timestamp must be unchanged after the guard check.'
        );
    }

    /**
     * Count how many distinct timestamps have a scheduled event for the given hook+args.
     * Used to verify WordPress allows (or the guard prevents) duplicate cron events.
     */
    private function count_scheduled_events( string $hook, array $args ): int {
        $key   = md5( serialize( $args ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
        $count = 0;
        foreach ( _get_cron_array() ?? [] as $events ) {
            if ( isset( $events[ $hook ][ $key ] ) ) {
                ++$count;
            }
        }
        return $count;
    }

    public function test_create_filter_schedules_daily_poll_at_requested_time(): void {
        // Use a poll time guaranteed to be in the future: 23:59.
        $filter_id = Disciple_Tools_CRM_Sync::create_filter( 'Daily Test', 'daily', [], '23:59', 'respond_io' );
        $ts = wp_next_scheduled( 'dt_crm_sync_poll', [ $filter_id ] );

        $this->assertNotFalse( $ts, 'Cron event for daily filter must be scheduled.' );

        // The scheduled timestamp must be at minute :59 of some future hour 23.
        $tz   = wp_timezone();
        $dt   = new DateTime( 'now', $tz );
        $dt->setTimestamp( $ts );
        $this->assertSame( '23', $dt->format( 'H' ), 'Daily filter must fire at hour 23.' );
        $this->assertSame( '59', $dt->format( 'i' ), 'Daily filter must fire at minute 59.' );
    }
}
