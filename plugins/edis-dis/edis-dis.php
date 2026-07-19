<?php
/**
 * Plugin Name:  EDIS Digital Immune System
 * Description:  Reads health posture from the DIS collector and adjusts ad rendering and admin alerts. Requires edis-core.
 * Version:      0.1.0
 * Requires PHP: 8.1
 * Author:       EINHORN_INDUSTRIAL
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ── Constants ────────────────────────────────────────────────────────────────

define( 'EDIS_DIS_CACHE_TTL', 10 ); // seconds; short TTL so posture stays fresh

/**
 * Returns the DIS collector URL lazily so option changes take effect immediately.
 * Using a constant defined via get_option() at plugin include time would freeze
 * the value before plugins_loaded and break option changes without a restart.
 */
function edis_dis_collector_url(): string {
    return get_option( 'edis_dis_collector_url', 'http://127.0.0.1:9099' );
}

// ── Boot ─────────────────────────────────────────────────────────────────────

add_action( 'plugins_loaded', 'edis_dis_boot' );

function edis_dis_boot(): void {
    // Admin notice if in attack/degraded state.
    if ( is_admin() ) {
        add_action( 'admin_notices', 'edis_dis_admin_notice' );
        add_action( 'admin_menu', 'edis_dis_admin_menu' );
    }
    add_action( 'rest_api_init', 'edis_dis_register_rest_routes' );
    add_action( 'wp_enqueue_scripts', 'edis_dis_maybe_enqueue_pow' );
}

/**
 * Only enqueue the PoW solver JS on requests where a challenge slot might
 * actually render — cheap check (mirrors the ad mode lookup the shortcode
 * itself does, both hit the same 10s transient so this doesn't double the
 * collector calls in practice).
 */
function edis_dis_maybe_enqueue_pow(): void {
    if ( edis_dis_ad_mode() !== 'pow_captcha' ) {
        return;
    }
    wp_enqueue_script(
        'edis-dis-pow',
        plugins_url( 'assets/pow.js', __FILE__ ),
        [],
        '0.1.0',
        true
    );
    wp_localize_script( 'edis-dis-pow', 'edisDisPow', [
        'restUrl' => esc_url_raw( rest_url( 'edis/v1/' ) ),
        'nonce'   => wp_create_nonce( 'wp_rest' ),
    ] );
}

// ── Health State Query ────────────────────────────────────────────────────────

/**
 * Returns the current health state string from the DIS collector.
 * Falls back to 'healthy' if the collector is unreachable (fail open always).
 */
function edis_dis_health_state(): string {
    $cached = get_transient( 'edis_dis_health' );
    if ( $cached !== false ) {
        return (string) $cached;
    }
    $state = edis_dis_fetch_state();
    set_transient( 'edis_dis_health', $state, EDIS_DIS_CACHE_TTL );
    return $state;
}

/**
 * Returns the current ad mode string: svg | text | pow_captcha | none
 */
function edis_dis_ad_mode(): string {
    $cached = get_transient( 'edis_dis_ad_mode' );
    if ( $cached !== false ) {
        return (string) $cached;
    }
    $mode = edis_dis_fetch_ad_mode();
    set_transient( 'edis_dis_ad_mode', $mode, EDIS_DIS_CACHE_TTL );
    return $mode;
}

function edis_dis_fetch_state(): string {
    $resp = wp_remote_get( edis_dis_collector_url() . '/dis/health', [
        'timeout'   => 1,
        'sslverify' => false,
    ]);
    if ( is_wp_error( $resp ) ) {
        return 'healthy'; // fail open
    }
    $body = wp_remote_retrieve_body( $resp );
    $data = json_decode( $body, true );
    if ( ! isset( $data['state'] ) ) {
        return 'healthy';
    }
    return sanitize_key( $data['state'] );
}

function edis_dis_fetch_ad_mode(): string {
    $resp = wp_remote_get( edis_dis_collector_url() . '/dis/admode', [
        'timeout'   => 1,
        'sslverify' => false,
    ]);
    if ( is_wp_error( $resp ) ) {
        return 'svg'; // fail open: full ads when collector unreachable
    }
    $mode = trim( wp_remote_retrieve_body( $resp ) );
    if ( ! in_array( $mode, [ 'svg', 'text', 'pow_captcha', 'none' ], true ) ) {
        return 'svg';
    }
    return $mode;
}

