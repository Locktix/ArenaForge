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

const evolveBtn = document.querySelector('.pet-evolve-btn');
if (evolveBtn) {
    evolveBtn.addEventListener('click', () => {
        if (!confirm('Évoluer ton compagnon pour 150 or ? L\'ancien sera remplacé définitivement.')) return;
        evolveBtn.disabled = true;
        evolveBtn.textContent = '⏳ Évolution…';
        const fd = new FormData();
        fd.append('csrf',   evolveBtn.dataset.csrf);
        fd.append('pet_id', evolveBtn.dataset.petId);
        fetch('../api/pet_evolve.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.ok) {
                    if (window.Toast && data.toast) window.Toast.queue([data.toast]);
                    (window.arenaNavigate || (u => { window.location.href = u; }))(data.redirect);
                } else {
                    evolveBtn.disabled = false;
                    evolveBtn.textContent = '✨ Évoluer';
                    alert(data.error || 'Erreur lors de l\'évolution.');
                }
            })
            .catch(() => {
                evolveBtn.disabled = false;
                evolveBtn.textContent = '✨ Évoluer';
                alert('Erreur réseau.');
            });
    });
}

const assignPetForm = document.getElementById('assign-pet-form');
if (assignPetForm) {
    assignPetForm.querySelectorAll('.create-pet-card').forEach(function (card) {
        card.addEventListener('click', function () {
            assignPetForm.querySelectorAll('.create-pet-card').forEach(function (c) {
                c.classList.remove('create-pet-card--selected');
            });
            card.classList.add('create-pet-card--selected');
        });
    });

    const assignMsg = assignPetForm.querySelector('[data-assign-pet-msg]');
    const assignBtn = assignPetForm.querySelector('button[type="submit"]');
    assignPetForm.addEventListener('submit', function (e) {
        e.preventDefault();
        assignBtn.disabled = true;
        assignBtn.textContent = '⏳';
        fetch('../api/assign_pet.php', { method: 'POST', body: new FormData(assignPetForm) })
            .then(r => r.json())
            .then(function (data) {
                if (data.ok) {
                    (window.arenaNavigate || (u => { window.location.href = u; }))(data.redirect);
                } else {
                    assignBtn.disabled = false;
                    assignBtn.textContent = 'Adopter ce compagnon';
                    if (assignMsg) { assignMsg.className = 'form-msg error'; assignMsg.textContent = data.error || 'Erreur'; }
                }
            })
            .catch(function () {
                assignBtn.disabled = false;
                assignBtn.textContent = 'Adopter ce compagnon';
                if (assignMsg) { assignMsg.className = 'form-msg error'; assignMsg.textContent = 'Erreur réseau'; }
            });
    });
}
