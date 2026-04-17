// Forge — upgrade d'armes + achat/équipement d'armures

(function () {
    async function postForm(form, endpoint, soundOnSuccess = 'forge') {
        const fd = new FormData(form);
        try {
            const res = await fetch(endpoint, { method: 'POST', body: fd });
            const data = await res.json();
            if (!data.ok) {
                alert(data.error || 'Erreur');
                return;
            }
            if (window.SFX) window.SFX.play(soundOnSuccess);
            if (window.Toast && Array.isArray(data.achievements)) {
                data.achievements.forEach((a) => window.Toast.achievement(a));
            }
            // Rechargement complet (simple, pas de risque de désynchro)
            window.location.reload();
        } catch (e) {
            alert('Erreur réseau');
        }
    }

    document.querySelectorAll('.forge-upgrade-form').forEach((f) => {
        f.addEventListener('submit', (e) => {
            e.preventDefault();
            postForm(f, '/ArenaForge/api/forge_upgrade.php', 'forge');
        });
    });

    document.querySelectorAll('.forge-armor-form').forEach((f) => {
        f.addEventListener('submit', (e) => {
            e.preventDefault();
            const action = f.dataset.action || 'buy';
            postForm(f, '/ArenaForge/api/forge_armor.php', action === 'buy' ? 'forge' : 'click');
        });
    });
})();
