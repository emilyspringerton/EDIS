<?php
// Homepage: recent WordPress posts (editorial) + governance signal feed.
get_header();
?>
<div class="site-wrap">
  <div class="site-main">
    <main class="content">
      <h2 style="font-size:1rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#64748b;margin-bottom:1rem;">Latest</h2>
      <?php if ( have_posts() ) : while ( have_posts() ) : the_post(); ?>
      <article class="card">
        <p class="card__meta"><?php the_date(); ?> &middot; <?php the_category( ', ' ); ?></p>
        <h2 class="card__ticker" style="font-size:1rem"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
        <p class="card__preview"><?php the_excerpt(); ?></p>
      </article>
      <?php endwhile; else : ?>
      <p>No posts yet. <a href="<?php echo admin_url( 'post-new.php' ); ?>">Create one</a>.</p>
      <?php endif; ?>
      <?php the_posts_pagination(); ?>
    </main>
    <aside class="sidebar">
      <?php dynamic_sidebar( 'primary-sidebar' ); ?>
    </aside>
  </div>
</div>
<?php get_footer(); ?>
