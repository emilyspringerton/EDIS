<?php
/**
 * Template Name: Player Profile
 * Public player profile: character name, job, faction rep, TRAPX district activity.
 * Reads from IDUNA Apples (public endpoints only).
 */
get_header();

$slug = get_query_var('player_slug', '');
if (!$slug) {
    $slug = sanitize_text_field(get_query_var('pagename', ''));
}
?>

<main class="gfd-main" style="min-height:80vh;padding:60px 0;">
    <div class="gfd-container">
        <div id="gfd-profile-loading" style="color:var(--gfd-muted);font-family:var(--font-mono);">
            Loading player profile…
        </div>
        <div id="gfd-profile-content" style="display:none;">

            <!-- Profile header -->
            <div style="display:flex;align-items:center;gap:28px;margin-bottom:40px;">
                <div style="width:72px;height:72px;background:var(--gfd-mid);border:1px solid var(--gfd-border);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.8rem;color:var(--gfd-accent);">
                    🐉
                </div>
                <div>
                    <h1 id="gfd-profile-name" class="gfd-section__title" style="margin-bottom:4px;">—</h1>
                    <p id="gfd-profile-job" style="font-family:var(--font-mono);font-size:0.75rem;color:var(--gfd-freq);text-transform:uppercase;letter-spacing:0.1em;">—</p>
                </div>
            </div>

            <!-- Faction rep grid -->
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:24px;">
                <div style="background:var(--gfd-mid);border:1px solid var(--gfd-border);border-radius:4px;padding:20px;">
                    <p style="font-family:var(--font-mono);font-size:0.65rem;text-transform:uppercase;letter-spacing:0.15em;color:var(--gfd-freq);margin-bottom:8px;">The Frequency</p>
                    <p id="gfd-rep-frequency" style="font-size:1.4rem;font-weight:900;color:#fff;">—</p>
                    <p style="font-size:0.75rem;color:var(--gfd-muted);">Rank</p>
                </div>
                <div style="background:var(--gfd-mid);border:1px solid var(--gfd-border);border-radius:4px;padding:20px;">
                    <p style="font-family:var(--font-mono);font-size:0.65rem;text-transform:uppercase;letter-spacing:0.15em;color:var(--gfd-bloc);margin-bottom:8px;">The Bloc</p>
                    <p id="gfd-rep-bloc" style="font-size:1.4rem;font-weight:900;color:#fff;">—</p>
                    <p style="font-size:0.75rem;color:var(--gfd-muted);">Rank</p>
                </div>
                <div style="background:var(--gfd-mid);border:1px solid var(--gfd-border);border-radius:4px;padding:20px;">
                    <p style="font-family:var(--font-mono);font-size:0.65rem;text-transform:uppercase;letter-spacing:0.15em;color:var(--gfd-procure);margin-bottom:8px;">Procurement</p>
                    <p id="gfd-rep-procurement" style="font-size:1.4rem;font-weight:900;color:#fff;">—</p>
                    <p style="font-size:0.75rem;color:var(--gfd-muted);">Rank</p>
                </div>
            </div>

            <!-- District activity -->
            <div style="background:var(--gfd-mid);border:1px solid var(--gfd-border);border-radius:4px;padding:28px;">
                <p style="font-family:var(--font-mono);font-size:0.7rem;text-transform:uppercase;letter-spacing:0.15em;color:var(--gfd-muted);margin-bottom:16px;">Recent TRAPX Activity</p>
                <ul id="gfd-activity" style="list-style:none;"></ul>
                <p id="gfd-no-activity" style="display:none;color:var(--gfd-muted);font-size:0.85rem;">No activity recorded yet.</p>
            </div>
        </div>
    </div>
</main>

<script>
(function() {
    var cfg     = window.gfdConfig || {};
    var slug    = <?php echo wp_json_encode($slug ?: get_query_var('pagename', '')); ?>;
    var loading = document.getElementById('gfd-profile-loading');
    var content = document.getElementById('gfd-profile-content');

    if (!slug) {
        loading.textContent = 'No player specified.';
        return;
    }

    // S125-05: hit the real /api/v1/players/{slug}/profile endpoint.
    fetch(cfg.idunaUrl + '/api/v1/players/' + encodeURIComponent(slug) + '/profile', {
        headers: window.gfdToken ? { 'Authorization': 'Bearer ' + window.gfdToken } : {}
    })
    .then(function(r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
    })
    .then(function(data) {
        loading.style.display = 'none';
        content.style.display = 'block';
        document.getElementById('gfd-profile-name').textContent = data.display_name || slug;
        document.getElementById('gfd-profile-job').textContent  = (data.job || 'WAR') + ' · K/D ' + (data.kd_ratio || 0).toFixed(2);

        var rep = data.faction_rep || {};
        document.getElementById('gfd-rep-frequency').textContent   = rep.sandoria || 0;
        document.getElementById('gfd-rep-bloc').textContent        = rep.bastok   || 0;
        document.getElementById('gfd-rep-procurement').textContent = rep.windurst || 0;

        var activity = data.trapx_activity || [];
        var ul = document.getElementById('gfd-activity');
        if (activity.length === 0) {
            document.getElementById('gfd-no-activity').style.display = 'block';
        } else {
            activity.forEach(function(a) {
                var li = document.createElement('li');
                li.style.cssText = 'padding:8px 0;border-bottom:1px solid var(--gfd-border);font-size:0.85rem;display:flex;gap:12px;';
                li.innerHTML =
                    '<span style="color:var(--gfd-muted);font-family:var(--font-mono);font-size:0.75rem;white-space:nowrap;">' + (a.recorded_at || '').slice(0,10) + '</span>' +
                    '<span style="color:var(--gfd-freq);font-family:var(--font-mono);font-size:0.7rem;">[' + (a.apple_type || '') + ']</span>' +
                    '<span>' + (a.title || '') + '</span>';
                ul.appendChild(li);
            });
        }
    })
    .catch(function() {
        loading.textContent = 'Could not load profile data.';
    });
})();
</script>

<?php get_footer(); ?>
