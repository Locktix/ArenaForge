// Page gladiateur : lancement combat + choix level-up

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
            window.location.href = data.redirect;
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

const fightForm = document.getElementById('fight-form');
if (fightForm) {
    fightForm.addEventListener('submit', (e) => {
        e.preventDefault();
        postForm(fightForm, 'api/start_fight.php');
    });
}

document.querySelectorAll('.levelup-form').forEach((f) => {
    f.addEventListener('submit', (e) => {
        e.preventDefault();
        postForm(f, 'api/level_up.php');
    });
});
