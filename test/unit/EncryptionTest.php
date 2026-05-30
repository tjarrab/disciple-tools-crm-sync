<?php
/**
 * Unit tests for AES-256-CBC encrypt / decrypt helpers in Disciple_Tools_CRM_Sync.
 *
 * The production class lives in the main plugin file and is not loaded in the
 * unit-test bootstrap; instead the bootstrap defines a compatible stub that
 * exposes the same encrypt_value() / decrypt_value() API together with a
 * $test_decrypt_fn override hook so the real decryption logic can be exercised
 * without loading WordPress.
 *
 * Run with: php vendor/bin/phpunit -c phpunit-unit.xml.dist --testdox
 */
class EncryptionTest extends BrainMonkeyTestCase {

    /**
     * A deterministic 32-byte key, base64-encoded, used for round-trip tests.
     * Must be exactly 32 bytes after base64-decoding to satisfy AES-256.
     */
    private const TEST_KEY_B64 = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='; // 32 zero bytes

    /**
     * Returns the real AES-256-CBC decrypt logic as a closure for injection via
     * the test hook. Centralised here to avoid copy-pasting the same 12-line
     * closure across every test that needs it.
     */
    private function make_decrypt_closure(): \Closure {
        return static function ( string $value ): string|false {
            $stored_key = get_option( 'dt_crm_sync_encryption_key', '' );
            if ( empty( $stored_key ) ) {
                return false;
            }
            $raw = base64_decode( $value, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
            if ( false === $raw || strlen( $raw ) <= 16 ) {
                return false;
            }
            $raw_key    = base64_decode( $stored_key, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
            $iv         = substr( $raw, 0, 16 );
            $ciphertext = substr( $raw, 16 );
            $plaintext  = openssl_decrypt( $ciphertext, 'AES-256-CBC', $raw_key, OPENSSL_RAW_DATA, $iv );
            return false === $plaintext ? false : $plaintext;
        };
    }

// decrypt_value: invalid / missing input

    public function test_decrypt_empty_string(): void {
        // decrypt_value() with no test hook set always returns false (stub default).
        // Passing an empty string exercises the "nothing to decrypt" path.
        $result = Disciple_Tools_CRM_Sync::decrypt_value( '' );
        $this->assertFalse( $result );
    }

    public function test_decrypt_missing_key(): void {
        // Inject the real decrypt logic via the test hook, but return an empty key
        // from get_option() so decryption cannot proceed.
        Disciple_Tools_CRM_Sync::$test_decrypt_fn = $this->make_decrypt_closure();

        \Brain\Monkey\Functions\when( 'get_option' )->justReturn( '' );

        $result = Disciple_Tools_CRM_Sync::decrypt_value( 'irrelevant_ciphertext' );
        $this->assertFalse( $result );
    }

    public function test_decrypt_corrupted_ciphertext(): void {
        // Inject real decrypt logic; provide a valid key but garbled ciphertext.
        Disciple_Tools_CRM_Sync::$test_decrypt_fn = $this->make_decrypt_closure();

        \Brain\Monkey\Functions\when( 'get_option' )->justReturn( self::TEST_KEY_B64 );

        // "not-valid-ciphertext" is a syntactically valid base64 string but its
        // decoded form is only 16 bytes (just an IV, no ciphertext body) so the
        // length guard catches it before openssl_decrypt is ever called.
        $result = Disciple_Tools_CRM_Sync::decrypt_value( base64_encode( str_repeat( "\x00", 16 ) ) );
        $this->assertFalse( $result );
    }

    public function test_decrypt_invalid_length(): void {
        // 33 bytes decoded: passes the strlen > 16 guard (IV = 16 b, ciphertext = 17 b),
        // but 17 bytes is not a valid AES block size so openssl_decrypt() returns false,
        // exercising the openssl_decrypt rejection path rather than the length guard.
        Disciple_Tools_CRM_Sync::$test_decrypt_fn = $this->make_decrypt_closure();

        \Brain\Monkey\Functions\when( 'get_option' )->justReturn( self::TEST_KEY_B64 );

        $result = Disciple_Tools_CRM_Sync::decrypt_value( base64_encode( str_repeat( "\x00", 33 ) ) );
        $this->assertFalse( $result );
    }

// encrypt_value: missing key

    public function test_encrypt_missing_key_throws(): void {
        \Brain\Monkey\Functions\when( 'get_option' )->justReturn( '' );

        $this->expectException( \RuntimeException::class );
        Disciple_Tools_CRM_Sync::encrypt_value( 'plaintext_credential' );
    }

// Round-trip

    public function test_encrypt_decrypt_roundtrip(): void {
        // Wire the real decrypt logic into the test hook.
        Disciple_Tools_CRM_Sync::$test_decrypt_fn = $this->make_decrypt_closure();

        \Brain\Monkey\Functions\when( 'get_option' )->justReturn( self::TEST_KEY_B64 );

        $original  = 'super_secret_api_token_12345';
        $encrypted = Disciple_Tools_CRM_Sync::encrypt_value( $original );

        $this->assertNotSame( $original, $encrypted, 'Encrypted value must differ from plaintext.' );

        $decrypted = Disciple_Tools_CRM_Sync::decrypt_value( $encrypted );
        $this->assertSame( $original, $decrypted, 'Decrypted value must match the original plaintext.' );
    }

// Connector Registry integration

    public function test_registry_passes_decrypted_credentials_to_connector(): void {
        // Encrypt a fake token, store it as the active connector's api_token,
        // then verify the registry decrypts it before building the connector.
        Disciple_Tools_CRM_Sync::$test_decrypt_fn = $this->make_decrypt_closure();

        $plaintext_token = 'my_plain_api_token';

        \Brain\Monkey\Functions\when( 'get_option' )->alias(
            static function ( string $option ) use ( $plaintext_token ): mixed {
                if ( 'dt_crm_sync_encryption_key' === $option ) {
                    return self::TEST_KEY_B64;
                }
                if ( 'dt_crm_sync_settings' === $option ) {
                    return [
                        'active_connector' => 'respond_io',
                        'connectors'       => [
                            'respond_io' => [
                                'api_url'   => 'https://api.test',
                                'api_token' => Disciple_Tools_CRM_Sync::encrypt_value( $plaintext_token ),
                            ],
                        ],
                    ];
                }
                return false;
            }
        );

        \Brain\Monkey\Functions\when( 'apply_filters' )->alias(
            static function ( string $hook, mixed $value ): mixed {
                if ( 'dt_crm_sync_connectors' === $hook ) {
                    return [ 'respond_io' => 'Disciple_Tools_CRM_Sync_Connector_Respond_IO' ];
                }
                return $value;
            }
        );

        \Brain\Monkey\Functions\when( 'sanitize_key' )->returnArg();

        $registry  = new Disciple_Tools_CRM_Sync_Connector_Registry();
        $connector = $registry->get_active_connector();

        $this->assertInstanceOf( Disciple_Tools_CRM_Sync_Connector_Respond_IO::class, $connector );

        // Retrieve credentials from the connector to confirm the token was decrypted.
        $reflection = new \ReflectionClass( $connector );
        $prop       = $reflection->getProperty( 'credentials' );
        $prop->setAccessible( true );
        $credentials = $prop->getValue( $connector );

        $this->assertSame(
            $plaintext_token,
            $credentials['api_token'],
            'The registry must decrypt credential values before passing them to the connector.'
        );
    }

// decrypt_value: invalid base64 characters

    public function test_decrypt_invalid_base64(): void {
        // Inject real decrypt logic — same pattern used by other EncryptionTest methods.
        Disciple_Tools_CRM_Sync::$test_decrypt_fn = $this->make_decrypt_closure();

        \Brain\Monkey\Functions\when( 'get_option' )->justReturn( self::TEST_KEY_B64 );

        // '!!' and '$$' are not valid base64 characters; base64_decode($value, true)
        // returns false in strict mode, exercising the `false === $raw` guard.
        $result = Disciple_Tools_CRM_Sync::decrypt_value( 'not$$valid!!base64' );
        $this->assertFalse( $result );
    }
}
