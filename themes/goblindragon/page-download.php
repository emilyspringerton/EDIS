<?php
/**
 * Template Name: Game Download
 * Tier-gated client download page.
 * Frequency+ gets full client. Free trial gets demo client.
 */
get_header();
?>

<main class="gfd-main" style="min-height:80vh;padding:60px 0;">
    <div class="gfd-container">
        <p class="gfd-section__eyebrow"><?php esc_html_e('Game Client', 'goblindragon'); ?></p>
        <h1 class="gfd-section__title"><?php esc_html_e('Download GoblinFoxDragon', 'goblindragon'); ?></h1>

        <div id="gfd-dl-loading" style="color:var(--gfd-muted);font-family:var(--font-mono);margin-top:24px;">
            <?php esc_html_e('Checking access level…', 'goblindragon'); ?>
        </div>

        <!-- Full client (Frequency+) -->
        <div id="gfd-dl-full" style="display:none;margin-top:32px;">
            <div class="gfd-broadcast-frame" style="padding:32px;max-width:600px;" data-timecode="LIVE">
                <p style="font-size:1.2rem;font-weight:700;color:#fff;margin-bottom:8px;">
                    <?php esc_html_e('GoblinFoxDragon — Full Client', 'goblindragon'); ?>
                </p>
                <p id="gfd-full-version" style="font-family:var(--font-mono);font-size:0.8rem;color:var(--gfd-freq);margin-bottom:4px;">v—</p>
                <p id="gfd-full-size" style="font-size:0.85rem;color:var(--gfd-muted);margin-bottom:24px;">—</p>
                <a id="gfd-full-download-btn" href="#" class="gfd-btn gfd-btn--primary" style="display:inline-block;">
                    <?php esc_html_e('Download Full Client', 'goblindragon'); ?>
                </a>
                <p style="font-size:0.75rem;color:var(--gfd-muted);margin-top:12px;">
                    <?php esc_html_e('Signed URL — valid 24 hours. Re-download if expired.', 'goblindragon'); ?>
                </p>
            </div>
        </div>

        <!-- Demo client (Free trial) -->
        <div id="gfd-dl-demo" style="display:none;margin-top:32px;">
            <div style="background:var(--gfd-mid);border:1px solid var(--gfd-border);border-radius:4px;padding:32px;max-width:600px;">
                <p style="font-size:1.2rem;font-weight:700;color:#fff;margin-bottom:8px;">
                    <?php esc_html_e('GoblinFoxDragon — Demo Client', 'goblindragon'); ?>
                </p>
                <p style="font-size:0.85rem;color:var(--gfd-muted);margin-bottom:24px;">
                    <?php esc_html_e('Detroit Apartment only. Upgrade to Frequency to unlock the full city.', 'goblindragon'); ?>
                </p>
                <a id="gfd-demo-download-btn" href="#" class="gfd-btn gfd-btn--ghost" style="display:inline-block;">
                    <?php esc_html_e('Download Demo Client', 'goblindragon'); ?>
                </a>
                <a href="<?php echo esc_url(home_url('/#plans')); ?>" class="gfd-btn gfd-btn--primary" style="margin-left:12px;">
                    <?php esc_html_e('Upgrade to Frequency', 'goblindragon'); ?>
                </a>
            </div>
        </div>

        <!-- Guest gate -->
        <div id="gfd-dl-guest" style="display:none;margin-top:32px;">
            <div style="background:var(--gfd-mid);border:1px solid var(--gfd-border);border-radius:4px;padding:32px;max-width:600px;">
                <p style="font-size:1.1rem;font-weight:700;color:#fff;margin-bottom:12px;">
                    <?php esc_html_e('Sign in to download', 'goblindragon'); ?>
                </p>
                <a href="#" class="gfd-btn gfd-btn--primary" data-login-trigger>
                    <?php esc_html_e('Take Control — Sign In', 'goblindragon'); ?>
                </a>
            </div>
        </div>

        <!-- System requirements -->
        <div style="margin-top:48px;max-width:600px;">
            <p style="font-family:var(--font-mono);font-size:0.7rem;text-transform:uppercase;letter-spacing:0.15em;color:var(--gfd-muted);margin-bottom:16px;">
                <?php esc_html_e('System Requirements', 'goblindragon'); ?>
            </p>
            <table style="width:100%;border-collapse:collapse;font-size:0.85rem;">
                <?php
                $reqs = [
                    [__('OS', 'goblindragon'),      __('Windows 10/11, macOS 12+, Ubuntu 22.04+', 'goblindragon')],
                    [__('CPU', 'goblindragon'),     __('x86-64, 4+ cores recommended', 'goblindragon')],
                    [__('RAM', 'goblindragon'),     __('4 GB minimum, 8 GB recommended', 'goblindragon')],
                    [__('Network', 'goblindragon'), __('Broadband required (UDP/TCP)', 'goblindragon')],
                    [__('Storage', 'goblindragon'), __('500 MB free', 'goblindragon')],
                ];
                foreach ($reqs as $row) : ?>
                <tr style="border-bottom:1px solid var(--gfd-border);">
                    <td style="padding:8px 0;color:var(--gfd-muted);width:100px;"><?php echo esc_html($row[0]); ?></td>
                    <td style="padding:8px 0;color:var(--gfd-text);"><?php echo esc_html($row[1]); ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>
</main>

<script>
(function() {
    var cfg     = window.gfdConfig || {};
    var loading = document.getElementById('gfd-dl-loading');

    function showGuest() {
        loading.style.display = 'none';
        document.getElementById('gfd-dl-guest').style.display = 'block';
    }

    var token = document.cookie.split('; ').find(function(r){ return r.startsWith('gfd_token='); });
    token = token ? token.split('=')[1] : null;
    if (!token) { showGuest(); return; }

    // Request a signed download URL from the EDIS subscription plugin (AJAX handler).
    Promise.all([
        fetch(cfg.ajaxUrl, {
            method: 'POST',
            headers: {'Content-Type':'application/x-www-form-urlencoded'},
            body: new URLSearchParams({action:'gfd_download_url', _wpnonce: cfg.nonce, token: token})
        }).then(function(r){ return r.json(); }),
        fetch('/api/gfd-version.json').then(function(r){ return r.json(); }).catch(function(){ return {}; })
    ]).then(function(results) {
        var dlData = results[0];
        var verData = results[1];

        loading.style.display = 'none';

        if (!dlData.success) { showGuest(); return; }

        var tier = dlData.data.tier || 'free_trial';
        var isFull = (tier === 'frequency_monthly' || tier === 'frequency_annual' || tier === 'bloc_annual');

        if (isFull) {
            document.getElementById('gfd-dl-full').style.display = 'block';
            document.getElementById('gfd-full-version').textContent = 'v' + (verData.version || '0.0.0');
            document.getElementById('gfd-full-size').textContent    = verData.size_mb ? verData.size_mb + ' MB' : '';
            document.getElementById('gfd-full-download-btn').href   = dlData.data.url || '#';
        } else {
            document.getElementById('gfd-dl-demo').style.display = 'block';
            document.getElementById('gfd-demo-download-btn').href = dlData.data.demo_url || '#';
        }
    }).catch(showGuest);
})();
</script>

<?php get_footer(); ?>
