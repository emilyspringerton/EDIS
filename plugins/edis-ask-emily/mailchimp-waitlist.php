<?php
/**
 * Mailchimp waitlist integration (S23-05).
 *
 * Replaces the wp_options waitlist storage with Mailchimp list API v3.
 * When configured, POST /wp-json/edis/v1/waitlist adds the contact to the
 * "EDIS Waitlist" audience in Mailchimp (via API). Falls back to wp_options
 * storage if Mailchimp is not configured (graceful degradation).
 *
 * Configuration (wp-config.php or EDIS admin settings):
 *   EDIS_MAILCHIMP_API_KEY    — Mailchimp API key (us1.xxxx format)
 *   EDIS_MAILCHIMP_LIST_ID    — Mailchimp audience/list ID (8-char hex string)
 *   EDIS_MAILCHIMP_SERVER     — Mailchimp data center (e.g. us1, us14; auto-detected from API key)
 *   EDIS_MAILCHIMP_TAG        — Tag applied to new contacts (default: "edis-waitlist")
 *
 * Mailchimp API reference:
 *   POST https://<server>.api.mailchimp.com/3.0/lists/<list_id>/members
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Override the waitlist REST handler registered in edis-ask-emily.php.
 * Uses remove_filter/add_filter on rest_api_init to replace the default handler.
 */
add_action( 'rest_api_init', 'edis_mailchimp_override_waitlist_route', 20 );

function edis_mailchimp_override_waitlist_route(): void {
    // Re-register the waitlist route with higher priority to override the default.
    register_rest_route( 'edis/v1', '/waitlist', [
        'methods'             => 'POST',
        'callback'            => 'edis_mailchimp_waitlist_handler',
        'permission_callback' => '__return_true',
        'override'            => true,
    ] );
}

/**
 * edis_mailchimp_waitlist_handler
 *
 * Handles POST /wp-json/edis/v1/waitlist.
 * Adds email to Mailchimp audience if configured; falls back to wp_options.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function edis_mailchimp_waitlist_handler( WP_REST_Request $request ): WP_REST_Response {
    $body  = $request->get_json_params();
    $email = isset( $body['email'] ) ? sanitize_email( $body['email'] ) : '';
    if ( ! is_email( $email ) ) {
        return new WP_REST_Response( [ 'error' => 'valid email required' ], 400 );
    }

    $api_key = edis_mailchimp_api_key();
    $list_id = edis_mailchimp_list_id();

    if ( ! empty( $api_key ) && ! empty( $list_id ) ) {
        $result = edis_mailchimp_subscribe( $email, $api_key, $list_id );
        if ( is_wp_error( $result ) ) {
            edis_mailchimp_log( 'Mailchimp subscribe failed for ' . $email . ': ' . $result->get_error_message() );
            // Fall through to wp_options backup so signup isn't lost.
            edis_mailchimp_backup_to_options( $email );
        }
    } else {
        // Mailchimp not configured — store locally and log.
        edis_mailchimp_log( 'Mailchimp not configured (EDIS_MAILCHIMP_API_KEY or LIST_ID missing) — storing in wp_options' );
        edis_mailchimp_backup_to_options( $email );
    }

    return new WP_REST_Response( [ 'status' => 'ok', 'message' => "You're on the list!" ], 200 );
}

/**
 * Subscribe an email to the Mailchimp audience.
 * Uses Mailchimp API v3 PUT (upsert) so re-subscribing an existing contact succeeds.
 *
 * @param string $email
 * @param string $api_key
 * @param string $list_id
 * @return true|WP_Error
 */
function edis_mailchimp_subscribe( string $email, string $api_key, string $list_id ) {
    $server = edis_mailchimp_server( $api_key );
    $tag    = edis_mailchimp_tag();

    // MD5 hash of lowercase email is the Mailchimp member identifier.
    $member_hash = md5( strtolower( $email ) );

    $payload = [
        'email_address' => $email,
        'status_if_new' => 'subscribed',   // subscribe new contacts immediately
        'status'        => 'subscribed',   // for existing contacts, leave status unchanged
        'tags'          => [ $tag ],
    ];

    $url = "https://{$server}.api.mailchimp.com/3.0/lists/{$list_id}/members/{$member_hash}";

    $response = wp_remote_request( $url, [
        'method'  => 'PUT',
        'headers' => [
            'Authorization' => 'Basic ' . base64_encode( 'anystring:' . $api_key ),
            'Content-Type'  => 'application/json',
        ],
        'body'    => wp_json_encode( $payload ),
        'timeout' => 10,
    ] );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $code = wp_remote_retrieve_response_code( $response );
    $body = wp_remote_retrieve_body( $response );

    if ( $code >= 400 ) {
        $data = json_decode( $body, true );
        $detail = $data['detail'] ?? $body;
        return new WP_Error( 'mailchimp_error', "Mailchimp API {$code}: {$detail}" );
    }

    return true;
}

