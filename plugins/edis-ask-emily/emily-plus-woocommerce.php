<?php
/**
 * Emily+ WooCommerce subscription provisioning.
 *
 * When a WooCommerce order completes for the "Emily+" product, this hook:
 *  1. Reads the buyer's IDUNA user_id from order meta (set at checkout via JS)
 *  2. POSTs to IDUNA /api/v1/subscriptions as the EDIS-WOOCOMMERCE agent
 *  3. IDUNA grants cap.query.full, which is embedded in the buyer's next JWT
 *
 * Configuration (wp-config.php or EDIS admin settings):
 *   EDIS_IDUNA_BASE_URL            — e.g. https://iduna.einhorn.internal
 *   EDIS_WOOCOMMERCE_AGENT_NAME    — EDIS-WOOCOMMERCE (default)
 *   EDIS_WOOCOMMERCE_AGENT_SECRET  — from IDUNA var/agent-secrets.env
 *   EDIS_EMILY_PLUS_PRODUCT_ID     — WooCommerce product ID for "Emily+" (integer)
 *   EDIS_EMILY_PLUS_PLAN_DAYS      — subscription duration in days; 0 = perpetual (default: 365)
 *
 * Triggered by: woocommerce_order_status_completed
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Hook into WooCommerce order completion.
 */
add_action( 'woocommerce_order_status_completed', 'edis_emily_plus_provision_on_order_complete', 10, 1 );

/**
 * edis_emily_plus_provision_on_order_complete
 *
 * Fires when a WooCommerce order transitions to "completed". Checks if any
 * line item is the Emily+ product, then provisions cap.query.full in IDUNA
 * for the buyer's IDUNA user_id.
 *
 * @param int $order_id WooCommerce order ID.
 */
function edis_emily_plus_provision_on_order_complete( int $order_id ): void {
    $emily_plus_product_id = (int) ( defined( 'EDIS_EMILY_PLUS_PRODUCT_ID' )
        ? EDIS_EMILY_PLUS_PRODUCT_ID
        : get_option( 'edis_emily_plus_product_id', 0 ) );

    if ( $emily_plus_product_id <= 0 ) {
        edis_emily_plus_log( "emily+: EDIS_EMILY_PLUS_PRODUCT_ID not configured — skipping order $order_id" );
        return;
    }

    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        return;
    }

    // Check if this order contains the Emily+ product.
    $has_emily_plus = false;
    foreach ( $order->get_items() as $item ) {
        if ( (int) $item->get_product_id() === $emily_plus_product_id ) {
            $has_emily_plus = true;
            break;
        }
    }
    if ( ! $has_emily_plus ) {
        return;
    }

    // Retrieve the IDUNA user_id stored in order meta at checkout.
    // The checkout JS stores this via POST to /wp-json/edis/v1/set-iduna-user.
    $iduna_user_id = $order->get_meta( '_edis_iduna_user_id', true );
    if ( empty( $iduna_user_id ) ) {
        // Fallback: look up by order email if the user is logged in and has a linked IDUNA account.
        $iduna_user_id = edis_emily_plus_lookup_iduna_user_id_by_email( $order->get_billing_email() );
    }

    if ( empty( $iduna_user_id ) ) {
        edis_emily_plus_log( "emily+: no IDUNA user_id for order $order_id — cannot provision subscription" );
        $order->add_order_note( '[Emily+] Could not provision: IDUNA user_id not linked. User must sign in with Google on next visit.' );
        return;
    }

    // Calculate expiry.
    $plan_days = (int) ( defined( 'EDIS_EMILY_PLUS_PLAN_DAYS' )
        ? EDIS_EMILY_PLUS_PLAN_DAYS
        : get_option( 'edis_emily_plus_plan_days', 365 ) );

    $expires_at = '';
    if ( $plan_days > 0 ) {
        $expires_at = gmdate( 'Y-m-d\TH:i:s\Z', time() + $plan_days * DAY_IN_SECONDS );
    }

    // Provision in IDUNA.
    $result = edis_emily_plus_iduna_provision( $iduna_user_id, 'emily_plus', 'active', $expires_at );
    if ( is_wp_error( $result ) ) {
        edis_emily_plus_log( "emily+: IDUNA provision failed for order $order_id: " . $result->get_error_message() );
        $order->add_order_note( '[Emily+] IDUNA provision failed: ' . esc_html( $result->get_error_message() ) );
        return;
    }

    edis_emily_plus_log( "emily+: provisioned cap.query.full for user $iduna_user_id (order $order_id, expires_at=$expires_at)" );
    $order->add_order_note( "[Emily+] Subscription provisioned in IDUNA. user_id=$iduna_user_id expires_at=$expires_at" );
    $order->update_meta_data( '_edis_emily_plus_provisioned', '1' );
    $order->save();
}

/**
 * Provision a subscription in IDUNA via the EDIS-WOOCOMMERCE agent.
 *
 * @param string $user_id    IDUNA user UUID.
 * @param string $plan       Plan name (emily_plus).
 * @param string $status     active | cancelled | expired.
 * @param string $expires_at RFC3339 UTC or empty for perpetual.
 * @return true|WP_Error
 */
