<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="profile" href="https://gmpg.org/xfn/11">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<header class="gfd-header">
    <div class="gfd-container">
        <div class="gfd-header__inner">
            <a class="gfd-logo" href="<?php echo esc_url(home_url('/')); ?>">
                <?php
                if (has_custom_logo()) {
                    the_custom_logo();
                } else {
                    echo esc_html(get_bloginfo('name', 'display'));
                }
                ?>
            </a>
            <nav aria-label="<?php esc_attr_e('Primary Navigation', 'goblindragon'); ?>">
                <?php
                wp_nav_menu([
                    'theme_location' => 'primary',
                    'container'      => false,
                    'menu_class'     => 'gfd-nav',
                    'fallback_cb'    => function() {
                        echo '<ul class="gfd-nav">';
                        echo '<li><a href="' . esc_url(home_url('/')) . '">Home</a></li>';
                        echo '<li><a href="' . esc_url(home_url('/game')) . '">The Game</a></li>';
                        echo '<li><a href="' . esc_url(home_url('/community')) . '">Community</a></li>';
                        echo '<li class="gfd-nav-cta"><a href="' . esc_url(home_url('/play')) . '">' . esc_html__('Take Control', 'goblindragon') . '</a></li>';
                        echo '</ul>';
                    },
                ]);
                ?>
            </nav>
        </div>
    </div>
</header>

<!-- Login modal (Channel 11 broadcast frame) -->
<div class="gfd-modal-overlay" id="gfd-login-modal" role="dialog" aria-modal="true" aria-labelledby="gfd-modal-title">
    <div class="gfd-modal gfd-broadcast-frame" data-timecode="<?php echo esc_attr(goblindragon_timecode()); ?>">
        <button class="gfd-modal__close" aria-label="Close" data-modal-close>✕</button>
        <h2 id="gfd-modal-title" style="font-size:1.4rem;font-weight:900;color:#fff;margin-bottom:8px;">
            <?php esc_html_e('Take Control', 'goblindragon'); ?>
        </h2>
        <p style="font-size:0.85rem;color:var(--gfd-muted);margin-bottom:24px;">
            <?php esc_html_e('Sign in to your GoblinFoxDragon account.', 'goblindragon'); ?>
        </p>
        <form class="gfd-login-form" id="gfd-login-form" method="post">
            <?php wp_nonce_field('gfd_login', 'gfd_login_nonce'); ?>
            <div class="gfd-form__field">
                <label class="gfd-form__label" for="gfd-email"><?php esc_html_e('Email', 'goblindragon'); ?></label>
                <input class="gfd-form__input" type="email" id="gfd-email" name="email" autocomplete="email" required>
            </div>
            <div class="gfd-form__field">
                <label class="gfd-form__label" for="gfd-password"><?php esc_html_e('Password', 'goblindragon'); ?></label>
                <input class="gfd-form__input" type="password" id="gfd-password" name="password" autocomplete="current-password" required>
            </div>
            <button class="gfd-btn gfd-btn--primary gfd-form__submit" type="submit">
                <?php esc_html_e('Enter the City', 'goblindragon'); ?>
            </button>
            <p style="text-align:center;margin-top:16px;font-size:0.85rem;color:var(--gfd-muted);">
                <?php esc_html_e("No account?", 'goblindragon'); ?>
                <a href="<?php echo esc_url(home_url('/register')); ?>"><?php esc_html_e('Start free trial', 'goblindragon'); ?></a>
            </p>
        </form>
    </div>
</div>
