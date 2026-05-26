// Page gladiateur : lancement combat + choix level-up

async function postForm(form, endpoint) {
    const msg = form.querySelector('[data-msg]');
    if (msg) { msg.className = 'form-msg'; msg.textContent = '…'; }
    try {
        const res = await fetch(endpoint, { method: 'POST', body: new FormData(form) });
        const data = await res.json();
        if (data.ok) {
            if (window.Toast && Array.isArray(data.achievements) && data.achievements.length) {
                window.Toast.queue(data.achievements);
            }
            if (window.Toast && Array.isArray(data.pet_evolutions) && data.pet_evolutions.length) {
                window.Toast.queue(data.pet_evolutions.map((e) => ({
                    kind_label: 'Pet évolué !',
                    title: e.from + ' est devenu ' + e.to,
                    reward_xp: 0,
                    icon_path: e.icon_path,
                })));
            }
            (window.arenaNavigate || (u => { window.location.href = u; }))(data.redirect);
        } else if (msg) {
            msg.className = 'form-msg error';
            msg.textContent = data.error || 'Erreur';
        }
    } catch (err) {
        if (msg) { msg.className = 'form-msg error'; msg.textContent = 'Erreur réseau'; }
    }
}

const fightForm = document.getElementById('fight-form');
if (fightForm) {
    fightForm.addEventListener('submit', (e) => {
        e.preventDefault();
        const btn = fightForm.querySelector('button');
        if (!btn || btn.disabled) return;
        btn.disabled = true;
        btn.textContent = '⏳ En chasse…';
        postForm(fightForm, '../api/start_fight.php').catch(() => {
            btn.disabled = false;
            btn.textContent = '⚔ ENTRER DANS L\'ARÈNE';
        });
    });
}

const duoForm = document.getElementById('duo-fight-form');
if (duoForm) {
    duoForm.addEventListener('submit', (e) => {
        e.preventDefault();
        postForm(duoForm, '../api/start_duo_fight.php');
    });
}

const trainingForm = document.getElementById('training-form');
if (trainingForm) {
    trainingForm.addEventListener('submit', (e) => {
        e.preventDefault();
        postForm(trainingForm, '../api/start_training.php');
    });
}

document.querySelectorAll('.levelup-form').forEach((f) => {
    f.addEventListener('submit', (e) => {
        e.preventDefault();
        postForm(f, '../api/level_up.php');
    });
});
