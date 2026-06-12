<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="profile" href="https://gmpg.org/xfn/11">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<header class="site-header">
  <div class="site-wrap">
    <p class="site-title"><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php bloginfo( 'name' ); ?></a></p>
    <nav class="site-nav">
      <?php wp_nav_menu( [ 'theme_location' => 'primary', 'container' => false, 'items_wrap' => '%3$s', 'walker' => new EDIS_Flat_Nav_Walker() ] ); ?>
      <a href="<?php echo esc_url( home_url( '/ask' ) ); ?>">Ask Emily</a>
    </nav>
  </div>
</header>
<?php

// Minimal nav walker — renders <a> tags without <ul>/<li> wrapping.
class EDIS_Flat_Nav_Walker extends Walker_Nav_Menu {
    public function start_el( &$output, $data_object, $depth = 0, $args = null, $id = 0 ) {
        $output .= '<a href="' . esc_attr( $data_object->url ) . '">' . esc_html( $data_object->title ) . '</a>';
    }
    public function end_el( &$output, $data_object, $depth = 0, $args = null ) {}
    public function start_lvl( &$output, $depth = 0, $args = null ) {}
    public function end_lvl( &$output, $depth = 0, $args = null ) {}
}