/**
 * Backup signup to wp_options (same as the pre-Mailchimp implementation).
 */
function edis_mailchimp_backup_to_options( string $email ): void {
    $list = get_option( 'edis_waitlist', [] );
    if ( ! in_array( $email, $list, true ) ) {
        $list[] = $email;
        update_option( 'edis_waitlist', $list );
    }
    wp_mail( get_option( 'admin_email' ), 'EDIS waitlist signup', $email );
}

// Config helpers.

function edis_mailchimp_api_key(): string {
    return defined( 'EDIS_MAILCHIMP_API_KEY' )
        ? (string) EDIS_MAILCHIMP_API_KEY
        : (string) get_option( 'edis_mailchimp_api_key', '' );
}

function edis_mailchimp_list_id(): string {
    return defined( 'EDIS_MAILCHIMP_LIST_ID' )
        ? (string) EDIS_MAILCHIMP_LIST_ID
        : (string) get_option( 'edis_mailchimp_list_id', '' );
}

function edis_mailchimp_tag(): string {
    return defined( 'EDIS_MAILCHIMP_TAG' )
        ? (string) EDIS_MAILCHIMP_TAG
        : (string) get_option( 'edis_mailchimp_tag', 'edis-waitlist' );
}

/**
 * Auto-detect the Mailchimp data center from the API key suffix (e.g. key-us14 → us14).
 */
function edis_mailchimp_server( string $api_key ): string {
    if ( defined( 'EDIS_MAILCHIMP_SERVER' ) && EDIS_MAILCHIMP_SERVER ) {
        return (string) EDIS_MAILCHIMP_SERVER;
    }
    if ( preg_match( '/-([a-z]+\d+)$/', $api_key, $m ) ) {
        return $m[1];
    }
    return 'us1'; // fallback
}

function edis_mailchimp_log( string $msg ): void {
    if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
        error_log( '[EDIS Mailchimp] ' . $msg );
    }
}

/**
 * Admin settings page additions — adds Mailchimp fields to EDIS settings.
 * Reuses the EDIS Core settings page if it exists, otherwise creates a notice.
 */
add_action( 'admin_init', 'edis_mailchimp_register_settings' );

function edis_mailchimp_register_settings(): void {
    register_setting( 'edis_settings', 'edis_mailchimp_api_key', [ 'sanitize_callback' => 'sanitize_text_field' ] );
    register_setting( 'edis_settings', 'edis_mailchimp_list_id', [ 'sanitize_callback' => 'sanitize_text_field' ] );
    register_setting( 'edis_settings', 'edis_mailchimp_tag',     [ 'sanitize_callback' => 'sanitize_text_field' ] );

    add_settings_section(
        'edis_mailchimp_section',
        'Mailchimp Waitlist Integration',
        '__return_false',
        'edis-settings'
    );

    add_settings_field(
        'edis_mailchimp_api_key',
        'Mailchimp API Key',
        'edis_mailchimp_api_key_field',
        'edis-settings',
        'edis_mailchimp_section'
    );
    add_settings_field(
        'edis_mailchimp_list_id',
        'Audience (List) ID',
        'edis_mailchimp_list_id_field',
        'edis-settings',
        'edis_mailchimp_section'
    );
    add_settings_field(
        'edis_mailchimp_tag',
        'Tag for new subscribers',
        'edis_mailchimp_tag_field',
        'edis-settings',
        'edis_mailchimp_section'
    );
}

function edis_mailchimp_api_key_field(): void {
    $val = esc_attr( get_option( 'edis_mailchimp_api_key', '' ) );
    echo "<input type='password' name='edis_mailchimp_api_key' value='$val' class='regular-text' placeholder='xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx-us14' />";
    echo "<p class='description'>Get from Mailchimp → Account → Extras → API keys. Or set <code>EDIS_MAILCHIMP_API_KEY</code> in wp-config.php (takes precedence).</p>";
}

function edis_mailchimp_list_id_field(): void {
    $val = esc_attr( get_option( 'edis_mailchimp_list_id', '' ) );
    echo "<input type='text' name='edis_mailchimp_list_id' value='$val' class='regular-text' placeholder='a1b2c3d4' />";
    echo "<p class='description'>Mailchimp Audience ID from Audience → Settings → Audience name and defaults. Or set <code>EDIS_MAILCHIMP_LIST_ID</code>.</p>";
}

function edis_mailchimp_tag_field(): void {
    $val = esc_attr( get_option( 'edis_mailchimp_tag', 'edis-waitlist' ) );
    echo "<input type='text' name='edis_mailchimp_tag' value='$val' class='regular-text' />";
    echo "<p class='description'>Tag applied to contacts added via the waitlist form. Default: edis-waitlist.</p>";
}
