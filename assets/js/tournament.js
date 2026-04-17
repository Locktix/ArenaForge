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

const joinForm = document.getElementById('tournament-join-form');
if (joinForm) {
    joinForm.addEventListener('submit', (e) => {
        e.preventDefault();
        postForm(joinForm, '/ArenaForge/api/tournament_join.php');
    });
}

const runForm = document.getElementById('tournament-run-form');
if (runForm) {
    runForm.addEventListener('submit', (e) => {
        e.preventDefault();
        const btn = runForm.querySelector('button');
        if (btn) { btn.disabled = true; btn.textContent = 'Résolution...'; }
        postForm(runForm, '/ArenaForge/api/tournament_run.php');
    });
}
