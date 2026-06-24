<?php
/**
 * Plugin Name: DIS GFD Subscription
 * Plugin URI:  https://goblinfoxdragon.com
 * Description: GoblinFoxDragon.com subscription integration. IDUNA JWT validation, tier-gated shortcodes, billing portal redirect, signed client download URL generation.
 * Version:     1.0.0
 * Author:      EINHORN_INDUSTRIAL
 * Text Domain: dis-gfd-subscription
 * License:     MIT
 */

defined('ABSPATH') || exit;

// ── Constants ─────────────────────────────────────────────────────────────────

if (!defined('GFD_SUB_VERSION')) define('GFD_SUB_VERSION', '1.0.0');
if (!defined('GFD_SUB_PLUGIN_FILE')) define('GFD_SUB_PLUGIN_FILE', __FILE__);

// Configuration sourced from wp-config.php:
//   define('GFD_IDUNA_URL',           'https://api.goblinfoxdragon.com/iduna');
//   define('GFD_S3_BUCKET',           'gfd-client-dist');
//   define('GFD_S3_REGION',           'us-east-1');
//   define('GFD_S3_KEY',              '...');
//   define('GFD_S3_SECRET',           '...');
//   define('GFD_CLIENT_KEY_FULL',     'gfd-client-latest.zip');
//   define('GFD_CLIENT_KEY_DEMO',     'gfd-client-demo-latest.zip');
//   define('GFD_STRIPE_PORTAL_URL',   'https://billing.stripe.com/p/...');
//   define('GFD_DOWNLOAD_URL_TTL',    86400);   // 24 hours in seconds

define('GFD_IDUNA_URL',  defined('GFD_IDUNA_URL')  ? GFD_IDUNA_URL  : 'http://localhost:8080');
define('GFD_DOWNLOAD_URL_TTL', defined('GFD_DOWNLOAD_URL_TTL') ? GFD_DOWNLOAD_URL_TTL : 86400);

// ── JWT session validation ─────────────────────────────────────────────────────

/**
 * Validates the gfd_token cookie against IDUNA's /api/v1/subscriptions/me endpoint.
 * Returns an array with keys: tier, features, subscribed, user_id.
 * Returns null on invalid/missing token (no cookie, 401, network error).
 *
 * Results are cached per request in a static array.
 */
function gfd_sub_get_session(): ?array {
    static $cache = null;
    if ($cache !== null) return $cache;

    $token = sanitize_text_field($_COOKIE['gfd_token'] ?? '');
    if (!$token) { $cache = null; return null; }

    $response = wp_remote_get(GFD_IDUNA_URL . '/api/v1/subscriptions/me', [
        'timeout' => 5,
        'headers' => ['Authorization' => 'Bearer ' . $token],
    ]);

    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        $cache = null;
        return null;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (!is_array($body)) { $cache = null; return null; }

    $cache = [
        'tier'        => $body['gfd_tier']   ?? 'free_trial',
        'features'    => $body['features']   ?? ['detroit_apartment','basic_mud','forums'],
        'subscribed'  => (bool)($body['subscribed'] ?? false),
    ];
    return $cache;
}

/**
 * Returns true if the current visitor's tier is at or above $min_tier.
 */
function gfd_sub_meets_tier(string $min_tier): bool {
    static $tier_order = ['free_trial' => 0, 'frequency_monthly' => 1, 'frequency_annual' => 1, 'bloc_annual' => 2];
    $session = gfd_sub_get_session();
    if (!$session) return $min_tier === 'free_trial';
    $user_rank = $tier_order[$session['tier']] ?? 0;
    $req_rank  = $tier_order[$min_tier]        ?? 1;
    return $user_rank >= $req_rank;
}

// ── Shortcodes ────────────────────────────────────────────────────────────────

/**
 * [gfd_tier min="frequency"]…content…[/gfd_tier]
 * Hides content from visitors below the minimum tier.
 * min values: free_trial (default) | frequency | bloc
 */
