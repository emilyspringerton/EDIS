<footer class="gfd-footer">
    <div class="gfd-container">
        <div class="gfd-footer__inner">
            <span>
                &copy; <?php echo date('Y'); ?> <?php bloginfo('name'); ?> &mdash; EINHORN_INDUSTRIAL
            </span>
            <span class="gfd-footer__signal">
                <?php esc_html_e('THE CITY IS ALWAYS ON', 'goblindragon'); ?>
            </span>
            <?php
            wp_nav_menu([
                'theme_location' => 'footer',
                'container'      => false,
                'menu_class'     => 'gfd-nav',
                'depth'          => 1,
                'fallback_cb'    => function() {
                    echo '<ul class="gfd-nav">';
                    echo '<li><a href="' . esc_url(home_url('/privacy')) . '">Privacy</a></li>';
                    echo '<li><a href="' . esc_url(home_url('/terms')) . '">Terms</a></li>';
                    echo '<li><a href="' . esc_url(home_url('/support')) . '">Support</a></li>';
                    echo '</ul>';
                },
            ]);
            ?>
        </div>
    </div>
</footer>

<?php wp_footer(); ?>
</body>
</html>
