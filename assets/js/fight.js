// Replay animé du combat à partir du log JSON stocké côté serveur

(function () {
    const F = window.FIGHT;
    if (!F) return;

    const arena   = document.getElementById('arena');
    const f1El    = document.getElementById('fighter1');
    const f2El    = document.getElementById('fighter2');
    const flash   = document.getElementById('flash');
    const logEl   = document.getElementById('combat-log');
    const replayBtn = document.getElementById('replay-btn');
    const petLeftBox  = document.getElementById('pet-left');
    const petRightBox = document.getElementById('pet-right');

    // Map slot -> { el, hpBar, hpMax }
    const slots = new Map();

    function registerMaster(slot, el, hpMax) {
        const bar = el.querySelector('[data-hp-bar]');
        slots.set(slot, { el, hpBar: bar, hpMax, role: 'master' });
    }
    registerMaster('L0', f1El, F.hp1Max);
    registerMaster('R0', f2El, F.hp2Max);

    function buildPetSprite(pet, sideBox, side) {
        const wrap = document.createElement('div');
        wrap.className = 'fighter pet-sprite';
        wrap.dataset.slot = pet.slot;
        wrap.innerHTML = `
            <div class="name-tag pet-tag">${escapeHtml(pet.name)}</div>
            <div class="bar hp small"><div class="bar-fill" data-hp-bar></div></div>
            <img class="sprite ${side === 'right' ? 'flip' : ''}" src="/ArenaForge/${escapeAttr(pet.icon_path)}" alt="">
        `;
        sideBox.appendChild(wrap);
        const bar = wrap.querySelector('[data-hp-bar]');
        slots.set(pet.slot, { el: wrap, hpBar: bar, hpMax: pet.hp_max, role: 'pet' });
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }
    function escapeAttr(s) {
        return escapeHtml(s);
    }

    function slotToSide(slot) {
        if (!slot) return null;
        return slot.charAt(0) === 'L' ? 'left' : 'right';
    }

    function nameToSide(name) {
        if (name === F.n1) return 'left';
        if (name === F.n2) return 'right';
        return null;
    }

    function resolveSlot(ev, field) {
        return ev[field + '_slot'] || null;
    }

    function setHp(slot, hpVal) {
        const meta = slots.get(slot);
        if (!meta) return;
        const cur = typeof hpVal === 'number' ? hpVal : meta.hpMax;
        const pct = Math.max(0, Math.min(100, (cur / meta.hpMax) * 100));
        meta.hpBar.style.width = pct + '%';
    }

    function setHpByName(name, hpVal) {
        // Fallback pour vieux logs sans slot
        const slot = name === F.n1 ? 'L0' : (name === F.n2 ? 'R0' : null);
        if (slot) setHp(slot, hpVal);
    }

    function appendLine(cls, text) {
        const d = document.createElement('div');
        d.className = 'line ' + cls;
        d.textContent = text;
        logEl.appendChild(d);
        logEl.scrollTop = logEl.scrollHeight;
    }

    function wait(ms) { return new Promise((r) => setTimeout(r, ms)); }

    function elForSlot(slot) {
        const meta = slots.get(slot);
        return meta ? meta.el : null;
    }

    function elByNameFallback(name) {
        return name === F.n1 ? f1El : (name === F.n2 ? f2El : null);
    }

    async function doAttackAnim(slot, nameFallback) {
        const el = elForSlot(slot) || elByNameFallback(nameFallback);
        if (!el) return;
        const side = slotToSide(slot) || nameToSide(nameFallback);
        el.classList.add(side === 'left' ? 'attack-left' : 'attack-right');
        await wait(220);
        el.classList.remove('attack-left', 'attack-right');
    }

    async function doHurtAnim(slot, nameFallback, isCrit) {
        const el = elForSlot(slot) || elByNameFallback(nameFallback);
        if (!el) return;
        el.classList.add('hurt');
        if (isCrit) {
            flash.classList.add('active');
            setTimeout(() => flash.classList.remove('active'), 180);
        }
        await wait(300);
        el.classList.remove('hurt');
    }

    async function doDodgeAnim(slot, nameFallback) {
        const el = elForSlot(slot) || elByNameFallback(nameFallback);
        if (!el) return;
        const side = slotToSide(slot) || nameToSide(nameFallback);
        el.classList.add(side === 'left' ? 'dodge-left' : 'dodge-right');
        await wait(260);
        el.classList.remove('dodge-left', 'dodge-right');
    }

    async function doKOAnim(slot, nameFallback) {
        const el = elForSlot(slot) || elByNameFallback(nameFallback);
        if (!el) return;
        el.classList.add('ko');
    }

    function resetArena() {
        [f1El, f2El].forEach((el) => el.classList.remove('ko', 'hurt', 'attack-left', 'attack-right', 'dodge-left', 'dodge-right'));
        // Réinitialise les pets (efface et reconstruira si `start` event)
        petLeftBox.innerHTML = '';
        petRightBox.innerHTML = '';
        // Retire du registre les pets précédemment créés
        for (const slot of Array.from(slots.keys())) {
            if (slot !== 'L0' && slot !== 'R0') slots.delete(slot);
        }
        setHp('L0', F.hp1Max);
        setHp('R0', F.hp2Max);
    }

    const sfx = (name) => { if (window.SFX) window.SFX.play(name); };

    async function play(log) {
        logEl.innerHTML = '';
        resetArena();

        for (const ev of log) {
            switch (ev.event) {
                case 'start':
                    appendLine('start', `Début du combat : ${F.n1} contre ${F.n2} !`);
                    if (ev.teams) {
                        // Sync des PV max effectifs (armure équipée peut augmenter hp_max)
                        if (ev.teams.L && ev.teams.L.master && ev.teams.L.master.hp_max) {
                            const metaL = slots.get('L0');
                            if (metaL) { metaL.hpMax = ev.teams.L.master.hp_max; setHp('L0', metaL.hpMax); }
                        }
                        if (ev.teams.R && ev.teams.R.master && ev.teams.R.master.hp_max) {
                            const metaR = slots.get('R0');
                            if (metaR) { metaR.hpMax = ev.teams.R.master.hp_max; setHp('R0', metaR.hpMax); }
                        }
                        (ev.teams.L && ev.teams.L.pets || []).forEach((p) => buildPetSprite(p, petLeftBox, 'left'));
                        (ev.teams.R && ev.teams.R.pets || []).forEach((p) => buildPetSprite(p, petRightBox, 'right'));
                    }
                    break;

                case 'hit': {
                    const attSlot = resolveSlot(ev, 'attacker');
                    const defSlot = resolveSlot(ev, 'defender');
                    await doAttackAnim(attSlot, ev.attacker);
                    const kind = ev.crit ? 'crit' : 'hit';
                    sfx(kind);
                    appendLine(kind,
                        `T${ev.turn} • ${ev.attacker} frappe ${ev.defender} avec ${ev.weapon}` +
                        (ev.crit ? ' (CRITIQUE)' : '') +
                        ` → -${ev.damage} PV`);
                    await doHurtAnim(defSlot, ev.defender, !!ev.crit);
                    if (defSlot) setHp(defSlot, ev.def_hp); else setHpByName(ev.defender, ev.def_hp);
                    if (attSlot) setHp(attSlot, ev.att_hp); else setHpByName(ev.attacker, ev.att_hp);
                    if (ev.def_hp <= 0) {
                        sfx('ko');
                        await doKOAnim(defSlot, ev.defender);
                    }
                    await wait(260);
                    break;
                }

                case 'dodge': {
                    const defSlot = resolveSlot(ev, 'defender');
                    sfx('dodge');
                    appendLine('dodge', `T${ev.turn} • ${ev.defender} esquive ${ev.attacker} !`);
                    await doDodgeAnim(defSlot, ev.defender);
                    await wait(120);
                    break;
                }

                case 'counter':
                    sfx('counter');
                    appendLine('counter', `T${ev.turn} • ${ev.attacker} contre-attaque !`);
                    await wait(120);
                    break;

                case 'regen': {
                    const slot = ev.actor_slot || null;
                    sfx('regen');
                    appendLine('regen', `T${ev.turn} • ${ev.actor} régénère ${ev.heal} PV`);
                    if (slot) setHp(slot, ev.actor_hp); else setHpByName(ev.actor, ev.actor_hp);
                    await wait(180);
                    break;
                }

                case 'lifesteal': {
                    const slot = ev.actor_slot || null;
                    sfx('lifesteal');
                    appendLine('lifesteal', `T${ev.turn} • ${ev.actor} draine ${ev.heal} PV`);
                    if (slot) setHp(slot, ev.actor_hp); else setHpByName(ev.actor, ev.actor_hp);
                    await wait(180);
                    break;
                }

                case 'down': {
                    const slot = ev.actor_slot || null;
                    sfx('ko');
                    appendLine('down', `T${ev.turn} • ${ev.actor} est mis hors de combat !`);
                    await doKOAnim(slot, ev.actor);
                    await wait(200);
                    break;
                }

                case 'timeout':
                    sfx('timeout');
                    appendLine('end', 'Combat interrompu (trop de tours).');
                    await wait(250);
                    break;

                case 'end':
                    sfx('victory');
                    appendLine('end', `🏆 Vainqueur : ${ev.winner} !`);
                    break;
            }
        }
    }

    replayBtn.addEventListener('click', () => play(F.log));
    play(F.log);
})();
