<?php
/**
 * Template Name: Account Page
 * Account management: subscription status, tier, billing portal, game client version.
 * Requires IDUNA JWT cookie (gfd_token).
 */
get_header();

// Redirect guests to login.
if (!is_user_logged_in() && !isset($_COOKIE['gfd_token'])) {
    wp_redirect(home_url('/'));
    exit;
}
?>

<main class="gfd-main" style="min-height:80vh;padding:60px 0;">
    <div class="gfd-container">
        <p class="gfd-section__eyebrow">Your Account</p>
        <h1 class="gfd-section__title">Account Dashboard</h1>

        <div id="gfd-account-loading" style="color:var(--gfd-muted);font-family:var(--font-mono);">
            Loading account data…
        </div>

        <div id="gfd-account-content" style="display:none;margin-top:32px;">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;">

                <!-- Subscription tile -->
                <div style="background:var(--gfd-mid);border:1px solid var(--gfd-border);border-radius:4px;padding:28px;">
                    <p style="font-family:var(--font-mono);font-size:0.7rem;text-transform:uppercase;letter-spacing:0.15em;color:var(--gfd-muted);margin-bottom:12px;">Subscription</p>
                    <p id="gfd-tier-name" style="font-size:1.6rem;font-weight:900;color:#fff;margin-bottom:4px;">—</p>
                    <p id="gfd-tier-status" style="font-size:0.85rem;color:var(--gfd-muted);margin-bottom:20px;">—</p>
                    <a id="gfd-billing-portal" href="#" class="gfd-btn gfd-btn--ghost" style="font-size:0.85rem;">
                        <?php esc_html_e('Manage Billing', 'goblindragon'); ?>
                    </a>
                    <a href="<?php echo esc_url(home_url('/#plans')); ?>" class="gfd-btn gfd-btn--primary" style="font-size:0.85rem;margin-left:12px;">
                        <?php esc_html_e('Upgrade', 'goblindragon'); ?>
                    </a>
                </div>

                <!-- Game client tile -->
                <div style="background:var(--gfd-mid);border:1px solid var(--gfd-border);border-radius:4px;padding:28px;">
                    <p style="font-family:var(--font-mono);font-size:0.7rem;text-transform:uppercase;letter-spacing:0.15em;color:var(--gfd-muted);margin-bottom:12px;">Game Client</p>
                    <p id="gfd-client-version" style="font-size:1.6rem;font-weight:900;color:#fff;margin-bottom:4px;">—</p>
                    <p id="gfd-client-status" style="font-size:0.85rem;color:var(--gfd-muted);margin-bottom:20px;">—</p>
                    <a id="gfd-download-btn" href="<?php echo esc_url(home_url('/download')); ?>" class="gfd-btn gfd-btn--primary" style="font-size:0.85rem;">
                        <?php esc_html_e('Download Client', 'goblindragon'); ?>
                    </a>
                </div>
            </div>

            <!-- Feature access list -->
            <div style="margin-top:24px;background:var(--gfd-mid);border:1px solid var(--gfd-border);border-radius:4px;padding:28px;">
                <p style="font-family:var(--font-mono);font-size:0.7rem;text-transform:uppercase;letter-spacing:0.15em;color:var(--gfd-muted);margin-bottom:16px;">Current Access</p>
                <ul id="gfd-features" style="list-style:none;display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px 24px;"></ul>
            </div>
        </div>
    </div>
</main>

<script>
(function() {
    var cfg = window.gfdConfig || {};
    var token = document.cookie.split('; ').find(function(r){ return r.startsWith('gfd_token='); });
    token = token ? token.split('=')[1] : null;

    if (!token) {
        document.getElementById('gfd-account-loading').textContent = 'No session token found. Please sign in.';
        return;
    }

    fetch(cfg.idunaUrl + '/api/v1/subscriptions/me', {
        headers: { 'Authorization': 'Bearer ' + token }
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        document.getElementById('gfd-account-loading').style.display = 'none';
        document.getElementById('gfd-account-content').style.display = 'block';

        var tierMap = {
            'free_trial':          'Free Trial',
            'frequency_monthly':   'The Frequency',
            'frequency_annual':    'The Frequency (Annual)',
            'bloc_annual':         'The Bloc',
        };
        var tierName = data.gfd_tier ? (tierMap[data.gfd_tier] || data.gfd_tier) : 'Free Trial';
        document.getElementById('gfd-tier-name').textContent = tierName;
        document.getElementById('gfd-tier-status').textContent =
            data.subscribed ? 'Active' : 'Inactive — upgrade to unlock full city access';

        // Feature list.
        var features = {
            'detroit_apartment':    'Detroit Apartment',
            'basic_mud':            'Basic MUD commands',
            'phone_tab1':           'Flip phone (tab 1)',
            'forums':               'Community forums',
            'all_districts':        'All 8 districts',
            'all_classes':          'All class unlock chains',
            'full_phone':           'Full flip phone',
            'cast_terminal':        'CAST terminal archive',
            'timeline':             'Timeline branch system',
            'priority_support':     'Priority support',
            'guild_tools':          'Guild tools',
            'bloc_faction_bonus':   'Bloc faction bonus',
            'fo_defense_npcs':      'FO defense NPCs',
            'annual_billing':       'Annual billing',
        };
        var ul = document.getElementById('gfd-features');
        (data.features || ['detroit_apartment','basic_mud','forums']).forEach(function(f) {
            var li = document.createElement('li');
            li.style.cssText = 'font-size:0.85rem;color:var(--gfd-text);padding:4px 0;';
            li.innerHTML = '<span style="color:var(--gfd-freq)">✓</span>  ' + (features[f] || f);
            ul.appendChild(li);
        });
    })
    .catch(function() {
        document.getElementById('gfd-account-loading').textContent = 'Could not load account data.';
    });

    // Load client version from version manifest.
    fetch('/api/gfd-version.json')
    .then(function(r) { return r.json(); })
    .then(function(v) {
        document.getElementById('gfd-client-version').textContent = v.version || '—';
        document.getElementById('gfd-client-status').textContent = 'Latest release: ' + (v.released_at || '');
    })
    .catch(function() { /* version endpoint not yet deployed */ });
})();
</script>

<?php get_footer(); ?>
