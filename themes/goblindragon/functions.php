<?php
/**
 * GoblinDragon theme functions.
 * Dark urban portal theme for GoblinFoxDragon.com.
 * TRAPX aesthetics — CRT scanline hero, Channel 11 broadcast frame.
 */

defined('ABSPATH') || exit;

// ── Theme setup ──────────────────────────────────────────────────────────────

function goblindragon_setup() {
    load_theme_textdomain('goblindragon', get_template_directory() . '/languages');
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('html5', ['script', 'style', 'search-form', 'gallery']);
    add_theme_support('custom-logo');
    register_nav_menus([
        'primary' => __('Primary Navigation', 'goblindragon'),
        'footer'  => __('Footer Navigation', 'goblindragon'),
    ]);
}
add_action('after_setup_theme', 'goblindragon_setup');

// ── Enqueue assets ───────────────────────────────────────────────────────────

function goblindragon_enqueue() {
    $v = wp_get_theme()->get('Version');
    wp_enqueue_style('goblindragon-style', get_stylesheet_uri(), [], $v);
    // Google Fonts: Inter + IBM Plex Mono
    wp_enqueue_style(
        'goblindragon-fonts',
        'https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;700&family=Inter:wght@400;700;900&display=swap',
        [], null
    );
    wp_enqueue_script('goblindragon-main', get_template_directory_uri() . '/js/main.js', [], $v, true);

    // Pass IDUNA auth endpoint to JS.
    wp_localize_script('goblindragon-main', 'gfdConfig', [
        'idunaUrl'       => defined('GFD_IDUNA_URL') ? GFD_IDUNA_URL : '',
        'ajaxUrl'        => admin_url('admin-ajax.php'),
        'nonce'          => wp_create_nonce('gfd_nonce'),
        'currentUser'    => is_user_logged_in() ? wp_get_current_user()->user_login : null,
    ]);
}
add_action('wp_enqueue_scripts', 'goblindragon_enqueue');

// ── IDUNA config constants ───────────────────────────────────────────────────
// Define in wp-config.php:
//   define('GFD_IDUNA_URL', 'https://api.goblinfoxdragon.com/iduna');
//   define('GFD_IDUNA_CLIENT_ID', '...');

// ── Login modal open/close state ─────────────────────────────────────────────

function goblindragon_body_classes($classes) {
    if (!is_user_logged_in()) {
        $classes[] = 'gfd-guest';
    } else {
        $classes[] = 'gfd-authed';
    }
    return $classes;
}
add_filter('body_class', 'goblindragon_body_classes');

// ── Broadcast frame timecode (JS timestamp rendered server-side as seed) ─────

function goblindragon_timecode() {
    return date('H:i:s') . ':' . str_pad(floor(microtime(true) * 25) % 25, 2, '0', STR_PAD_LEFT);
}

// ── Widget areas ─────────────────────────────────────────────────────────────

function goblindragon_widgets_init() {
    register_sidebar([
        'name'          => __('Footer Signal Feed', 'goblindragon'),
        'id'            => 'footer-signal',
        'before_widget' => '<div class="gfd-widget">',
        'after_widget'  => '</div>',
        'before_title'  => '<h4 class="gfd-widget__title">',
        'after_title'   => '</h4>',
    ]);
}
add_action('widgets_init', 'goblindragon_widgets_init');

// ── Custom page templates ─────────────────────────────────────────────────────

function goblindragon_page_templates($templates) {
    $templates['page-account.php']  = __('Account Page', 'goblindragon');
    $templates['page-profile.php']  = __('Player Profile', 'goblindragon');
    $templates['page-download.php'] = __('Game Download', 'goblindragon');
    return $templates;
}
add_filter('theme_page_templates', 'goblindragon_page_templates');
