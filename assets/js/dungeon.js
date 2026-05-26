// ArenaForge — Donjons
// Gère : entrer dans un donjon, avancer dans une run, abandonner.

(function () {
    const BASE_URL = (function () {
        const el = document.querySelector('script[src*="dungeon.js"]');
        return el ? el.src.replace(/\/assets\/js\/dungeon\.js(\?.*)?$/, '') : '';
    })();

    function navigate(url) {
        if (window.arenaNavigate) {
            window.arenaNavigate(url);
        } else {
            window.location.href = url;
        }
    }

    function postApi(endpoint, body) {
        return fetch(BASE_URL + '/api/' + endpoint, {
            method: 'POST',
            body: body,
        }).then(r => r.json());
    }

    function setLoading(btn, loading) {
        btn.disabled = loading;
        if (loading) {
            btn._orig = btn.textContent;
            btn.textContent = '⏳ …';
        } else if (btn._orig) {
            btn.textContent = btn._orig;
        }
    }

    function showError(msg) {
        if (window.Toast) {
            window.Toast.queue([{
                title: 'Erreur',
                description: msg,
                icon_path: 'assets/svg/ui/nav_settings.svg',
            }]);
        } else {
            alert(msg);
        }
    }

    // ── Entrer dans un donjon ────────────────────────────────────────────────
    document.querySelectorAll('.dungeon-enter-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const code = btn.dataset.code;
            const cost = parseInt(btn.dataset.cost || '0', 10);

            if (cost > 0) {
                if (!confirm('Cette expédition coûte ' + cost + ' combat(s) bonus. Confirmer ?')) return;
            }

            const fd = new FormData();
            fd.append('csrf', btn.dataset.csrf);
            fd.append('code', code);

            setLoading(btn, true);

            postApi('dungeon_start.php', fd).then(function (data) {
                if (data.ok && data.redirect) {
                    if (window.SFX) SFX.play('start');
                    navigate(data.redirect);
                } else {
                    setLoading(btn, false);
                    showError(data.error || 'Impossible d\'entrer dans ce donjon.');
                }
            }).catch(function () {
                setLoading(btn, false);
                showError('Erreur réseau. Réessaie.');
            });
        });
    });

    // ── Passer à la salle suivante (ou réclamer la victoire) ─────────────────
    document.querySelectorAll('.dungeon-next-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const runId = btn.dataset.runId;

            const fd = new FormData();
            fd.append('csrf', btn.dataset.csrf);
            fd.append('run_id', runId);

            setLoading(btn, true);

            postApi('dungeon_next.php', fd).then(function (data) {
                if (!data.ok) {
                    setLoading(btn, false);
                    showError(data.error || 'Impossible d\'avancer.');
                    return;
                }

                if (data.outcome === 'victory') {
                    if (window.SFX) SFX.play('victory');
                    navigate('dungeon.php?result=victory&run_id=' + data.run_id);
                } else if (data.outcome === 'continue' && data.redirect) {
                    if (window.SFX) SFX.play('start');
                    navigate(data.redirect);
                } else if (data.outcome === 'defeat') {
                    navigate('dungeon.php');
                } else {
                    setLoading(btn, false);
                    showError('Réponse inattendue du serveur.');
                }
            }).catch(function () {
                setLoading(btn, false);
                showError('Erreur réseau. Réessaie.');
            });
        });
    });

    // ── Abandonner ───────────────────────────────────────────────────────────
    document.querySelectorAll('.dungeon-abandon-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!btn.dataset.noConfirm && !confirm('Abandonner cette expédition ? Tout le butin non réclamé sera perdu.')) return;

            const runId    = btn.dataset.runId;
            const redirect = btn.dataset.redirect || 'dungeon.php';

            const fd = new FormData();
            fd.append('csrf', btn.dataset.csrf);
            fd.append('run_id', runId);

            setLoading(btn, true);

            postApi('dungeon_abandon.php', fd).then(function (data) {
                if (data.ok) {
                    navigate(redirect);
                } else {
                    setLoading(btn, false);
                    showError(data.error || 'Impossible d\'abandonner.');
                }
            }).catch(function () {
                setLoading(btn, false);
                showError('Erreur réseau. Réessaie.');
            });
        });
    });
})();