function gfd_sub_shortcode_tier(array $atts, ?string $content = null): string {
    $atts = shortcode_atts(['min' => 'frequency_monthly'], $atts, 'gfd_tier');
    $min  = sanitize_text_field($atts['min']);

    // Normalise shorthand.
    if ($min === 'frequency') $min = 'frequency_monthly';
    if ($min === 'bloc')      $min = 'bloc_annual';

    if (!gfd_sub_meets_tier($min)) {
        return '<div class="gfd-tier-gate" style="background:var(--gfd-mid,#1c1c1c);border:1px solid var(--gfd-border,#2a2a2a);border-radius:4px;padding:20px 24px;">' .
               '<p style="color:var(--gfd-muted,#666);font-size:0.9rem;margin:0;">' .
               esc_html__('This content requires a higher subscription tier.', 'dis-gfd-subscription') . ' ' .
               '<a href="' . esc_url(home_url('/#plans')) . '">' . esc_html__('Upgrade', 'dis-gfd-subscription') . '</a>.' .
               '</p></div>';
    }
    return do_shortcode($content ?? '');
}
add_shortcode('gfd_tier', 'gfd_sub_shortcode_tier');

/**
 * [gfd_user_tier]
 * Outputs the current visitor's tier name inline.
 */
function gfd_sub_shortcode_user_tier(): string {
    $session = gfd_sub_get_session();
    $tier    = $session['tier'] ?? 'free_trial';
    $names   = [
        'free_trial'         => 'Free Trial',
        'frequency_monthly'  => 'The Frequency',
        'frequency_annual'   => 'The Frequency (Annual)',
        'bloc_annual'        => 'The Bloc',
    ];
    return '<span class="gfd-user-tier">' . esc_html($names[$tier] ?? $tier) . '</span>';
}
add_shortcode('gfd_user_tier', 'gfd_sub_shortcode_user_tier');

// ── AJAX: Login via IDUNA ─────────────────────────────────────────────────────

add_action('wp_ajax_nopriv_gfd_login', 'gfd_sub_ajax_login');
add_action('wp_ajax_gfd_login',        'gfd_sub_ajax_login');

