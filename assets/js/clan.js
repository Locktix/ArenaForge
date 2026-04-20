// Clans — actions (create / join / leave / kick)

(function () {
    async function postForm(form, endpoint) {
        const msg = form.querySelector('[data-msg]');
        if (msg) { msg.className = 'form-msg'; msg.textContent = '…'; }
        try {
            const res = await fetch(endpoint, { method: 'POST', body: new FormData(form) });
            const data = await res.json();
            if (!data.ok) {
                if (msg) { msg.className = 'form-msg error'; msg.textContent = data.error || 'Erreur'; }
                else alert(data.error || 'Erreur');
                return;
            }
            if (window.Toast && Array.isArray(data.achievements)) {
                data.achievements.forEach((a) => window.Toast.queue([a]));
            }
            if (data.redirect) {
                window.location.href = data.redirect;
            } else {
                window.location.reload();
            }
        } catch (e) {
            if (msg) { msg.className = 'form-msg error'; msg.textContent = 'Erreur réseau'; }
            else alert('Erreur réseau');
        }
    }

    const endpoints = '../api/clan_action.php';
    const selectors = [
        '#clan-create-form',
        '.clan-join-form',
        '.clan-leave-form',
        '.clan-kick-form',
    ];

    selectors.forEach((sel) => {
        document.querySelectorAll(sel).forEach((f) => {
            f.addEventListener('submit', (e) => {
                e.preventDefault();
                if (sel === '.clan-leave-form' && !confirm('Quitter ce clan ?')) return;
                if (sel === '.clan-kick-form' && !confirm('Renvoyer ce membre ?')) return;
                postForm(f, endpoints);
            });
        });
    });
})();