// ── PoW Gate REST Proxy ────────────────────────────────────────────────────────
//
// The DIS collector only listens on 127.0.0.1 (never exposed to browsers
// directly — same reasoning as the health/admode fetches above), so the
// PoW challenge/verify round trip has to go through WordPress's own REST
// API as a thin proxy. Both routes require a valid WP nonce (any visitor
// gets one automatically — this isn't an auth check, just standard
// same-origin CSRF hygiene for a REST endpoint).

function edis_dis_register_rest_routes(): void {
    register_rest_route( 'edis/v1', '/dis-pow-challenge', [
        'methods'             => 'GET',
        'callback'            => 'edis_dis_rest_pow_challenge',
        'permission_callback' => '__return_true',
    ] );
    register_rest_route( 'edis/v1', '/dis-pow-verify', [
        'methods'             => 'POST',
        'callback'            => 'edis_dis_rest_pow_verify',
        'permission_callback' => '__return_true',
    ] );
}

function edis_dis_rest_pow_challenge( \WP_REST_Request $request ) {
    $resp = wp_remote_get( edis_dis_collector_url() . '/dis/pow/challenge', [
        'timeout'   => 2,
        'sslverify' => false,
    ]);
    if ( is_wp_error( $resp ) ) {
        return new \WP_Error( 'dis_unreachable', 'DIS collector unreachable', [ 'status' => 502 ] );
    }
    $data = json_decode( wp_remote_retrieve_body( $resp ), true );
    if ( ! is_array( $data ) || ! isset( $data['token'] ) ) {
        return new \WP_Error( 'dis_bad_response', 'DIS collector returned an invalid challenge', [ 'status' => 502 ] );
    }
    return new \WP_REST_Response( $data, 200 );
}

function edis_dis_rest_pow_verify( \WP_REST_Request $request ) {
    $token = (string) $request->get_param( 'token' );
    $nonce = (string) $request->get_param( 'nonce' );
    $slot  = sanitize_text_field( (string) $request->get_param( 'slot' ) );
    $text  = sanitize_text_field( (string) $request->get_param( 'text' ) );
    $href  = esc_url_raw( (string) $request->get_param( 'href' ) );

    if ( $token === '' || $nonce === '' ) {
        return new \WP_REST_Response( [ 'ok' => false ], 400 );
    }

    $resp = wp_remote_post( edis_dis_collector_url() . '/dis/pow/verify', [
        'timeout'   => 2,
        'sslverify' => false,
        'headers'   => [ 'Content-Type' => 'application/json' ],
        'body'      => wp_json_encode( [ 'token' => $token, 'nonce' => $nonce ] ),
    ]);
    if ( is_wp_error( $resp ) ) {
        // Fail closed here specifically: unlike ad-mode reads, an
        // unreachable collector during a PoW check must not grant the ad —
        // the whole point of this gate is that attack-mode traffic has to
        // pay a verifiable cost, not just claim to have paid it.
        return new \WP_REST_Response( [ 'ok' => false ], 502 );
    }
    $data = json_decode( wp_remote_retrieve_body( $resp ), true );
    if ( ! is_array( $data ) || empty( $data['ok'] ) ) {
        return new \WP_REST_Response( [ 'ok' => false ], 403 );
    }

    return new \WP_REST_Response( [
        'ok'   => true,
        'html' => edis_dis_text_ad( [ 'slot' => $slot, 'text' => $text ?: 'EINHORN_INDUSTRIAL — Financial intelligence built different.', 'href' => $href ?: home_url( '/ask' ) ] ),
    ], 200 );
}

// ── Ad Mode Shortcode ─────────────────────────────────────────────────────────

/**
 * [edis_dis_ad] — renders an ad slot adjusted to the current health state.
 * Attributes: slot (string), src (URL for SVG/image ad).
 *
 * Usage: [edis_dis_ad slot="sidebar" src="https://ads.example.com/728x90.svg"]
 */
add_shortcode( 'edis_dis_ad', 'edis_dis_ad_shortcode' );

function edis_dis_ad_shortcode( array $atts ): string {
    $atts = shortcode_atts( [
        'slot' => 'default',
        'src'  => '',
        'text' => 'EINHORN_INDUSTRIAL — Financial intelligence built different.',
        'href' => home_url( '/ask' ),
    ], $atts );

    $mode = edis_dis_ad_mode();

    switch ( $mode ) {
        case 'svg':
            if ( $atts['src'] ) {
                return sprintf(
                    '<div class="edis-ad edis-ad--svg" data-slot="%s"><a href="%s" rel="nofollow"><img src="%s" loading="lazy" alt="Advertisement" /></a></div>',
                    esc_attr( $atts['slot'] ),
                    esc_url( $atts['href'] ),
                    esc_url( $atts['src'] )
                );
            }
            return edis_dis_text_ad( $atts );

        case 'text':
            return edis_dis_text_ad( $atts );

        case 'pow_captcha':
            // Render a challenge gate — user must solve a lightweight PoW before seeing the ad.
            return edis_dis_challenge_ad( $atts );

        case 'none':
        default:
            return ''; // shed load — no ad rendered at all
    }
}

