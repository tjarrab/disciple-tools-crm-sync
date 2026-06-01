<?php
/**
 * Unit tests for Disciple_Tools_CRM_Sync_Translation_Rate_Limiter.
 *
 * Covers window reset, count increment, over-limit rejection, and the
 * unlimited (daily_limit=0) bypass.
 */

use Brain\Monkey\Functions;

class TranslationRateLimiterTest extends BrainMonkeyTestCase {

    protected function setUp(): void {
        parent::setUp();
    }

    // --- is_allowed ---

    public function test_is_allowed_returns_true_when_count_below_limit(): void {
        Functions\when( 'get_option' )->alias( static function ( $key, $default = null ) {
            if ( 'dt_crm_sync_translation_usage' === $key ) {
                return [ 'window_start' => time(), 'count' => 4 ];
            }
            return $default;
        } );
        Functions\when( 'update_option' )->justReturn( true );

        $limiter = new Disciple_Tools_CRM_Sync_Translation_Rate_Limiter();
        $this->assertTrue( $limiter->is_allowed( 5 ) );
    }

    public function test_is_allowed_returns_false_when_count_at_limit(): void {
        Functions\when( 'get_option' )->alias( static function ( $key, $default = null ) {
            if ( 'dt_crm_sync_translation_usage' === $key ) {
                return [ 'window_start' => time(), 'count' => 5 ];
            }
            return $default;
        } );
        Functions\when( 'update_option' )->justReturn( true );

        $limiter = new Disciple_Tools_CRM_Sync_Translation_Rate_Limiter();
        $this->assertFalse( $limiter->is_allowed( 5 ) );
    }

    public function test_is_allowed_returns_true_for_zero_limit(): void {
        // daily_limit = 0 means unlimited; the rate check is bypassed entirely.
        Functions\when( 'get_option' )->alias( static function ( $key, $default = null ) {
            if ( 'dt_crm_sync_translation_usage' === $key ) {
                // Count already at an arbitrarily high value.
                return [ 'window_start' => time(), 'count' => 99999 ];
            }
            return $default;
        } );
        Functions\when( 'update_option' )->justReturn( true );

        $limiter = new Disciple_Tools_CRM_Sync_Translation_Rate_Limiter();
        $this->assertTrue( $limiter->is_allowed( 0 ) );
    }

    // --- window reset ---

    public function test_window_resets_after_24_hours(): void {
        $old_window_start = time() - ( DAY_IN_SECONDS + 1 );
        $saved_state      = null;

        Functions\when( 'get_option' )->alias( static function ( $key, $default = null ) use ( $old_window_start ) {
            if ( 'dt_crm_sync_translation_usage' === $key ) {
                return [ 'window_start' => $old_window_start, 'count' => 10 ];
            }
            return $default;
        } );
        Functions\when( 'update_option' )->alias( static function ( $key, $value ) use ( &$saved_state ) {
            if ( 'dt_crm_sync_translation_usage' === $key ) {
                $saved_state = $value;
            }
            return true;
        } );

        $limiter = new Disciple_Tools_CRM_Sync_Translation_Rate_Limiter();
        // Calling is_allowed() triggers maybe_reset_window().
        $limiter->is_allowed( 5 );

        $this->assertNotNull( $saved_state, 'update_option should have been called after window reset.' );
        $this->assertSame( 0, $saved_state['count'], 'Count should be reset to 0 after window expires.' );
        $this->assertGreaterThan( $old_window_start, $saved_state['window_start'], 'window_start should advance.' );
    }

    // --- increment ---

    public function test_increment_increases_stored_count(): void {
        $saved_state = null;

        Functions\when( 'get_option' )->alias( static function ( $key, $default = null ) {
            if ( 'dt_crm_sync_translation_usage' === $key ) {
                return [ 'window_start' => time(), 'count' => 3 ];
            }
            return $default;
        } );
        Functions\when( 'update_option' )->alias( static function ( $key, $value ) use ( &$saved_state ) {
            if ( 'dt_crm_sync_translation_usage' === $key ) {
                $saved_state = $value;
            }
            return true;
        } );

        $limiter = new Disciple_Tools_CRM_Sync_Translation_Rate_Limiter();
        $limiter->increment();

        $this->assertNotNull( $saved_state );
        $this->assertSame( 4, $saved_state['count'] );
    }

    // --- get_count ---

    public function test_get_count_returns_current_window_count(): void {
        Functions\when( 'get_option' )->alias( static function ( $key, $default = null ) {
            if ( 'dt_crm_sync_translation_usage' === $key ) {
                return [ 'window_start' => time(), 'count' => 7 ];
            }
            return $default;
        } );
        Functions\when( 'update_option' )->justReturn( true );

        $limiter = new Disciple_Tools_CRM_Sync_Translation_Rate_Limiter();
        $this->assertSame( 7, $limiter->get_count() );
    }
}