function edis_emily_plus_iduna_provision( string $user_id, string $plan, string $status, string $expires_at ) {
    $token = edis_emily_plus_iduna_agent_token();
    if ( is_wp_error( $token ) ) {
        return $token;
    }

    $iduna_base = edis_emily_plus_iduna_base_url();
    $payload = [ 'user_id' => $user_id, 'plan' => $plan, 'status' => $status ];
    if ( $expires_at !== '' ) {
        $payload['expires_at'] = $expires_at;
    }

    $response = wp_remote_post( $iduna_base . '/api/v1/subscriptions', [
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type'  => 'application/json',
        ],
        'body'    => wp_json_encode( $payload ),
        'timeout' => 10,
    ] );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $code = wp_remote_retrieve_response_code( $response );
    if ( $code !== 200 ) {
        $body = wp_remote_retrieve_body( $response );
        return new WP_Error( 'iduna_provision_failed', "IDUNA returned HTTP $code: $body" );
    }

    return true;
}

/**
 * Authenticate as EDIS-WOOCOMMERCE agent and return an IDUNA JWT.
 * Token is cached in a transient for 50 minutes.
 *
 * @return string|WP_Error
 */
function edis_emily_plus_iduna_agent_token() {
    $cached = get_transient( 'edis_emily_plus_agent_token' );
    if ( $cached ) {
        return $cached;
    }

    $agent_name   = defined( 'EDIS_WOOCOMMERCE_AGENT_NAME' )   ? EDIS_WOOCOMMERCE_AGENT_NAME   : 'EDIS-WOOCOMMERCE';
    $agent_secret = defined( 'EDIS_WOOCOMMERCE_AGENT_SECRET' ) ? EDIS_WOOCOMMERCE_AGENT_SECRET  : '';

    if ( empty( $agent_secret ) ) {
        return new WP_Error( 'missing_config', 'EDIS_WOOCOMMERCE_AGENT_SECRET not configured' );
    }

    $iduna_base = edis_emily_plus_iduna_base_url();
    $response = wp_remote_post( $iduna_base . '/api/v1/auth/agent', [
        'headers' => [ 'Content-Type' => 'application/json' ],
        'body'    => wp_json_encode( [ 'agent_name' => $agent_name, 'agent_secret' => $agent_secret ] ),
        'timeout' => 10,
    ] );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $code = wp_remote_retrieve_response_code( $response );
    if ( $code !== 200 ) {
        $body = wp_remote_retrieve_body( $response );
        return new WP_Error( 'iduna_auth_failed', "IDUNA auth returned HTTP $code: $body" );
    }

    $data = json_decode( wp_remote_retrieve_body( $response ), true );
    $token = $data['token'] ?? ( $data['access_token'] ?? '' );
    if ( empty( $token ) ) {
        return new WP_Error( 'iduna_auth_failed', 'IDUNA auth response did not include token' );
    }

    set_transient( 'edis_emily_plus_agent_token', $token, 50 * MINUTE_IN_SECONDS );
    return $token;
}

/**
 * Look up an IDUNA user_id by billing email. Returns empty string if not found.
 * This is a best-effort fallback when order meta is not set.
 */
function edis_emily_plus_lookup_iduna_user_id_by_email( string $email ): string {
    // WordPress user meta: when the user signs in with Google via /api/auth/google,
    // we store their IDUNA user_id in usermeta. Retrieve it here.
    $wp_user = get_user_by( 'email', $email );
    if ( ! $wp_user ) {
        return '';
    }
    return (string) get_user_meta( $wp_user->ID, '_edis_iduna_user_id', true );
}

/**
 * REST endpoint: POST /wp-json/edis/v1/set-iduna-user
 * Called by checkout JS when the user is logged in to store their IDUNA user_id in usermeta.
 * Also stored in order meta when called during checkout.
 */
add_action( 'rest_api_init', function () {
    register_rest_route( 'edis/v1', '/set-iduna-user', [
        'methods'             => 'POST',
        'callback'            => 'edis_emily_plus_set_iduna_user_rest',
        'permission_callback' => '__return_true',
    ] );
} );

function edis_emily_plus_set_iduna_user_rest( WP_REST_Request $request ): WP_REST_Response {
    $iduna_user_id = sanitize_text_field( $request->get_param( 'iduna_user_id' ) );
    if ( empty( $iduna_user_id ) ) {
        return new WP_REST_Response( [ 'error' => 'iduna_user_id required' ], 400 );
    }

    // Store in logged-in user's meta for future lookup.
    if ( is_user_logged_in() ) {
        update_user_meta( get_current_user_id(), '_edis_iduna_user_id', $iduna_user_id );
    }

    // Store in WC session so it can be attached to the next order.
    if ( function_exists( 'WC' ) && WC()->session ) {
        WC()->session->set( 'edis_iduna_user_id', $iduna_user_id );
    }

    return new WP_REST_Response( [ 'ok' => true ], 200 );
}

/**
 * Attach IDUNA user_id to order meta on checkout.
 */
add_action( 'woocommerce_checkout_create_order', function ( $order ) {
    if ( function_exists( 'WC' ) && WC()->session ) {
        $iduna_user_id = WC()->session->get( 'edis_iduna_user_id', '' );
        if ( ! empty( $iduna_user_id ) ) {
            $order->update_meta_data( '_edis_iduna_user_id', $iduna_user_id );
        }
    }
}, 10, 1 );

function edis_emily_plus_iduna_base_url(): string {
    return defined( 'EDIS_IDUNA_BASE_URL' )
        ? rtrim( EDIS_IDUNA_BASE_URL, '/' )
        : rtrim( (string) get_option( 'edis_iduna_base_url', 'http://localhost:8080' ), '/' );
}

function edis_emily_plus_log( string $message ): void {
    if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
        error_log( '[EDIS] ' . $message );
    }
}
