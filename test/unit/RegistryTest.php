<?php
/**
 * Unit tests for Disciple_Tools_CRM_Sync_Connector_Registry.
 *
 * Tests connector discovery (via the dt_crm_sync_connectors filter), slug-to-
 * label map building, and active-connector resolution from the plugin settings
 * option — all without a WordPress environment.
 */

use Brain\Monkey\Functions;

class RegistryTest extends BrainMonkeyTestCase {

// make()

    public function test_make_null_unregistered_slug(): void {
        Functions\when( 'apply_filters' )->justReturn( [
            'respond_io' => 'Disciple_Tools_CRM_Sync_Connector_Respond_IO',
        ] );

        $result = Disciple_Tools_CRM_Sync_Connector_Registry::make( 'nonexistent_slug', [] );

        $this->assertNull( $result );
    }

    public function test_make_instance_registered_slug(): void {
        Functions\when( 'apply_filters' )->justReturn( [
            'respond_io' => 'Disciple_Tools_CRM_Sync_Connector_Respond_IO',
        ] );

        $result = Disciple_Tools_CRM_Sync_Connector_Registry::make( 'respond_io', [] );

        $this->assertInstanceOf( Disciple_Tools_CRM_Sync_Abstract_Connector::class, $result );
    }

// get_labels()

    public function test_get_labels_slug_map(): void {
        Functions\when( 'apply_filters' )->justReturn( [
            'respond_io' => 'Disciple_Tools_CRM_Sync_Connector_Respond_IO',
        ] );

        $labels = Disciple_Tools_CRM_Sync_Connector_Registry::get_labels();

        $this->assertArrayHasKey( 'respond_io', $labels );
        $this->assertSame( 'Respond.io', $labels['respond_io'] );
    }

    public function test_get_labels_empty_no_connectors(): void {
        Functions\when( 'apply_filters' )->justReturn( [] );

        $labels = Disciple_Tools_CRM_Sync_Connector_Registry::get_labels();

        $this->assertSame( [], $labels );
    }

// get_active_connector()

    public function test_active_connector_null_missing_settings(): void {
        Functions\when( 'get_option' )->justReturn( false );
        Functions\when( 'sanitize_key' )->returnArg();

        $result = Disciple_Tools_CRM_Sync_Connector_Registry::get_active_connector();

        $this->assertNull( $result );
    }

    public function test_active_connector_null_empty_key(): void {
        Functions\when( 'get_option' )->justReturn( [] ); // no 'active_connector' key
        Functions\when( 'sanitize_key' )->returnArg();

        $result = Disciple_Tools_CRM_Sync_Connector_Registry::get_active_connector();

        $this->assertNull( $result );
    }

    public function test_active_connector_null_unregistered(): void {
        Functions\when( 'get_option' )->justReturn( [ 'active_connector' => 'ghost_crm' ] );
        Functions\when( 'sanitize_key' )->returnArg();
        Functions\when( 'apply_filters' )->justReturn( [
            'respond_io' => 'Disciple_Tools_CRM_Sync_Connector_Respond_IO',
        ] );

        $result = Disciple_Tools_CRM_Sync_Connector_Registry::get_active_connector();

        $this->assertNull( $result );
    }

    public function test_active_connector_plaintext_credentials(): void {
        // decrypt_value() stub (defined in bootstrap) returns false, so the registry
        // falls back to the raw stored value — i.e. treats all values as plaintext.
        $stored_settings = [
            'active_connector' => 'respond_io',
            'connectors'       => [
                'respond_io' => [
                    'api_url'   => 'https://api.respond.io',
                    'api_token' => 'plain_token',
                ],
            ],
        ];
        Functions\when( 'get_option' )->justReturn( $stored_settings );
        Functions\when( 'sanitize_key' )->returnArg();
        Functions\when( 'apply_filters' )->justReturn( [
            'respond_io' => 'Disciple_Tools_CRM_Sync_Connector_Respond_IO',
        ] );

        $connector = Disciple_Tools_CRM_Sync_Connector_Registry::get_active_connector();

        $this->assertInstanceOf( Disciple_Tools_CRM_Sync_Connector_Respond_IO::class, $connector );
    }
}
