<?php
/**
 * Unit tests for Disciple_Tools_CRM_Sync_Translation_Service.
 *
 * Covers best-effort behaviour: provider errors and rate-limit blocks both
 * return the original text and log the outcome without interrupting the import.
 */

class TranslationServiceTest extends BrainMonkeyTestCase {

    /** @var \PHPUnit\Framework\MockObject\MockObject&Disciple_Tools_CRM_Sync_Abstract_Translation_Provider */
    private $provider;

    /** @var \PHPUnit\Framework\MockObject\MockObject&Disciple_Tools_CRM_Sync_Translation_Rate_Limiter */
    private $rate_limiter;

    protected function setUp(): void {
        parent::setUp();
        $this->provider     = $this->createMock( Disciple_Tools_CRM_Sync_Abstract_Translation_Provider::class );
        $this->rate_limiter = $this->createMock( Disciple_Tools_CRM_Sync_Translation_Rate_Limiter::class );
    }

    private function make_service( string $prompt = 'translate: ', int $daily_limit = 100 ): Disciple_Tools_CRM_Sync_Translation_Service {
        return new Disciple_Tools_CRM_Sync_Translation_Service(
            $this->provider,
            $this->rate_limiter,
            $prompt,
            $daily_limit
        );
    }

    // --- success path ---

    public function test_translate_returns_translated_text_on_success(): void {
        $this->rate_limiter->method( 'is_allowed' )->willReturn( true );
        $this->provider->method( 'translate_with_meta' )->willReturn( [
            'translation'      => 'hello',
            'http_status'      => 200,
            'response_preview' => '{"candidates":[{"conte',
        ] );
        $this->rate_limiter->expects( $this->once() )->method( 'increment' );

        global $wpdb;
        $service = $this->make_service();
        $result  = $service->translate( 'bonjour', 'rid_1' );

        $this->assertSame( 'hello', $result );
        $this->assertCount( 1, $wpdb->insert_calls, 'Translation logger should record one row.' );
        $this->assertSame( 'success', $wpdb->insert_calls[0]['data']['status'] );
    }

    // --- provider WP_Error ---

    public function test_translate_returns_original_when_provider_returns_wp_error(): void {
        $this->rate_limiter->method( 'is_allowed' )->willReturn( true );
        $this->provider->method( 'translate_with_meta' )->willReturn(
            new WP_Error( 'gemini_translate_error', 'API key not valid.', [ 'http_status' => 401, 'response_preview' => '{"error":{"code":401' ] )
        );
        // increment() must not be called on a failed translation.
        $this->rate_limiter->expects( $this->never() )->method( 'increment' );

        global $wpdb;
        $service = $this->make_service();
        $result  = $service->translate( 'bonjour', 'rid_2' );

        $this->assertSame( 'bonjour', $result, 'Original text must be returned on provider error.' );
        $this->assertCount( 1, $wpdb->insert_calls );
        $this->assertSame( 'failed', $wpdb->insert_calls[0]['data']['status'] );
    }

    // --- rate limited ---

    public function test_translate_returns_original_when_rate_limited(): void {
        $this->rate_limiter->method( 'is_allowed' )->willReturn( false );
        // Provider should not be called when rate-limited.
        $this->provider->expects( $this->never() )->method( 'translate_with_meta' );

        global $wpdb;
        $service = $this->make_service();
        $result  = $service->translate( 'bonjour', 'rid_3' );

        $this->assertSame( 'bonjour', $result, 'Original text must be returned when rate-limited.' );
        $this->assertCount( 1, $wpdb->insert_calls );
        $this->assertSame( 'rate_limited', $wpdb->insert_calls[0]['data']['status'] );
    }

    // --- daily_limit = 0 (unlimited) ---

    public function test_translate_with_zero_limit_skips_rate_check(): void {
        // The rate limiter's is_allowed(0) returns true (unlimited path).
        $this->rate_limiter->method( 'is_allowed' )->with( 0 )->willReturn( true );
        $this->provider->method( 'translate_with_meta' )->willReturn( [
            'translation'      => 'hello',
            'http_status'      => 200,
            'response_preview' => 'hello',
        ] );

        $service = $this->make_service( 'translate: ', 0 );
        $result  = $service->translate( 'bonjour', 'rid_4' );

        $this->assertSame( 'hello', $result );
    }

    // =========================================================================
    // translate_batch()
    // =========================================================================

    // --- key remapping ---

