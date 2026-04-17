// Replay animé du combat à partir du log JSON stocké côté serveur

(function () {
    const F = window.FIGHT;
    if (!F) return;

    const arena   = document.getElementById('arena');
    const f1El    = document.getElementById('fighter1');
    const f2El    = document.getElementById('fighter2');
    const hp1Bar  = f1El.querySelector('[data-hp-bar]');
    const hp2Bar  = f2El.querySelector('[data-hp-bar]');
    const flash   = document.getElementById('flash');
    const logEl   = document.getElementById('combat-log');
    const replayBtn = document.getElementById('replay-btn');

    // Résolution du "côté" d'un nom de combattant
    function side(name) {
        return name === F.n1 ? 'left' : 'right';
    }

    function setHp(name, hpVal, hpMax) {
        const bar = name === F.n1 ? hp1Bar : hp2Bar;
        const max = name === F.n1 ? F.hp1Max : F.hp2Max;
        const cur = typeof hpVal === 'number' ? hpVal : max;
        const pct = Math.max(0, Math.min(100, (cur / max) * 100));
        bar.style.width = pct + '%';
    }

    function appendLine(cls, text) {
        const d = document.createElement('div');
        d.className = 'line ' + cls;
        d.textContent = text;
        logEl.appendChild(d);
        logEl.scrollTop = logEl.scrollHeight;
    }

    function wait(ms) {
        return new Promise((r) => setTimeout(r, ms));
    }

    async function doAttackAnim(attackerName) {
        const s = side(attackerName);
        const el = s === 'left' ? f1El : f2El;
        el.classList.add(s === 'left' ? 'attack-left' : 'attack-right');
        await wait(220);
        el.classList.remove('attack-left', 'attack-right');
    }

    async function doHurtAnim(defenderName, isCrit) {
        const s = side(defenderName);
        const el = s === 'left' ? f1El : f2El;
        el.classList.add('hurt');
        if (isCrit) {
            flash.classList.add('active');
            setTimeout(() => flash.classList.remove('active'), 180);
        }
        await wait(300);
        el.classList.remove('hurt');
    }

    async function doDodgeAnim(defenderName) {
        const s = side(defenderName);
        const el = s === 'left' ? f1El : f2El;
        el.classList.add(s === 'left' ? 'dodge-left' : 'dodge-right');
        await wait(260);
        el.classList.remove('dodge-left', 'dodge-right');
    }

    async function doKOAnim(name) {
        const s = side(name);
        const el = s === 'left' ? f1El : f2El;
        el.classList.add('ko');
    }

    async function play(log) {
        logEl.innerHTML = '';
        // Reset sprites
        f1El.classList.remove('ko', 'hurt', 'attack-left', 'attack-right', 'dodge-left', 'dodge-right');
        f2El.classList.remove('ko', 'hurt', 'attack-left', 'attack-right', 'dodge-left', 'dodge-right');
        setHp(F.n1, F.hp1Max);
        setHp(F.n2, F.hp2Max);

        for (const ev of log) {
            switch (ev.event) {
                case 'start':
                    appendLine('start', `Début du combat : ${F.n1} contre ${F.n2} !`);
                    break;
                case 'hit':
                    await doAttackAnim(ev.attacker);
                    appendLine(ev.crit ? 'crit' : 'hit',
                        `T${ev.turn} • ${ev.attacker} frappe ${ev.defender} avec ${ev.weapon}` +
                        (ev.crit ? ' (CRITIQUE)' : '') +
                        ` → -${ev.damage} PV`);
                    await doHurtAnim(ev.defender, !!ev.crit);
                    setHp(ev.defender, ev.def_hp);
                    setHp(ev.attacker, ev.att_hp);
                    if (ev.def_hp <= 0) {
                        await doKOAnim(ev.defender);
                    }
                    await wait(260);
                    break;
                case 'dodge':
                    appendLine('dodge', `T${ev.turn} • ${ev.defender} esquive ${ev.attacker} !`);
                    await doDodgeAnim(ev.defender);
                    await wait(120);
                    break;
                case 'counter':
                    appendLine('counter', `T${ev.turn} • ${ev.attacker} contre-attaque !`);
                    await wait(120);
                    break;
                case 'regen':
                    appendLine('regen', `T${ev.turn} • ${ev.actor} régénère ${ev.heal} PV`);
                    setHp(ev.actor, ev.actor_hp);
                    await wait(180);
                    break;
                case 'lifesteal':
                    appendLine('lifesteal', `T${ev.turn} • ${ev.actor} draine ${ev.heal} PV`);
                    setHp(ev.actor, ev.actor_hp);
                    await wait(180);
                    break;
                case 'timeout':
                    appendLine('end', 'Combat interrompu (trop de tours).');
                    await wait(250);
                    break;
                case 'end':
                    appendLine('end', `🏆 Vainqueur : ${ev.winner} !`);
                    break;
            }
        }
    }

    replayBtn.addEventListener('click', () => play(F.log));
    play(F.log);
})();
