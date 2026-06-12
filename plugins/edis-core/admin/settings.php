<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'admin_init', 'edis_core_settings_init' );
function edis_core_settings_init() {
    register_setting( 'edis_settings_group', 'edis_signalapi_url', [
        'sanitize_callback' => 'esc_url_raw',
    ] );
    register_setting( 'edis_settings_group', 'edis_emily_url', [
        'sanitize_callback' => 'esc_url_raw',
    ] );
    register_setting( 'edis_settings_group', 'edis_cache_ttl', [
        'sanitize_callback' => 'absint',
    ] );
    add_settings_section( 'edis_main', 'API Connections', '__return_false', 'edis-settings' );
    add_settings_field( 'edis_signalapi_url', 'FatBaby signalapi URL', 'edis_field_signalapi_url', 'edis-settings', 'edis_main' );
    add_settings_field( 'edis_emily_url',     'Emily Prime URL',       'edis_field_emily_url',     'edis-settings', 'edis_main' );
    add_settings_field( 'edis_cache_ttl',     'Cache TTL (seconds)',   'edis_field_cache_ttl',     'edis-settings', 'edis_main' );
}

function edis_core_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    if ( isset( $_GET['settings-updated'] ) ) {
        add_settings_error( 'edis_messages', 'edis_message', 'Settings saved.', 'updated' );
    }
    settings_errors( 'edis_messages' );
    ?>
    <div class="wrap">
        <h1>EDIS Settings</h1>
        <form action="options.php" method="post">
            <?php
            settings_fields( 'edis_settings_group' );
            do_settings_sections( 'edis-settings' );
            submit_button( 'Save Settings' );
            ?>
        </form>
        <hr>
        <h2>Connection Test</h2>
        <?php edis_core_connection_test(); ?>
    </div>
    <?php
}

function edis_field_signalapi_url() {
    $val = get_option( 'edis_signalapi_url', '' );
    printf( '<input type="url" name="edis_signalapi_url" value="%s" class="regular-text" placeholder="http://localhost:9091">', esc_attr( $val ) );
    echo '<p class="description">FatBaby signalapi base URL. No trailing slash.</p>';
}

function edis_field_emily_url() {
    $val = get_option( 'edis_emily_url', '' );
    printf( '<input type="url" name="edis_emily_url" value="%s" class="regular-text" placeholder="http://localhost:8086">', esc_attr( $val ) );
    echo '<p class="description">Emily Prime base URL (:8086). No trailing slash.</p>';
}

function edis_field_cache_ttl() {
    $val = get_option( 'edis_cache_ttl', 60 );
    printf( '<input type="number" name="edis_cache_ttl" value="%d" min="0" max="3600" class="small-text">', (int) $val );
    echo '<p class="description">Seconds to cache signalapi responses. 0 disables caching.</p>';
}

function edis_core_connection_test() {
    $url = get_option( 'edis_signalapi_url', '' );
    if ( empty( $url ) ) {
        echo '<p>signalapi URL not set.</p>';
        return;
    }
    $response = wp_remote_get( rtrim( $url, '/' ) . '/healthz', [ 'timeout' => 5 ] );
    if ( is_wp_error( $response ) ) {
        echo '<p style="color:red">&#x2717; signalapi unreachable: ' . esc_html( $response->get_error_message() ) . '</p>';
    } else {
        $code = wp_remote_retrieve_response_code( $response );
        if ( $code === 200 ) {
            echo '<p style="color:green">&#x2713; signalapi OK (HTTP 200)</p>';
        } else {
            echo '<p style="color:orange">&#x26A0; signalapi returned HTTP ' . esc_html( $code ) . '</p>';
        }
    }
}
