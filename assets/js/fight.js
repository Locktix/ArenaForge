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
    const skipBtn   = document.getElementById('skip-btn');
    const speedBtns = Array.from(document.querySelectorAll('[data-speed]'));
    const petLeftBox  = document.getElementById('pet-left');
    const petRightBox = document.getElementById('pet-right');

    // Vitesse de replay : 1 = normal, 0.5 = ralenti, 2/4 = accéléré
    let speedMul = 1;
    let skipping = false;

    // Map slot -> { el, hpBar, hpMax, statusEl }
    const slots = new Map();

    function setActiveSpeed(btn) {
        speedBtns.forEach((b) => b.classList.toggle('active', b === btn));
    }

    function applySpeed(mul) {
        speedMul = Math.max(0.25, mul);
    }

    speedBtns.forEach((btn) => {
        btn.addEventListener('click', () => {
            const v = parseFloat(btn.dataset.speed);
            if (Number.isFinite(v)) {
                applySpeed(v);
                setActiveSpeed(btn);
            }
        });
    });

    function ensureStatusBar(el) {
        let bar = el.querySelector('[data-statuses]');
        if (!bar) {
            bar = document.createElement('div');
            bar.className = 'status-bar';
            bar.dataset.statuses = '1';
            el.appendChild(bar);
        }
        return bar;
    }

    function registerMaster(slot, el, hpMax) {
        const bar = el.querySelector('[data-hp-bar]');
        slots.set(slot, { el, hpBar: bar, hpMax, role: 'master', statuses: {} });
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
            <img class="sprite ${side === 'right' ? 'flip' : ''}" src="../${escapeAttr(pet.icon_path)}" alt="">
        `;
        sideBox.appendChild(wrap);
        const bar = wrap.querySelector('[data-hp-bar]');
        slots.set(pet.slot, { el: wrap, hpBar: bar, hpMax: pet.hp_max, role: 'pet', statuses: {} });
    }

    function buildPartnerSprite(partner, sideBox, side) {
        const wrap = document.createElement('div');
        wrap.className = 'fighter partner-sprite';
        wrap.dataset.slot = partner.slot;
        wrap.innerHTML = `
            <div class="name-tag partner-tag">${escapeHtml(partner.name)} <small>Niv. ${parseInt(partner.level || 1, 10)}</small></div>
            <div class="bar hp small"><div class="bar-fill" data-hp-bar></div></div>
            <img class="sprite partner-icon ${side === 'right' ? 'flip' : ''}" src="../assets/svg/ui/nav_pupils.svg" alt="">
        `;
        sideBox.appendChild(wrap);
        const bar = wrap.querySelector('[data-hp-bar]');
        slots.set(partner.slot, { el: wrap, hpBar: bar, hpMax: partner.hp_max, role: 'partner', statuses: {} });
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
        const slot = name === F.n1 ? 'L0' : (name === F.n2 ? 'R0' : null);
        if (slot) setHp(slot, hpVal);
    }

    const STATUS_LABELS = {
        bleed:  { label: 'Saignement', emoji: '🩸', cls: 'st-bleed' },
        poison: { label: 'Poison',     emoji: '🧪', cls: 'st-poison' },
        stun:   { label: 'Étourdi',    emoji: '⭐', cls: 'st-stun' },
    };

    function refreshStatusBadges(slot) {
        const meta = slots.get(slot);
        if (!meta) return;
        const bar = ensureStatusBar(meta.el);
        bar.innerHTML = '';
        Object.keys(meta.statuses).forEach((type) => {
            if (!meta.statuses[type]) return;
            const def = STATUS_LABELS[type];
            if (!def) return;
            const span = document.createElement('span');
            span.className = 'status-badge ' + def.cls;
            span.title = def.label + (meta.statuses[type].turns ? ' (' + meta.statuses[type].turns + 't)' : '');
            span.textContent = def.emoji;
            bar.appendChild(span);
        });
    }

    function setStatus(slot, type, turns) {
        const meta = slots.get(slot);
        if (!meta) return;
        meta.statuses[type] = { turns };
        refreshStatusBadges(slot);
    }

    function clearStatus(slot, type) {
        const meta = slots.get(slot);
        if (!meta) return;
        delete meta.statuses[type];
        refreshStatusBadges(slot);
    }

    function appendLine(cls, text) {
        const d = document.createElement('div');
        d.className = 'line ' + cls;
        d.textContent = text;
        logEl.appendChild(d);
        logEl.scrollTop = logEl.scrollHeight;
    }

    function wait(ms) {
        if (skipping) return Promise.resolve();
        return new Promise((r) => setTimeout(r, ms / speedMul));
    }

    function elForSlot(slot) {
        const meta = slots.get(slot);
        return meta ? meta.el : null;
    }

    function elByNameFallback(name) {
        return name === F.n1 ? f1El : (name === F.n2 ? f2El : null);
    }

    async function doAttackAnim(slot, nameFallback) {
        if (skipping) return;
        const el = elForSlot(slot) || elByNameFallback(nameFallback);
        if (!el) return;
        const side = slotToSide(slot) || nameToSide(nameFallback);
        el.classList.add(side === 'left' ? 'attack-left' : 'attack-right');
        await wait(220);
        el.classList.remove('attack-left', 'attack-right');
    }

    async function doHurtAnim(slot, nameFallback, isCrit) {
        if (skipping) return;
        const el = elForSlot(slot) || elByNameFallback(nameFallback);
        if (!el) return;
        el.classList.add('hurt');
        if (isCrit) {
            flash.classList.add('active');
            setTimeout(() => flash.classList.remove('active'), 180 / speedMul);
        }
        await wait(300);
        el.classList.remove('hurt');
    }

    async function doDodgeAnim(slot, nameFallback) {
        if (skipping) return;
        const el = elForSlot(slot) || elByNameFallback(nameFallback);
        if (!el) return;
        const side = slotToSide(slot) || nameToSide(nameFallback);
        el.classList.add(side === 'left' ? 'dodge-left' : 'dodge-right');
        await wait(260);
        el.classList.remove('dodge-left', 'dodge-right');
    }

    async function doStatusFlash(slot, type) {
        if (skipping) return;
        const el = elForSlot(slot);
        if (!el) return;
        const cls = 'status-flash-' + type;
        el.classList.add(cls);
        await wait(220);
        el.classList.remove(cls);
    }

    async function doKOAnim(slot, nameFallback) {
        const el = elForSlot(slot) || elByNameFallback(nameFallback);
        if (!el) return;
        el.classList.add('ko');
    }

    function resetArena() {
        [f1El, f2El].forEach((el) => el.classList.remove('ko', 'hurt', 'attack-left', 'attack-right', 'dodge-left', 'dodge-right', 'status-flash-bleed', 'status-flash-poison', 'status-flash-stun'));
        petLeftBox.innerHTML = '';
        petRightBox.innerHTML = '';
        for (const slot of Array.from(slots.keys())) {
            if (slot !== 'L0' && slot !== 'R0') slots.delete(slot);
        }
        // Réinitialiser les méta des deux maîtres
        ['L0', 'R0'].forEach((s) => {
            const m = slots.get(s);
            if (m) {
                m.statuses = {};
                refreshStatusBadges(s);
            }
        });
        setHp('L0', F.hp1Max);
        setHp('R0', F.hp2Max);
    }

    const sfx = (name) => { if (window.SFX && !skipping) window.SFX.play(name); };

    let playToken = 0;

    async function play(log) {
        const myToken = ++playToken;
        skipping = false;
        if (skipBtn) skipBtn.disabled = false;
        logEl.innerHTML = '';
        resetArena();

        for (const ev of log) {
            if (myToken !== playToken) return;
            switch (ev.event) {
                case 'start':
                    appendLine('start', `Début du combat : ${F.n1} contre ${F.n2} !`);
                    if (ev.weather) {
                        appendLine('weather', `${ev.weather.icon} ${ev.weather.label} — ${ev.weather.desc}`);
                        const banner = document.getElementById('weather-banner');
                        if (banner) {
                            banner.innerHTML = `<span class="weather-icon">${ev.weather.icon}</span> ${escapeHtml(ev.weather.label)}`;
                            banner.dataset.weather = ev.weather.code;
                            banner.style.display = 'inline-flex';
                        }
                    }
                    if (ev.teams) {
                        if (ev.teams.L && ev.teams.L.master && ev.teams.L.master.hp_max) {
                            const metaL = slots.get('L0');
                            if (metaL) { metaL.hpMax = ev.teams.L.master.hp_max; setHp('L0', metaL.hpMax); }
                        }
                        if (ev.teams.R && ev.teams.R.master && ev.teams.R.master.hp_max) {
                            const metaR = slots.get('R0');
                            if (metaR) { metaR.hpMax = ev.teams.R.master.hp_max; setHp('R0', metaR.hpMax); }
                        }
                        if (ev.teams.L && ev.teams.L.partner) buildPartnerSprite(ev.teams.L.partner, petLeftBox, 'left');
                        if (ev.teams.R && ev.teams.R.partner) buildPartnerSprite(ev.teams.R.partner, petRightBox, 'right');
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

                case 'status_apply': {
                    const slot = ev.target_slot || null;
                    const def = STATUS_LABELS[ev.status_type];
                    if (def && slot) {
                        setStatus(slot, ev.status_type, ev.duration);
                        await doStatusFlash(slot, ev.status_type);
                    }
                    appendLine('status', `T${ev.turn} • ${ev.target} subit ${def ? def.label : ev.status_type} (${ev.duration}t)`);
                    await wait(140);
                    break;
                }

                case 'status_tick': {
                    const slot = ev.target_slot || null;
                    const def  = STATUS_LABELS[ev.status_type];
                    sfx('hit');
                    appendLine('status', `T${ev.turn} • ${def ? def.emoji : '•'} ${ev.target} perd ${ev.damage} PV (${def ? def.label : ev.status_type})`);
                    if (slot) {
                        setHp(slot, ev.target_hp);
                        await doStatusFlash(slot, ev.status_type);
                        if (ev.target_hp <= 0) {
                            await doKOAnim(slot, ev.target);
                        }
                    }
                    await wait(180);
                    break;
                }

                case 'status_skip': {
                    const slot = ev.actor_slot || null;
                    appendLine('status', `T${ev.turn} • ${ev.actor} est étourdi et saute son tour`);
                    if (slot) await doStatusFlash(slot, ev.status_type || 'stun');
                    await wait(160);
                    break;
                }

                case 'status_expire': {
                    const slot = ev.target_slot || null;
                    if (slot) clearStatus(slot, ev.status_type);
                    break;
                }

                case 'ult_trigger': {
                    const slot = ev.actor_slot || null;
                    sfx('counter');
                    appendLine('ult', `T${ev.turn} • ⚡ ${ev.actor} déclenche ${ev.skill_name} !`);
                    if (slot) {
                        const el = elForSlot(slot);
                        if (el) {
                            el.classList.add('ult-flash');
                            await wait(280);
                            el.classList.remove('ult-flash');
                        }
                    }
                    if (ev.actor_hp != null && slot) setHp(slot, ev.actor_hp);
                    await wait(120);
                    break;
                }

                case 'weather_tick': {
                    const slot = ev.target_slot || null;
                    appendLine('weather', `T${ev.turn} • 🔥 ${ev.target} subit ${ev.damage} PV (chaleur)`);
                    if (slot) {
                        setHp(slot, ev.target_hp);
                        await doStatusFlash(slot, 'bleed');
                        if (ev.target_hp <= 0) await doKOAnim(slot, ev.target);
                    }
                    await wait(160);
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
        if (skipBtn) skipBtn.disabled = true;
    }

    if (replayBtn) {
        replayBtn.addEventListener('click', () => play(F.log));
    }
    if (skipBtn) {
        skipBtn.addEventListener('click', () => {
            skipping = true;
        });
    }

    play(F.log);
})();
