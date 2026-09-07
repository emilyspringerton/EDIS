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

// ── Ad Inventory (CP-ADMON-1) ─────────────────────────────────────────────────
//
// Founder real-time, 2026-09-07: internal-ecosystem-first advertising, mixed
// with a real "would you like to advertise here?" house CTA in the SAME
// rotation ("mixed with other stuff as we have stuff to advertise") --
// not a fallback-only-when-empty message, one more real entry in the pool.
// Vetted external sponsors (e.g. "we would take a Redbull sponsorship") are
// a real, supported `kind` here too -- we don't share any of our own user
// data with them, only serve a static creative + outbound link, and default
// external links to `rel="noreferrer"` so the click doesn't even leak which
// page it came from (stronger than the internet's own default of a plain
// `Referer` header leak, which we've acknowledged plainly is otherwise
// unavoidable for a normal outbound link).
//
// This is real, current inventory -- GFD is live and shipped
// (dis-gfd-subscription is this exact repo's own real cross-product
// precedent). No placeholder ad for a product that isn't live yet
// (WOTAN/BrawlPit/Emily+/IDUNA_PRO): that's a promise we can't keep,
// not a real ad. Add real entries here (or via the
// `edis_dis_ad_pool` filter, e.g. from another plugin) as products ship or
// sponsors are vetted -- never hardcode a specific ad's content into a
// shortcode call in a template; that's exactly the per-placement drift this
// pool replaces.
function edis_dis_default_ad_pool(): array {
    return [
        [
            'kind' => 'house',
            'text' => 'GoblinFoxDragon — the long-running RPG. Play free.',
            'href' => 'https://goblinfoxdragon.com',
            'src'  => '',
        ],
        [
            'kind' => 'meta',
            'text' => 'Advertise here — reach FatBaby\'s markets-desk readers.',
            'href' => edis_dis_advertise_contact(),
            'src'  => '',
        ],
    ];
}

/**
 * The real destination for "would you like to advertise?" -- a plain
 * mailto: by default (no contact-form backend exists for FatBaby yet, and a
 * dead link would be worse than an honest mailto:), admin-editable on the
 * DIS settings page since the real inbox to use is an operational decision,
 * not a code one.
 */
function edis_dis_advertise_contact(): string {
    $configured = get_option( 'edis_dis_advertise_contact', '' );
    if ( $configured ) {
        return $configured;
    }
    $host = wp_parse_url( home_url(), PHP_URL_HOST ) ?: 'fatbaby.news';
    return 'mailto:ads@' . $host;
}

/**
 * Returns the real ad pool for a slot -- `edis_dis_default_ad_pool()`
 * filtered by `edis_dis_ad_pool` (pass `$slot` to target one slot
 * specifically; the default filter callback ignores it and returns the same
 * pool everywhere, which is the correct behavior until slot-specific
 * inventory is actually needed).
 */
function edis_dis_ad_pool( string $slot ): array {
    return apply_filters( 'edis_dis_ad_pool', edis_dis_default_ad_pool(), $slot );
}

/**
 * Picks one real entry from the slot's ad pool at random. Empty array (not
 * a guess, not a placeholder ad) if the pool is empty -- callers must
 * handle that by rendering nothing, same "no ad slots suppressed" spirit as
 * the `none` health-driven ad mode below.
 */
function edis_dis_pick_ad( string $slot ): array {
    $pool = edis_dis_ad_pool( $slot );
    if ( empty( $pool ) ) {
        return [];
    }
    return $pool[ array_rand( $pool ) ];
}

// ── Ad Mode Shortcode ─────────────────────────────────────────────────────────

/**
 * [edis_dis_ad] — renders an ad slot adjusted to the current health state.
 * Attributes: slot (string). src/text/href are optional EXPLICIT overrides
 * for a one-off placement (back-compat with any hand-authored ad); when
 * omitted (the normal case), the real creative is picked at random from
 * edis_dis_ad_pool() for that slot -- house ads, the "advertise with us"
 * CTA, and any vetted sponsor, all mixed in the same rotation.
 *
 * Usage: [edis_dis_ad slot="sidebar"]
 *        [edis_dis_ad slot="sidebar" src="https://ads.example.com/728x90.svg" href="https://example.com" text="..."]  (explicit override)
 */