function edis_dis_text_ad( array $atts ): string {
    return sprintf(
        '<div class="edis-ad edis-ad--text" data-slot="%s"><a href="%s" rel="nofollow">%s</a></div>',
        esc_attr( $atts['slot'] ),
        esc_url( $atts['href'] ),
        esc_html( $atts['text'] )
    );
}

function edis_dis_challenge_ad( array $atts ): string {
    // Real hashcash-style PoW gate (internal/dis/pow.go + assets/pow.js):
    // the client fetches a challenge from the DIS collector (proxied through
    // the REST routes above), solves it in the browser, and posts the
    // solution back for verification. Only on a verified solve does the
    // real ad HTML get served — this div is a placeholder the JS replaces
    // or removes, never the ad itself.
    return sprintf(
        '<div class="edis-ad edis-ad--challenge" data-slot="%s" data-text="%s" data-href="%s">'
        . '<p class="edis-dis-challenge-msg">Verifying…</p>'
        . '</div>',
        esc_attr( $atts['slot'] ),
        esc_attr( $atts['text'] ),
        esc_attr( $atts['href'] )
    );
}

// ── Admin Notice ──────────────────────────────────────────────────────────────

function edis_dis_admin_notice(): void {
    $state = edis_dis_health_state();
    if ( $state === 'healthy' ) return;

    $messages = [
        'elevated'   => [ 'warning', 'DIS: Elevated threat signal — text-only ads active.' ],
        'attack'     => [ 'error',   'DIS: Active attack pattern detected — PoW/CAPTCHA gate active. Check posture panel.' ],
        'degraded'   => [ 'error',   'DIS: System degraded — all ad slots suppressed. Check resource utilisation.' ],
    ];

    if ( ! isset( $messages[ $state ] ) ) return;
    [$class, $msg] = $messages[ $state ];

    printf(
        '<div class="notice notice-%s"><p><strong>EDIS DIS:</strong> %s</p></div>',
        esc_attr( $class ),
        esc_html( $msg )
    );
}

// ── Admin Page ────────────────────────────────────────────────────────────────

function edis_dis_admin_menu(): void {
    add_submenu_page(
        'options-general.php',
        'EDIS Digital Immune System',
        'EDIS DIS',
        'manage_options',
        'edis-dis',
        'edis_dis_admin_page'
    );
}

