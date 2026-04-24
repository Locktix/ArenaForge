// Tournoi : inscription + lancement

async function postForm(form, endpoint) {
    const msg = form.querySelector('[data-msg]');
    if (msg) { msg.className = 'form-msg'; msg.textContent = '…'; }
    try {
        const res = await fetch(endpoint, {
            method: 'POST',
            body: new FormData(form),
        });
        const data = await res.json();
        if (data.ok) {
            window.location.href = data.redirect || window.location.href;
        } else if (msg) {
            msg.className = 'form-msg error';
            msg.textContent = data.error || 'Erreur';
        }
    } catch (err) {
        if (msg) {
            msg.className = 'form-msg error';
            msg.textContent = 'Erreur réseau';
        }
    }
}

document.querySelectorAll('.tournament-join-form').forEach((form) => {
    form.addEventListener('submit', (e) => {
        e.preventDefault();
        postForm(form, '../api/tournament_join.php');
    });
});

document.querySelectorAll('.tournament-run-form').forEach((form) => {
    form.addEventListener('submit', (e) => {
        e.preventDefault();
        const btn = form.querySelector('button');
        if (btn) { btn.disabled = true; btn.textContent = 'Résolution...'; }
        postForm(form, '../api/tournament_run.php');
    });
});
