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

function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

async function startFightWithOpponent(form, opponentId) {
    const fd = new FormData(form);
    fd.append('opponent_id', opponentId);
    try {
        const res  = await fetch('../api/start_fight.php', { method: 'POST', body: fd });
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
            window.location.href = data.redirect;
        } else {
            const modal = document.getElementById('opponent-modal');
            if (modal) modal.style.display = 'none';
            const btn = form.querySelector('button[type=submit], button:not([type])');
            if (btn) { btn.disabled = false; btn.textContent = '⚔ ENTRER DANS L\'ARÈNE'; }
            const msg = form.querySelector('[data-msg]');
            if (msg) { msg.className = 'form-msg error'; msg.textContent = data.error || 'Erreur'; }
        }
    } catch (err) {
        const btn = form.querySelector('button[type=submit], button:not([type])');
        if (btn) { btn.disabled = false; btn.textContent = '⚔ ENTRER DANS L\'ARÈNE'; }
    }
}

const fightForm = document.getElementById('fight-form');
if (fightForm) {
    fightForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const btn = fightForm.querySelector('button');
        if (!btn || btn.disabled) return;
        btn.disabled = true;
        btn.textContent = '⏳ Recherche…';

        try {
            const res  = await fetch('../api/get_opponents.php', { method: 'POST', body: new FormData(fightForm) });
            const data = await res.json();
            if (!data.ok) {
                btn.disabled = false;
                btn.textContent = '⚔ ENTRER DANS L\'ARÈNE';
                const msg = fightForm.querySelector('[data-msg]');
                if (msg) { msg.className = 'form-msg error'; msg.textContent = data.error || 'Erreur'; }
                return;
            }

            const modal   = document.getElementById('opponent-modal');
            const choices = document.getElementById('opponent-choices');
            if (!modal || !choices) {
                await startFightWithOpponent(fightForm, data.opponents[0].id);
                return;
            }

            choices.innerHTML = data.opponents.map((opp) => `
                <div class="opponent-card">
                    <div class="opp-card-name">${escapeHtml(opp.name)}</div>
                    <div class="opp-card-meta">
                        <span class="opp-level-badge">Niv. ${opp.level}</span>
                        <span class="opp-mmr-badge">MMR ${opp.mmr}</span>
                    </div>
                    <button class="btn btn-primary opp-fight-btn" data-id="${opp.id}" type="button">⚔ Affronter</button>
                </div>
            `).join('');

            choices.querySelectorAll('.opp-fight-btn').forEach((oppBtn) => {
                oppBtn.addEventListener('click', () => {
                    modal.style.display = 'none';
                    oppBtn.disabled = true;
                    startFightWithOpponent(fightForm, oppBtn.dataset.id);
                });
            });

            const cancelBtn = document.getElementById('opponent-cancel');
            if (cancelBtn) {
                cancelBtn.onclick = () => {
                    modal.style.display = 'none';
                    btn.disabled = false;
                    btn.textContent = '⚔ ENTRER DANS L\'ARÈNE';
                };
            }

            modal.style.display = 'flex';
            modal.setAttribute('aria-hidden', 'false');
        } catch (err) {
            btn.disabled = false;
            btn.textContent = '⚔ ENTRER DANS L\'ARÈNE';
        }
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

document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        const modal = document.getElementById('opponent-modal');
        if (modal && modal.style.display !== 'none') {
            e.preventDefault();
            document.getElementById('opponent-cancel')?.click();
        }
    }
});
