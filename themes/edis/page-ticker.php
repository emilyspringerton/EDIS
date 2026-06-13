<?php
/**
 * Template Name: Ticker Page
 *
 * Renders governance intelligence for a ticker.
 * URL: /ticker/AAPL → query var edis_ticker=AAPL (see functions.php rewrite rule).
 * Also usable as a static WordPress Page with Template: Ticker Page selected.
 */
get_header();
$ticker = strtoupper( get_query_var( 'edis_ticker', '' ) );
if ( ! $ticker && get_the_ID() ) {
    // Fall back to a custom field on the page for static ticker pages.
    $ticker = strtoupper( get_post_meta( get_the_ID(), 'edis_ticker', true ) );
}
?>
<div class="site-wrap">
  <div class="site-main">
    <main class="content">
      <?php if ( ! $ticker ) : ?>
        <p>Ticker not specified. Use URL <code>/ticker/AAPL</code>.</p>
      <?php else : ?>
        <h1 style="font-size:1.75rem;font-weight:800;margin-bottom:0.25rem"><?php echo esc_html( $ticker ); ?></h1>
        <p style="color:#64748b;font-size:0.85rem;margin-top:0">Governance intelligence · Updated from FatBaby pipeline</p>

        <h2 style="font-size:0.85rem;font-weight:700;text-transform:uppercase;color:#64748b;margin:1.5rem 0 0.5rem">Corporate Signals</h2>
        <?php echo do_shortcode( '[edis_signals ticker="' . esc_attr( $ticker ) . '" limit="15"]' ); ?>

        <h2 style="font-size:0.85rem;font-weight:700;text-transform:uppercase;color:#64748b;margin:1.5rem 0 0.5rem">Board &amp; Governance</h2>
        <?php echo do_shortcode( '[edis_entity ticker="' . esc_attr( $ticker ) . '"]' ); ?>

        <h2 style="font-size:0.85rem;font-weight:700;text-transform:uppercase;color:#64748b;margin:1.5rem 0 0.5rem">EPS History</h2>
        <?php echo do_shortcode( '[edis_eps ticker="' . esc_attr( $ticker ) . '" periods="8"]' ); ?>

        <h2 style="font-size:0.85rem;font-weight:700;text-transform:uppercase;color:#64748b;margin:1.5rem 0 0.5rem">Press Releases</h2>
        <?php echo do_shortcode( '[edis_press_releases ticker="' . esc_attr( $ticker ) . '" limit="10"]' ); ?>

        <h2 style="font-size:0.85rem;font-weight:700;text-transform:uppercase;color:#64748b;margin:1.5rem 0 0.5rem">Ask Emily about <?php echo esc_html( $ticker ); ?></h2>
        <?php echo do_shortcode( '[ask_emily ticker="' . esc_attr( $ticker ) . '"]' ); ?>

        <?php
        // Editorial posts for this ticker.
        $posts = new WP_Query( [ 'post_type' => 'post', 'tag' => strtolower( $ticker ), 'posts_per_page' => 5 ] );
        if ( $posts->have_posts() ) : ?>
          <h2 style="font-size:0.85rem;font-weight:700;text-transform:uppercase;color:#64748b;margin:1.5rem 0 0.5rem">Editorial</h2>
          <?php while ( $posts->have_posts() ) : $posts->the_post(); ?>
          <article class="card">
            <h3 style="font-size:1rem;margin:0"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
            <p class="card__meta"><?php the_date(); ?></p>
            <p class="card__preview"><?php the_excerpt(); ?></p>
          </article>
          <?php endwhile; wp_reset_postdata(); ?>
        <?php endif; ?>
      <?php endif; ?>
    </main>
    <aside class="sidebar">
      <?php dynamic_sidebar( 'primary-sidebar' ); ?>
    </aside>
  </div>
</div>
<?php get_footer(); ?>
