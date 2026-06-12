<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'after_setup_theme', function () {
    add_theme_support( 'title-tag' );
    add_theme_support( 'post-thumbnails' );
    add_theme_support( 'html5', [ 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption' ] );
    register_nav_menus( [ 'primary' => 'Primary Navigation' ] );
} );

add_action( 'widgets_init', function () {
    register_sidebar( [
        'name'          => 'Primary Sidebar',
        'id'            => 'primary-sidebar',
        'before_widget' => '<div class="widget card %2$s">',
        'after_widget'  => '</div>',
        'before_title'  => '<h3 class="widget-title">',
        'after_title'   => '</h3>',
    ] );
} );

add_action( 'wp_enqueue_scripts', function () {
    wp_enqueue_style( 'edis-theme', get_stylesheet_uri(), [], '0.1.0' );
} );

// Ticker page: resolve ?ticker=AAPL → query var
add_action( 'init', function () {
    add_rewrite_tag( '%edis_ticker%', '([A-Z]{1,6})' );
    add_rewrite_rule( '^ticker/([A-Z]{1,6})/?$', 'index.php?edis_ticker=$matches[1]', 'top' );
} );

add_filter( 'query_vars', function ( $vars ) {
    $vars[] = 'edis_ticker';
    return $vars;
} );

// Flush rewrite rules on theme activation.
add_action( 'after_switch_theme', function () {
    flush_rewrite_rules();
} );
