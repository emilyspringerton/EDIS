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

// S23-03: Route /ticker/{SYM} virtual pages to page-ticker.php template.
add_filter( 'template_include', function ( string $template ): string {
    if ( get_query_var( 'edis_ticker' ) ) {
        $ticker_tmpl = locate_template( 'page-ticker.php' );
        if ( $ticker_tmpl ) {
            return $ticker_tmpl;
        }
    }
    return $template;
} );

// S23-02: OpenGraph meta for ticker pages and editorial posts.
add_action( 'wp_head', function (): void {
    $ticker = strtoupper( get_query_var( 'edis_ticker', '' ) );

    if ( $ticker ) {
        // Ticker virtual page.
        $title = esc_attr( $ticker . ' Governance Intelligence — FatBaby' );
        $desc  = esc_attr( 'Governance signals, director data, and EPS history for ' . $ticker . '. Powered by FatBaby.' );
        $url   = esc_url( home_url( '/ticker/' . strtolower( $ticker ) ) );
        printf( '<meta property="og:type" content="article" />' . "\n" );
        printf( '<meta property="og:title" content="%s" />' . "\n", $title );
        printf( '<meta property="og:description" content="%s" />' . "\n", $desc );
        printf( '<meta property="og:url" content="%s" />' . "\n", $url );
        printf( '<meta name="description" content="%s" />' . "\n", $desc );
        printf( '<title>%s</title>' . "\n", $title );
    } elseif ( is_singular() ) {
        // Editorial post.
        $title = esc_attr( get_the_title() . ' — FatBaby' );
        $desc  = esc_attr( wp_strip_all_tags( get_the_excerpt() ) );
        $url   = esc_url( get_permalink() );
        printf( '<meta property="og:type" content="article" />' . "\n" );
        printf( '<meta property="og:title" content="%s" />' . "\n", $title );
        if ( $desc ) {
            printf( '<meta property="og:description" content="%s" />' . "\n", $desc );
            printf( '<meta name="description" content="%s" />' . "\n", $desc );
        }
        printf( '<meta property="og:url" content="%s" />' . "\n", $url );
    }
}, 5 ); // priority 5 so Yoast SEO (priority 1) can override if installed
