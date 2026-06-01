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
}
