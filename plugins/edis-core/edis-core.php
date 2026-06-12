<?php
/**
 * Plugin Name: EDIS Core
 * Plugin URI:  https://github.com/emilyspringerton/EDIS
 * Description: API client, settings, and transient cache layer for the EDIS plugin suite.
 *              All signalapi calls go through this plugin.
 * Version:     0.1.0
 * Author:      EINHORN INDUSTRIAL
 * License:     MIT
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'EDIS_CORE_VERSION', '0.1.0' );
define( 'EDIS_CORE_DIR', plugin_dir_path( __FILE__ ) );

// Pull connection constants from wp-config.php (or environment via WP helper).
if ( ! defined( 'EDIS_SIGNALAPI_URL' ) ) {
    define( 'EDIS_SIGNALAPI_URL', get_option( 'edis_signalapi_url', '' ) );
}
if ( ! defined( 'EDIS_EMILY_URL' ) ) {
    define( 'EDIS_EMILY_URL', get_option( 'edis_emily_url', '' ) );
}
if ( ! defined( 'EDIS_CACHE_TTL' ) ) {
    define( 'EDIS_CACHE_TTL', (int) get_option( 'edis_cache_ttl', 60 ) );
}

require_once EDIS_CORE_DIR . 'includes/class-api-client.php';
require_once EDIS_CORE_DIR . 'includes/class-cache.php';
require_once EDIS_CORE_DIR . 'admin/settings.php';

register_activation_hook( __FILE__, 'edis_core_activate' );
function edis_core_activate() {
    add_option( 'edis_signalapi_url', '' );
    add_option( 'edis_emily_url', '' );
    add_option( 'edis_cache_ttl', 60 );
}

add_action( 'admin_menu', 'edis_core_register_menu' );
function edis_core_register_menu() {
    add_options_page(
        'EDIS Settings',
        'EDIS',
        'manage_options',
        'edis-settings',
        'edis_core_settings_page'
    );
}
