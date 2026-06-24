/* GoblinDragon theme JS — modal, auth, timecode tick */
(function() {
    'use strict';

    // ── Login modal ───────────────────────────────────────────────────────────

    var modal = document.getElementById('gfd-login-modal');
    var cfg   = window.gfdConfig || {};

    function openModal() {
        if (!modal) return;
        modal.classList.add('is-open');
        modal.querySelector('input[type="email"]').focus();
    }

    function closeModal() {
        if (!modal) return;
        modal.classList.remove('is-open');
    }

    // Open on [data-login-trigger] clicks.
    document.addEventListener('click', function(e) {
        if (e.target.closest('[data-login-trigger]')) {
            e.preventDefault();
            openModal();
        }
        if (e.target.closest('[data-modal-close]') || e.target === modal) {
            closeModal();
        }
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeModal();
    });

    // ── Login form submit → IDUNA auth ────────────────────────────────────────

    var loginForm = document.getElementById('gfd-login-form');
    if (loginForm) {
        loginForm.addEventListener('submit', function(e) {
            e.preventDefault();
            var email    = loginForm.querySelector('[name="email"]').value;
            var password = loginForm.querySelector('[name="password"]').value;
            var btn      = loginForm.querySelector('[type="submit"]');
            btn.textContent = 'Connecting…';
            btn.disabled    = true;

            fetch(cfg.ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action:    'gfd_login',
                    email:     email,
                    password:  password,
                    _wpnonce:  cfg.nonce,
                }),
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    window.location.href = data.data.redirect || '/account';
                } else {
                    btn.textContent = data.data.message || 'Login failed';
                    btn.disabled    = false;
                }
            })
            .catch(function() {
                btn.textContent = 'Connection error — try again';
                btn.disabled    = false;
            });
        });
    }

    // ── Channel 11 broadcast timecode tick ────────────────────────────────────

    var frames    = document.querySelectorAll('.gfd-broadcast-frame');
    var frameNum  = 0;

    function padTwo(n) { return n < 10 ? '0' + n : '' + n; }

    function tickTimecode() {
        var now  = new Date();
        var tc   = padTwo(now.getHours()) + ':' +
                   padTwo(now.getMinutes()) + ':' +
                   padTwo(now.getSeconds()) + ':' +
                   padTwo(frameNum % 25);
        frameNum++;
        frames.forEach(function(el) { el.setAttribute('data-timecode', tc); });
    }

    if (frames.length) {
        tickTimecode();
        setInterval(tickTimecode, 1000 / 25); // ~24fps
    }

})();
