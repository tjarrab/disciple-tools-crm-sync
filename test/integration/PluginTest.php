<?php

class PluginTest extends TestCase
{
    /**
     * Verify the plugin appears in the active plugins list after activation.
     *
     * @return void
     */
    public function test_plugin_installed() {
        activate_plugin( 'disciple-tools-crm-sync/disciple-tools-crm-sync.php' );

        $this->assertContains(
            'disciple-tools-crm-sync/disciple-tools-crm-sync.php',
            get_option( 'active_plugins' )
        );
    }

    /**
     * Confirm the main plugin class was loaded by the bootstrap.
     *
     * @return void
     */
    public function test_plugin_class_exists() {
        $this->assertTrue( class_exists( 'Disciple_Tools_CRM_Sync' ) );
    }

    /**
     * Confirm the required path and URL constants are set after bootstrap.
     *
     * @return void
     */
    public function test_plugin_constants_defined() {
        $this->assertTrue( defined( 'DT_CRM_SYNC_PATH' ) );
        $this->assertTrue( defined( 'DT_CRM_SYNC_URL' ) );
    }
}
