<footer class="site-footer">
  <div class="site-wrap">
    <?php if ( shortcode_exists( 'edis_dis_ad' ) ) : ?>
    <?php echo do_shortcode( '[edis_dis_ad slot="footer"]' ); ?>
    <?php endif; ?>
    <p>
      &copy; <?php echo date( 'Y' ); ?> <?php bloginfo( 'name' ); ?> &middot;
      Governance intelligence powered by <a href="https://github.com/emilyspringerton/PRRJECT_FATBABY">FatBaby</a> &middot;
      <a href="<?php echo esc_url( home_url( '/ask' ) ); ?>">Ask Emily</a>
    </p>
  </div>
</footer>
<?php wp_footer(); ?>
</body>
</html>