function edis_dis_admin_page(): void {
    if ( ! current_user_can( 'manage_options' ) ) return;

    // Handle settings save
    if ( isset( $_POST['edis_dis_save'] ) && check_admin_referer( 'edis_dis_settings' ) ) {
        update_option( 'edis_dis_collector_url', sanitize_url( $_POST['edis_dis_collector_url'] ?? '' ) );
        update_option( 'edis_dis_admin_token', sanitize_text_field( $_POST['edis_dis_admin_token'] ?? '' ) );
        echo '<div class="updated"><p>Settings saved.</p></div>';
    }

    // Handle manual ForceState override
    if ( isset( $_POST['edis_dis_force'] ) && check_admin_referer( 'edis_dis_force' ) ) {
        $state = sanitize_key( $_POST['edis_dis_force_state'] ?? '' );
        $token = get_option( 'edis_dis_admin_token', '' );
        if ( $token && in_array( $state, [ 'healthy', 'elevated', 'attack', 'degraded' ], true ) ) {
            $result = wp_remote_post(
                edis_dis_collector_url() . '/dis/force?state=' . urlencode( $state ),
                [ 'timeout' => 2, 'headers' => [ 'Authorization' => 'Bearer ' . $token ] ]
            );
            if ( is_wp_error( $result ) ) {
                echo '<div class="notice notice-error"><p>Force failed: ' . esc_html( $result->get_error_message() ) . '</p></div>';
            } else {
                echo '<div class="updated"><p>Posture forced to <strong>' . esc_html( $state ) . '</strong>.</p></div>';
            }
        } elseif ( ! $token ) {
            echo '<div class="notice notice-warning"><p>Set an admin token in Settings to enable manual override.</p></div>';
        }
    }

    // Fetch live posture from collector
    $resp = wp_remote_get( edis_dis_collector_url() . '/dis/posture', [ 'timeout' => 2 ] );
    $posture = null;
    if ( ! is_wp_error( $resp ) ) {
        $posture = json_decode( wp_remote_retrieve_body( $resp ), true );
    }

    ?>
    <div class="wrap">
        <h1>EDIS Digital Immune System</h1>

        <?php if ( $posture ): ?>
        <table class="widefat" style="max-width:600px;margin-bottom:2em;">
            <tbody>
                <tr><th>Health State</th><td><?php echo esc_html( $posture['state'] ?? 'unknown' ); ?></td></tr>
                <tr><th>Ad Mode</th><td><?php echo esc_html( $posture['ad_mode'] ?? 'unknown' ); ?></td></tr>
                <tr><th>Ad Mode Desc</th><td><?php echo esc_html( $posture['ad_mode_description'] ?? '' ); ?></td></tr>
                <tr><th>Hostile Ratio</th><td><?php echo esc_html( number_format( (float) ( $posture['hostile_ratio'] ?? 0.0 ), 4 ) ); ?></td></tr>
            </tbody>
        </table>
        <?php else: ?>
        <div class="notice notice-warning"><p>DIS collector not reachable at <code><?php echo esc_html( edis_dis_collector_url() ); ?></code>. Install and start the <code>dis</code> binary.</p></div>
        <?php endif; ?>

        <form method="post">
            <?php wp_nonce_field( 'edis_dis_settings' ); ?>
            <table class="form-table">
                <tr>
                    <th>Collector URL</th>
                    <td>
                        <input type="url" name="edis_dis_collector_url"
                            value="<?php echo esc_attr( get_option( 'edis_dis_collector_url', 'http://127.0.0.1:9099' ) ); ?>"
                            class="regular-text" />
                        <p class="description">The address where <code>dis</code> daemon is listening. Default: <code>http://127.0.0.1:9099</code></p>
                    </td>
                </tr>
                <tr>
                    <th>Admin Token</th>
                    <td>
                        <input type="password" name="edis_dis_admin_token"
                            value="<?php echo esc_attr( get_option( 'edis_dis_admin_token', '' ) ); ?>"
                            class="regular-text" autocomplete="new-password" />
                        <p class="description">Bearer token passed to <code>/dis/force</code>. Must match the <code>--admin-token</code> flag on the <code>dis</code> binary. Required for manual override.</p>
                    </td>
                </tr>
            </table>
            <p class="submit"><input type="submit" name="edis_dis_save" class="button-primary" value="Save Settings" /></p>
        </form>

        <h2>Manual Override</h2>
        <?php $has_token = (bool) get_option( 'edis_dis_admin_token', '' ); ?>
        <?php if ( ! $has_token ): ?>
        <p class="description">Set an admin token above to enable manual posture override.</p>
        <?php else: ?>
        <form method="post">
            <?php wp_nonce_field( 'edis_dis_force' ); ?>
            <select name="edis_dis_force_state">
                <option value="healthy">Healthy</option>
                <option value="elevated">Elevated</option>
                <option value="attack">Attack</option>
                <option value="degraded">Degraded</option>
            </select>
            <input type="submit" name="edis_dis_force" class="button button-secondary" value="Force Posture" />
            <p class="description">Immediately override the DIS collector state. Use during incidents when automated detection is insufficient.</p>
        </form>
        <?php endif; ?>

        <h2>Shortcode</h2>
        <pre>[edis_dis_ad slot="sidebar" src="https://ads.example.com/banner.svg" href="https://example.com"]</pre>
        <p>The ad output adapts automatically to the current health state: SVG → text → PoW/CAPTCHA → nothing.</p>

        <h2>Health States</h2>
        <table class="widefat" style="max-width:600px;">
            <thead><tr><th>State</th><th>Ad Mode</th><th>Cause</th></tr></thead>
            <tbody>
                <tr><td>healthy</td><td>svg</td><td>Normal operation</td></tr>
                <tr><td>elevated</td><td>text</td><td>&gt;20% hostile sessions in 30s window</td></tr>
                <tr><td>attack</td><td>pow_captcha</td><td>&gt;50% hostile sessions in 30s window</td></tr>
                <tr><td>degraded</td><td>none</td><td>CPU/memory pressure or sustained attack</td></tr>
            </tbody>
        </table>
    </div>
    <?php
}