    /**
     * Providers are free to return sequential integer keys even when the input
     * batch uses sparse keys.  The service must re-map results positionally so
     * translations land on the correct source strings.
     */
    public function test_translate_batch_maps_sequential_provider_keys_to_original_keys(): void {
        $this->rate_limiter->method( 'get_remaining' )->willReturn( 100 );
        $this->provider->method( 'translate_batch' )->willReturn( [
            'translations'     => [ 0 => 'translated-a', 1 => 'translated-b' ],
            'http_status'      => 200,
            'response_preview' => '[',
        ] );
        $this->rate_limiter->expects( $this->once() )->method( 'increment' )->with( 2 );

        $service = $this->make_service();
        $result  = $service->translate_batch( [ 5 => 'a', 12 => 'b' ], 'rid_5' );

        $this->assertSame( 'translated-a', $result[5], 'Key 5 should receive the first translation.' );
        $this->assertSame( 'translated-b', $result[12], 'Key 12 should receive the second translation.' );
    }

    // --- success, full batch ---

    public function test_translate_batch_full_batch_success(): void {
        $this->rate_limiter->method( 'get_remaining' )->willReturn( 100 );
        $this->provider->method( 'translate_batch' )->willReturn( [
            'translations'     => [ 0 => 'hello', 1 => 'world' ],
            'http_status'      => 200,
            'response_preview' => '[',
        ] );
        $this->rate_limiter->expects( $this->once() )->method( 'increment' )->with( 2 );

        global $wpdb;
        $service = $this->make_service();
        $result  = $service->translate_batch( [ 0 => 'bonjour', 1 => 'monde' ], 'rid_6' );

        $this->assertSame( [ 0 => 'hello', 1 => 'world' ], $result );
        $this->assertSame( 'success', $wpdb->insert_calls[0]['data']['status'] );
    }

    // --- rate limited at batch entry ---

    public function test_translate_batch_rate_limited_returns_originals(): void {
        $this->rate_limiter->method( 'get_remaining' )->willReturn( 0 );
        $this->provider->expects( $this->never() )->method( 'translate_batch' );

        global $wpdb;
        $service = $this->make_service();
        $result  = $service->translate_batch( [ 0 => 'bonjour', 1 => 'monde' ], 'rid_7' );

        $this->assertSame( [ 0 => 'bonjour', 1 => 'monde' ], $result, 'Originals must be returned when rate-limited.' );
        $this->assertSame( 'rate_limited', $wpdb->insert_calls[0]['data']['status'] );
    }

    // --- partial batch due to rate limit mid-batch ---

    public function test_translate_batch_partial_batch_translates_allowed_slice(): void {
        // Only 1 translation remaining; second item should keep original.
        $this->rate_limiter->method( 'get_remaining' )->willReturn( 1 );
        $this->provider->method( 'translate_batch' )
            ->willReturnCallback( function ( array $texts ) {
                // Provider receives only the first item; return sequential keys.
                return [
                    'translations'     => array_values( array_map( fn( $t ) => 'translated: ' . $t, $texts ) ),
                    'http_status'      => 200,
                    'response_preview' => '[',
                ];
            } );
        $this->rate_limiter->expects( $this->once() )->method( 'increment' )->with( 1 );

        global $wpdb;
        $service = $this->make_service();
        $result  = $service->translate_batch( [ 7 => 'first', 9 => 'second' ], 'rid_8' );

        $this->assertSame( 'translated: first', $result[7], 'First item should be translated.' );
        $this->assertSame( 'second', $result[9], 'Second item should keep the original when skipped by rate limit.' );
        $this->assertSame( 'partial', $wpdb->insert_calls[0]['data']['status'] );
    }

    // --- provider WP_Error ---

    public function test_translate_batch_provider_wp_error_returns_originals(): void {
        $this->rate_limiter->method( 'get_remaining' )->willReturn( 100 );
        $this->provider->method( 'translate_batch' )->willReturn(
            new WP_Error( 'gemini_batch_error', 'API quota exceeded.', [ 'http_status' => 429, 'response_preview' => '{"error":' ] )
        );
        $this->rate_limiter->expects( $this->never() )->method( 'increment' );

        global $wpdb;
        $service = $this->make_service();
        $result  = $service->translate_batch( [ 0 => 'bonjour', 1 => 'monde' ], 'rid_9' );

        $this->assertSame( [ 0 => 'bonjour', 1 => 'monde' ], $result, 'Originals must be returned on provider error.' );
        $this->assertSame( 'failed', $wpdb->insert_calls[0]['data']['status'] );
    }
}