function gfd_sub_ajax_login(): void {
    check_ajax_referer('gfd_nonce', '_wpnonce');

    $email    = sanitize_email($_POST['email']    ?? '');
    $password = sanitize_text_field($_POST['password'] ?? '');

    if (!$email || !$password) {
        wp_send_json_error(['message' => 'Email and password required.'], 400);
    }

    // POST credentials to IDUNA local auth endpoint.
    $response = wp_remote_post(GFD_IDUNA_URL . '/api/v1/auth/local', [
        'timeout'     => 8,
        'headers'     => ['Content-Type' => 'application/json'],
        'body'        => wp_json_encode(['email' => $email, 'password' => $password]),
        'data_format' => 'body',
    ]);

    if (is_wp_error($response)) {
        wp_send_json_error(['message' => 'Authentication service unavailable.'], 503);
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);

    if ($code !== 200 || empty($body['token'])) {
        wp_send_json_error(['message' => $body['error'] ?? 'Invalid credentials.'], 401);
    }

    // Set gfd_token cookie (httponly, secure on production).
    $secure = is_ssl();
    setcookie('gfd_token', $body['token'], [
        'expires'  => time() + 86400 * 7,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    wp_send_json_success(['redirect' => home_url('/account'), 'tier' => $body['tier'] ?? 'free_trial']);
}

// ── AJAX: Signed download URL ─────────────────────────────────────────────────

add_action('wp_ajax_nopriv_gfd_download_url', 'gfd_sub_ajax_download_url');
add_action('wp_ajax_gfd_download_url',        'gfd_sub_ajax_download_url');

function gfd_sub_ajax_download_url(): void {
    check_ajax_referer('gfd_nonce', '_wpnonce');

    $session = gfd_sub_get_session();
    $tier    = $session['tier'] ?? 'free_trial';
    $is_full = in_array($tier, ['frequency_monthly', 'frequency_annual', 'bloc_annual'], true);

    $url = gfd_sub_generate_signed_url($is_full);

    if (!$url) {
        wp_send_json_error(['message' => 'Could not generate download URL.'], 500);
    }

    $payload = ['tier' => $tier];
    if ($is_full) {
        $payload['url'] = $url;
    } else {
        $payload['demo_url'] = $url;
    }
    wp_send_json_success($payload);
}

/**
 * Generates an AWS S3 presigned URL for the game client.
 * Uses bare HMAC-SHA256 signing (no SDK required).
 * Returns null if S3 credentials are not configured.
 */
function gfd_sub_generate_signed_url(bool $full_client): ?string {
    $bucket = defined('GFD_S3_BUCKET') ? GFD_S3_BUCKET : '';
    $region = defined('GFD_S3_REGION') ? GFD_S3_REGION : 'us-east-1';
    $key    = defined('GFD_S3_KEY')    ? GFD_S3_KEY    : '';
    $secret = defined('GFD_S3_SECRET') ? GFD_S3_SECRET : '';

    if (!$bucket || !$key || !$secret) return null;

    $object    = $full_client
        ? (defined('GFD_CLIENT_KEY_FULL') ? GFD_CLIENT_KEY_FULL : 'gfd-client-latest.zip')
        : (defined('GFD_CLIENT_KEY_DEMO') ? GFD_CLIENT_KEY_DEMO : 'gfd-client-demo-latest.zip');
    $ttl       = GFD_DOWNLOAD_URL_TTL;
    $host      = "{$bucket}.s3.{$region}.amazonaws.com";
    $datetime  = gmdate('Ymd\THis\Z');
    $date      = gmdate('Ymd');
    $scope     = "{$date}/{$region}/s3/aws4_request";

    $canonical = implode("\n", [
        'GET',
        '/' . rawurlencode($object),
        implode('&', [
            'X-Amz-Algorithm=AWS4-HMAC-SHA256',
            'X-Amz-Credential=' . rawurlencode("{$key}/{$scope}"),
            'X-Amz-Date=' . $datetime,
            'X-Amz-Expires=' . $ttl,
            'X-Amz-SignedHeaders=host',
        ]),
        "host:{$host}",
        '',
        'host',
        'UNSIGNED-PAYLOAD',
    ]);

    $string_to_sign = implode("\n", [
        'AWS4-HMAC-SHA256',
        $datetime,
        $scope,
        hash('sha256', $canonical),
    ]);

    $signing_key = hash_hmac('sha256', 'aws4_request',
        hash_hmac('sha256', 's3',
            hash_hmac('sha256', $region,
                hash_hmac('sha256', $date, 'AWS4' . $secret, true),
            true),
        true),
    true);

    $signature = hash_hmac('sha256', $string_to_sign, $signing_key);

    return "https://{$host}/" . rawurlencode($object) . '?' . implode('&', [
        'X-Amz-Algorithm=AWS4-HMAC-SHA256',
        'X-Amz-Credential=' . rawurlencode("{$key}/{$scope}"),
        'X-Amz-Date=' . $datetime,
        'X-Amz-Expires=' . $ttl,
        'X-Amz-SignedHeaders=host',
        'X-Amz-Signature=' . $signature,
    ]);
}

// ── AJAX: Logout ──────────────────────────────────────────────────────────────

add_action('wp_ajax_gfd_logout', 'gfd_sub_ajax_logout');

function gfd_sub_ajax_logout(): void {
    check_ajax_referer('gfd_nonce', '_wpnonce');
    setcookie('gfd_token', '', ['expires' => time() - 3600, 'path' => '/']);
    wp_send_json_success(['redirect' => home_url('/')]);
}

// ── Version manifest endpoint ─────────────────────────────────────────────────
// GET /api/gfd-version.json → served by EDIS dis-gfd-subscription (S124-05).

add_action('init', function() {
    if ($_SERVER['REQUEST_URI'] === '/api/gfd-version.json') {
        header('Content-Type: application/json');
        header('Cache-Control: public, max-age=300');
        $manifest = gfd_sub_get_version_manifest();
        echo wp_json_encode($manifest);
        exit;
    }
});

function gfd_sub_get_version_manifest(): array {
    // In production: read from a manifest.json stored in S3 or wp-content.
    // The game server publishes this file after each build.
    $manifest_path = WP_CONTENT_DIR . '/gfd-version-manifest.json';
    if (file_exists($manifest_path)) {
        $data = json_decode(file_get_contents($manifest_path), true);
        if (is_array($data)) return $data;
    }
    // Fallback stub until first build is published.
    return [
        'version'      => '0.1.0-alpha',
        'released_at'  => '2026-06-24',
        'size_mb'      => 48,
        'demo_size_mb' => 12,
        'changelog'    => 'VS0 Detroit slice — Detroit Apartment + Detroit School.',
        'required'     => '0.1.0-alpha',
    ];
}
