<?php
/**
 * GoblinDragon homepage template.
 * Hero (CRT scanline) + Trailer slot + Tiers grid.
 */
get_header();
?>

<!-- ── Hero ──────────────────────────────────────────────────────────────── -->
<section class="gfd-hero">
    <div class="gfd-hero__bg"></div>
    <div class="gfd-hero__scanlines" aria-hidden="true"></div>
    <div class="gfd-hero__vignette" aria-hidden="true"></div>
    <div class="gfd-container">
        <div class="gfd-hero__content">
            <p class="gfd-hero__tag"><?php esc_html_e('GoblinFoxDragon — Open City', 'goblindragon'); ?></p>
            <h1 class="gfd-hero__title">
                <?php esc_html_e('The City', 'goblindragon'); ?><br>
                <span><?php esc_html_e('Never Sleeps.', 'goblindragon'); ?></span>
            </h1>
            <p class="gfd-hero__subtitle">
                <?php esc_html_e('TRAPX. Field Offices. Rogue Swarms. The Dragon watches. Take control of a district — or lose it.', 'goblindragon'); ?>
            </p>
            <a href="<?php echo esc_url(home_url('/play')); ?>" class="gfd-btn gfd-btn--primary">
                <?php esc_html_e('Take Control — Free Trial', 'goblindragon'); ?>
            </a>
            <a href="<?php echo esc_url(home_url('/game')); ?>" class="gfd-btn gfd-btn--ghost">
                <?php esc_html_e('Learn More', 'goblindragon'); ?>
            </a>
        </div>
    </div>
</section>

<!-- ── Trailer ────────────────────────────────────────────────────────────── -->
<section class="gfd-section" style="background:var(--gfd-dark);">
    <div class="gfd-container">
        <p class="gfd-section__eyebrow"><?php esc_html_e('Channel 11 — Broadcast', 'goblindragon'); ?></p>
        <h2 class="gfd-section__title"><?php esc_html_e('What's Happening in the City', 'goblindragon'); ?></h2>
        <div class="gfd-trailer" style="margin-top:32px;">
            <?php
            // If a video URL is set in theme options, embed it; otherwise show placeholder.
            $trailer_url = get_theme_mod('gfd_trailer_url', '');
            if ($trailer_url) :
                echo wp_oembed_get(esc_url($trailer_url), ['width' => 1100]);
            else :
            ?>
            <div class="gfd-trailer__placeholder">
                <div class="gfd-trailer__play" role="button" tabindex="0" aria-label="<?php esc_attr_e('Play trailer', 'goblindragon'); ?>">▶</div>
                <span><?php esc_html_e('Game Trailer — Coming Soon', 'goblindragon'); ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- ── Subscription tiers ─────────────────────────────────────────────────── -->
<section class="gfd-section" id="plans">
    <div class="gfd-container">
        <p class="gfd-section__eyebrow"><?php esc_html_e('Subscription Tiers', 'goblindragon'); ?></p>
        <h2 class="gfd-section__title"><?php esc_html_e('Choose Your Access Level', 'goblindragon'); ?></h2>
        <p class="gfd-section__body">
            <?php esc_html_e('Free trial gives you Detroit Apartment. Frequency opens the full city. Bloc gives you guild tools and annual access.', 'goblindragon'); ?>
        </p>
        <div class="gfd-tiers">
            <!-- Free Trial -->
            <div class="gfd-tier">
                <p class="gfd-tier__name"><?php esc_html_e('Free Trial', 'goblindragon'); ?></p>
                <div class="gfd-tier__price">$0 <span>/forever</span></div>
                <p class="gfd-tier__desc"><?php esc_html_e('Detroit Apartment — scene 200. Test the MUD. See if you can hold an FO.', 'goblindragon'); ?></p>
                <ul class="gfd-tier__features">
                    <li><?php esc_html_e('Access to Detroit Apartment (scene 200)', 'goblindragon'); ?></li>
                    <li><?php esc_html_e('Basic MUD commands', 'goblindragon'); ?></li>
                    <li><?php esc_html_e('Flip phone (tab 1 only)', 'goblindragon'); ?></li>
                    <li><?php esc_html_e('Community forums', 'goblindragon'); ?></li>
                </ul>
                <a href="<?php echo esc_url(home_url('/register')); ?>" class="gfd-btn gfd-btn--ghost" style="width:100%;text-align:center;">
                    <?php esc_html_e('Start Free', 'goblindragon'); ?>
                </a>
            </div>
            <!-- Frequency -->
            <div class="gfd-tier gfd-tier--featured">
                <div class="gfd-tier__badge"><?php esc_html_e('Most Popular', 'goblindragon'); ?></div>
                <p class="gfd-tier__name"><?php esc_html_e('The Frequency', 'goblindragon'); ?></p>
                <div class="gfd-tier__price">$12 <span>/month</span></div>
                <p class="gfd-tier__desc"><?php esc_html_e('Full TRAPX city. All 8 districts. K9 Doctrine. Class unlocks. Dragon events.', 'goblindragon'); ?></p>
                <ul class="gfd-tier__features">
                    <li><?php esc_html_e('All 8 TYLER/TRAPX districts', 'goblindragon'); ?></li>
                    <li><?php esc_html_e('All job class unlock chains', 'goblindragon'); ?></li>
                    <li><?php esc_html_e('Full flip phone (5 tabs)', 'goblindragon'); ?></li>
                    <li><?php esc_html_e('CAST terminal archive access', 'goblindragon'); ?></li>
                    <li><?php esc_html_e('Timeline branch system', 'goblindragon'); ?></li>
                    <li><?php esc_html_e('Priority support', 'goblindragon'); ?></li>
                </ul>
                <a href="<?php echo esc_url(home_url('/subscribe?tier=frequency_monthly')); ?>" class="gfd-btn gfd-btn--primary" style="width:100%;text-align:center;">
                    <?php esc_html_e('Join The Frequency', 'goblindragon'); ?>
                </a>
            </div>
            <!-- Bloc Annual -->
            <div class="gfd-tier gfd-tier--bloc">
                <p class="gfd-tier__name"><?php esc_html_e('The Bloc', 'goblindragon'); ?></p>
                <div class="gfd-tier__price">$96 <span>/year</span></div>
                <p class="gfd-tier__desc"><?php esc_html_e('Annual access + guild tools. Community solidarity. The block protects its own.', 'goblindragon'); ?></p>
                <ul class="gfd-tier__features">
                    <li><?php esc_html_e('Everything in Frequency', 'goblindragon'); ?></li>
                    <li><?php esc_html_e('Guild management tools', 'goblindragon'); ?></li>
                    <li><?php esc_html_e('Bloc faction reputation bonus', 'goblindragon'); ?></li>
                    <li><?php esc_html_e('FO defense NPC patrols', 'goblindragon'); ?></li>
                    <li><?php esc_html_e('Annual billing — save 33%', 'goblindragon'); ?></li>
                </ul>
                <a href="<?php echo esc_url(home_url('/subscribe?tier=bloc_annual')); ?>" class="gfd-btn gfd-btn--ghost" style="width:100%;text-align:center;">
                    <?php esc_html_e('Join The Bloc', 'goblindragon'); ?>
                </a>
            </div>
        </div>
    </div>
</section>

<?php get_footer(); ?>
