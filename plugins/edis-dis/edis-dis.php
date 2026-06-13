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

define( 'EDIS_DIS_COLLECTOR_URL', get_option( 'edis_dis_collector_url', 'http://127.0.0.1:9099' ) );
define( 'EDIS_DIS_CACHE_TTL', 10 ); // seconds; short TTL so posture stays fresh

// ── Boot ─────────────────────────────────────────────────────────────────────

add_action( 'plugins_loaded', 'edis_dis_boot' );

function edis_dis_boot(): void {
    // Admin notice if in attack/degraded state.
    if ( is_admin() ) {
        add_action( 'admin_notices', 'edis_dis_admin_notice' );
        add_action( 'admin_menu', 'edis_dis_admin_menu' );
    }
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
    $resp = wp_remote_get( EDIS_DIS_COLLECTOR_URL . '/dis/health', [
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
    $resp = wp_remote_get( EDIS_DIS_COLLECTOR_URL . '/dis/admode', [
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
    // PoW stub: a JS challenge that delays bot requests.
    // Real implementation: server-issued nonce + hashcash-style PoW.
    $nonce = wp_create_nonce( 'edis_dis_pow_' . $atts['slot'] );
    return sprintf(
        '<div class="edis-ad edis-ad--challenge" data-slot="%s" data-nonce="%s">'
        . '<p class="edis-dis-challenge-msg">Please wait while we verify your browser…</p>'
        . '</div>',
        esc_attr( $atts['slot'] ),
        esc_attr( $nonce )
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
        echo '<div class="updated"><p>Settings saved.</p></div>';
    }

    // Fetch live posture from collector
    $resp = wp_remote_get( EDIS_DIS_COLLECTOR_URL . '/dis/posture', [ 'timeout' => 2 ] );
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
            </tbody>
        </table>
        <?php else: ?>
        <div class="notice notice-warning"><p>DIS collector not reachable at <code><?php echo esc_html( EDIS_DIS_COLLECTOR_URL ); ?></code>. Install and start the <code>dis</code> binary.</p></div>
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
            </table>
            <p class="submit"><input type="submit" name="edis_dis_save" class="button-primary" value="Save" /></p>
        </form>

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