add_shortcode( 'edis_dis_ad', 'edis_dis_ad_shortcode' );

function edis_dis_ad_shortcode( array $atts ): string {
    $atts = shortcode_atts( [
        'slot' => 'default',
        'src'  => '',
        'text' => '',
        'href' => '',
        'kind' => '',
    ], $atts );

    // Explicit override: any of src/text/href passed means "render exactly
    // this," the original behavior, untouched.
    if ( $atts['text'] || $atts['href'] || $atts['src'] ) {
        if ( ! $atts['text'] ) $atts['text'] = 'EINHORN_INDUSTRIAL — Financial intelligence built different.';
        if ( ! $atts['href'] ) $atts['href'] = home_url( '/ask' );
    } else {
        $picked = edis_dis_pick_ad( $atts['slot'] );
        if ( empty( $picked ) ) {
            return ''; // real, empty pool -- render nothing, don't guess.
        }
        $atts = array_merge( $atts, $picked );
    }

    $mode = edis_dis_ad_mode();

    switch ( $mode ) {
        case 'svg':
            if ( $atts['src'] ) {
                return sprintf(
                    '<div class="edis-ad edis-ad--svg" data-slot="%s"><a href="%s" rel="%s"><img src="%s" loading="lazy" alt="Advertisement" /></a></div>',
                    esc_attr( $atts['slot'] ),
                    esc_url( $atts['href'] ),
                    esc_attr( edis_dis_ad_rel( $atts['kind'] ) ),
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

/**
 * rel attribute per ad kind -- CP-ADMON-1's own real privacy stance:
 * "sponsor" (a vetted external advertiser) gets noreferrer+noopener on top
 * of the existing nofollow, so the click doesn't even leak which FatBaby
 * page it came from. "house"/"meta" (our own products, our own contact
 * address) stay nofollow-only -- there's no privacy boundary being crossed
 * pointing at our own ecosystem or our own inbox.
 */
function edis_dis_ad_rel( string $kind ): string {
    return $kind === 'sponsor' ? 'nofollow noreferrer noopener' : 'nofollow';
}

function edis_dis_text_ad( array $atts ): string {
    return sprintf(
        '<div class="edis-ad edis-ad--text" data-slot="%s"><a href="%s" rel="%s">%s</a></div>',
        esc_attr( $atts['slot'] ),
        esc_url( $atts['href'] ),
        esc_attr( edis_dis_ad_rel( $atts['kind'] ?? '' ) ),
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
        update_option( 'edis_dis_advertise_contact', sanitize_text_field( $_POST['edis_dis_advertise_contact'] ?? '' ) );
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
                <tr>
                    <th>Advertise Contact</th>
                    <td>
                        <input type="text" name="edis_dis_advertise_contact"
                            value="<?php echo esc_attr( get_option( 'edis_dis_advertise_contact', '' ) ); ?>"
                            class="regular-text" placeholder="mailto:ads@yourdomain.com" />
                        <p class="description">Where the "Advertise here" house ad links -- a real, checked inbox, not a placeholder. Defaults to <code>mailto:ads@&lt;this site's own domain&gt;</code> if left blank.</p>
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
        <pre>[edis_dis_ad slot="sidebar"]</pre>
        <p>Renders one real entry (house ad, "advertise here" CTA, or a vetted sponsor) picked at random from the ad pool, adapted automatically to the current health state: SVG → text → PoW/CAPTCHA → nothing. Add a real sponsor via the <code>edis_dis_ad_pool</code> filter -- see <code>internal/gauntlet</code>'s own sibling doc, <code>docs/AD_MONETIZATION_NORTHSTAR.md</code>, for the full policy.</p>
        <pre>[edis_dis_ad slot="sidebar" src="..." href="..." text="..."]</pre>
        <p>Explicit override for a one-off placement, unchanged from before -- bypasses the pool entirely.</p>

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
