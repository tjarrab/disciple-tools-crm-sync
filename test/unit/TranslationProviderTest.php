<?php
/**
 * Unit tests for Disciple_Tools_CRM_Sync_Gemini_Translation_Provider.
 *
 * Covers the generateContent request shape, response parsing, model list
 * caching, and error handling for non-2xx responses.
 */

use Brain\Monkey\Functions;

class TranslationProviderTest extends BrainMonkeyTestCase {

    private Disciple_Tools_CRM_Sync_Gemini_Translation_Provider $provider;

    protected function setUp(): void {
        parent::setUp();
        $this->provider = new Disciple_Tools_CRM_Sync_Gemini_Translation_Provider( 'test-key', 'models/gemini-2.0-flash' );
    }

    // --- translate_with_meta ---

    public function test_translate_sends_correct_request_body(): void {
        $captured_args = null;

        Functions\when( 'add_query_arg' )->alias( static function ( $args, $url = '' ) {
            if ( is_array( $args ) ) {
                return $url . '?' . http_build_query( $args );
            }
            return $url . '?' . $args . '=' . $url; // fallback, not exercised here
        } );

        Functions\when( 'wp_safe_remote_post' )->alias( static function ( string $url, array $args ) use ( &$captured_args ) {
            $captured_args = $args;
            return [
                'response' => [ 'code' => 200 ],
                'body'     => json_encode( [
                    'candidates' => [
                        [ 'content' => [ 'parts' => [ [ 'text' => 'hello' ] ] ] ],
                    ],
                ] ),
            ];
        } );

        Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn( $r ) => $r['response']['code'] );
        Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( $r ) => $r['body'] );

        $this->provider->translate_with_meta( 'bonjour', 'translate: ' );

        $this->assertNotNull( $captured_args, 'wp_safe_remote_post was not called.' );
        $body = json_decode( $captured_args['body'], true );
        $this->assertSame( 'translate: bonjour', $body['contents'][0]['parts'][0]['text'] );
    }

    public function test_translate_extracts_translation_from_response(): void {
        Functions\when( 'add_query_arg' )->alias( static fn( $args, $url = '' ) => $url );
        Functions\when( 'wp_safe_remote_post' )->alias( static function () {
            return [
                'response' => [ 'code' => 200 ],
                'body'     => json_encode( [
                    'candidates' => [
                        [ 'content' => [ 'parts' => [ [ 'text' => 'hello world' ] ] ] ],
                    ],
                ] ),
            ];
        } );
        Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn( $r ) => $r['response']['code'] );
        Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( $r ) => $r['body'] );

        $result = $this->provider->translate_with_meta( 'bonjour monde', 'translate: ' );

        $this->assertIsArray( $result );
        $this->assertSame( 'hello world', $result['translation'] );
        $this->assertSame( 200, $result['http_status'] );
    }

    public function test_translate_returns_wp_error_on_non_2xx(): void {
        Functions\when( 'add_query_arg' )->alias( static fn( $args, $url = '' ) => $url );
        Functions\when( 'wp_safe_remote_post' )->alias( static function () {
            return [
                'response' => [ 'code' => 401 ],
                'body'     => json_encode( [ 'error' => [ 'message' => 'API key not valid.' ] ] ),
            ];
        } );
        Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn( $r ) => $r['response']['code'] );
        Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( $r ) => $r['body'] );

        $result = $this->provider->translate_with_meta( 'bonjour', '' );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'gemini_translate_error', $result->get_error_code() );
    }

    public function test_translate_returns_wp_error_when_translation_empty(): void {
        Functions\when( 'add_query_arg' )->alias( static fn( $args, $url = '' ) => $url );
        Functions\when( 'wp_safe_remote_post' )->alias( static function () {
            return [
                'response' => [ 'code' => 200 ],
                'body'     => json_encode( [ 'candidates' => [] ] ),
            ];
        } );
        Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn( $r ) => $r['response']['code'] );
        Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( $r ) => $r['body'] );

        $result = $this->provider->translate_with_meta( 'bonjour', '' );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'gemini_empty_response', $result->get_error_code() );
    }

    // --- get_models ---

    public function test_get_models_caches_result_in_transient(): void {
        Functions\when( 'get_transient' )->justReturn( false );
        Functions\when( 'add_query_arg' )->alias( static fn( $args, $url = '' ) => $url );
        Functions\when( 'wp_safe_remote_get' )->alias( static function () {
            return [
                'response' => [ 'code' => 200 ],
                'body'     => json_encode( [
                    'models' => [
                        [
                            'name'                       => 'models/gemini-2.0-flash',
                            'displayName'                => 'Gemini 2.0 Flash',
                            'supportedGenerationMethods' => [ 'generateContent' ],
                        ],
                    ],
                ] ),
            ];
        } );
        Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn( $r ) => $r['response']['code'] );
        Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( $r ) => $r['body'] );

        $set_transient_called = false;
        Functions\when( 'set_transient' )->alias( static function () use ( &$set_transient_called ) {
            $set_transient_called = true;
            return true;
        } );
        Functions\when( 'sanitize_text_field' )->alias( static fn( $s ) => $s );

        $models = $this->provider->get_models();

        $this->assertIsArray( $models );
        $this->assertCount( 1, $models );
        $this->assertSame( 'models/gemini-2.0-flash', $models[0]['value'] );
        $this->assertTrue( $set_transient_called, 'Result should be stored in a transient.' );
    }

    public function test_get_models_returns_cached_result_without_api_call(): void {
        $cached = [ [ 'value' => 'models/gemini-2.0-flash', 'label' => 'Gemini 2.0 Flash' ] ];
        Functions\when( 'get_transient' )->justReturn( $cached );

        // If the cache is hit, the API should not be called at all.
        Functions\expect( 'wp_safe_remote_get' )->never();

        $result = $this->provider->get_models();
        $this->assertSame( $cached, $result );
    }

    public function test_get_models_filters_out_non_generate_content_models(): void {
        Functions\when( 'get_transient' )->justReturn( false );
        Functions\when( 'add_query_arg' )->alias( static fn( $args, $url = '' ) => $url );
        Functions\when( 'wp_safe_remote_get' )->alias( static function () {
            return [
                'response' => [ 'code' => 200 ],
                'body'     => json_encode( [
                    'models' => [
                        [
                            'name'                       => 'models/embedding-001',
                            'displayName'                => 'Embedding 001',
                            'supportedGenerationMethods' => [ 'embedContent' ],
                        ],
                        [
                            'name'                       => 'models/gemini-2.0-flash',
                            'displayName'                => 'Gemini 2.0 Flash',
                            'supportedGenerationMethods' => [ 'generateContent' ],
                        ],
                    ],
                ] ),
            ];
        } );
        Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn( $r ) => $r['response']['code'] );
        Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( $r ) => $r['body'] );
        Functions\when( 'set_transient' )->justReturn( true );
        Functions\when( 'sanitize_text_field' )->alias( static fn( $s ) => $s );

        $models = $this->provider->get_models();

        $this->assertCount( 1, $models );
        $this->assertSame( 'models/gemini-2.0-flash', $models[0]['value'] );
    }
}
