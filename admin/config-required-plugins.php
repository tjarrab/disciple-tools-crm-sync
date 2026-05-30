<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Require plugins with the TGM library.
 *
 * This defines the required and suggested plugins to go with this plugin.
 *
 * @see https://github.com/TGMPA/TGM-Plugin-Activation
 * @package Disciple_Tools
 */

add_action( 'tgmpa_register', function () {
    /*
     * Array of plugin arrays. Required keys are name and slug.
     * If the source is NOT from the .org repo, then source is also required.
     *
     * Example of a companion DT plugin:
     *
     * $plugins[] = [
     *     'name'     => 'Disciple.Tools Dashboard',
     *     'slug'     => 'disciple-tools-dashboard',
     *     'source'   => 'https://github.com/DiscipleTools/disciple-tools-dashboard/releases/latest/download/disciple-tools-dashboard.zip',
     *     'required' => false,
     * ];
     */
    $plugins = [];

    $config = [
        'id'           => 'disciple_tools_crm_sync',
        'default_path' => '',
        'menu'         => 'tgmpa-install-plugins',
        'parent_slug'  => 'plugins.php',
        'capability'   => 'manage_options',
        'has_notices'  => true,
        'dismissable'  => true,
        'dismiss_msg'  => 'These are recommended plugins to complement your Disciple.Tools - CRM Sync installation.',
        'is_automatic' => true,
        'message'      => '',
    ];

    tgmpa( $plugins, $config );
} );
