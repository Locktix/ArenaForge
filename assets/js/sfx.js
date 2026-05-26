// ArenaForge — Librairie SFX
// Expose window.SFX : play(name), startAmbient(type), stopAmbient(),
//                     toggleMute(), setVolume(v), isMuted(), getVolume()
//
// Sons de combat : fichiers WAV préchargés en AudioBuffer, fallback synthèse.
// Ambiance       : synthèse Web Audio API (aucun fichier requis).

(function () {
    const STORAGE_KEY = 'arenaforge_sfx';
    const DEFAULTS = { muted: false, volume: 0.12, _v: 2 };

    const state = loadState();
    let ctx = null;
    let masterGain = null;

    function loadState() {
        try {
            const raw = localStorage.getItem(STORAGE_KEY);
            if (!raw) return { ...DEFAULTS };
            const saved = JSON.parse(raw);
            // Migration : ancienne valeur sans version → reset aux nouveaux défauts
            if (!saved._v || saved._v < DEFAULTS._v) return { ...DEFAULTS };
            return { ...DEFAULTS, ...saved };
        } catch (e) { return { ...DEFAULTS }; }
    }

    function saveState() {
        try { localStorage.setItem(STORAGE_KEY, JSON.stringify(state)); } catch (e) {}
    }

    function ensureCtx() {
        if (ctx) return ctx;
        const AC = window.AudioContext || window.webkitAudioContext;
        if (!AC) return null;
        ctx = new AC();
        masterGain = ctx.createGain();
        masterGain.gain.value = state.muted ? 0 : state.volume;
        masterGain.connect(ctx.destination);
        preloadAll();
        return ctx;
    }

    function resumeCtx() {
        if (ctx && ctx.state === 'suspended') ctx.resume();
    }

    // -------------------------------------------------------------------------
    // Chargement WAV (sons de combat)
    // -------------------------------------------------------------------------

    // Base URL déduite depuis le src du script — fonctionne sur n'importe quel
    // sous-dossier serveur (/ArenaForge/, /, /game/, …).
    const BASE_URL = (function () {
        const el = document.querySelector('script[src*="sfx.js"]');
        return el ? el.src.replace(/\/assets\/js\/sfx\.js(\?.*)?$/, '') : '';
    })();

    const SOUND_FILES = {
        start:       'fx/Weapons/sword_unsheath.wav',
        hit:         'fx/Combat%20and%20Gore/punch.wav',
        crit:        'fx/Combat%20and%20Gore/crunch.wav',
        dodge:       'fx/Other/whoosh_1.wav',
        counter:     'fx/Weapons/sword_clash.wav',
        regen:       'fx/Items/heart_collect.wav',
        lifesteal:   'fx/Combat%20and%20Gore/squelching_1.wav',
        ko:          'fx/Weapons/weapon_drop.wav',
        timeout:     'fx/Musical%20Effects/harpsichord_negative.wav',
        victory:     'fx/Musical%20Effects/harpsichord_level_complete.wav',
        click:       'fx/UI/click_double_on.wav',
        achievement: 'fx/Items/gem_collect.wav',
        forge:       'fx/Weapons/weapon_upgrade.wav',
    };

    const buffers = {};

    function loadSound(name, rel) {
        const url = BASE_URL + '/assets/' + rel;
        fetch(url)
            .then((r) => { if (!r.ok) throw new Error(r.status); return r.arrayBuffer(); })
            .then((ab) => ctx.decodeAudioData(ab))
            .then((buf) => { buffers[name] = buf; })
            .catch(() => {}); // silencieux : fallback synthèse
    }

    function preloadAll() {
        Object.entries(SOUND_FILES).forEach(([name, rel]) => loadSound(name, rel));
    }

    function playBuffer(name) {
        const buf = buffers[name];
        if (!buf) return false;
        const src = ctx.createBufferSource();
        src.buffer = buf;
        src.connect(masterGain);
        src.start();
        return true;
    }

    // -------------------------------------------------------------------------
    // Synthèse Web Audio — primitives
    // -------------------------------------------------------------------------

    function envelope(node, _u, attack, hold, release, peak) {
        const now = ctx.currentTime;
        const g = node.gain;
        g.cancelScheduledValues(now);
        g.setValueAtTime(0.0001, now);
        g.exponentialRampToValueAtTime(peak, now + attack);
        g.setValueAtTime(peak, now + attack + hold);
        g.exponentialRampToValueAtTime(0.0001, now + attack + hold + release);
    }

    function tone({ freq = 440, type = 'sine', duration = 0.2, peak = 0.6, attack = 0.005, hold = 0.02, slideTo = null, filterFreq = null }) {
        const osc = ctx.createOscillator();
        osc.type = type;
        osc.frequency.setValueAtTime(freq, ctx.currentTime);
        if (slideTo !== null) osc.frequency.exponentialRampToValueAtTime(slideTo, ctx.currentTime + duration);
        const gain = ctx.createGain();
        envelope(gain, null, attack, hold, duration - attack - hold, peak);
        let last = gain;
        if (filterFreq !== null) {
            const filter = ctx.createBiquadFilter();
            filter.type = 'lowpass';
            filter.frequency.value = filterFreq;
            gain.connect(filter);
            last = filter;
        }
        osc.connect(gain);
        last.connect(masterGain);
        osc.start();
        osc.stop(ctx.currentTime + duration + 0.02);
    }

    function noiseBuffer(duration = 0.2) {
        const len = Math.floor(ctx.sampleRate * duration);
        const buf = ctx.createBuffer(1, len, ctx.sampleRate);
        const data = buf.getChannelData(0);
        for (let i = 0; i < len; i++) data[i] = Math.random() * 2 - 1;
        return buf;
    }

    function noiseBurst({ duration = 0.15, peak = 0.4, filterType = 'lowpass', filterFreq = 800, attack = 0.003, release = null }) {
        const src = ctx.createBufferSource();
        src.buffer = noiseBuffer(duration);
        const filter = ctx.createBiquadFilter();
        filter.type = filterType;
        filter.frequency.value = filterFreq;
        const gain = ctx.createGain();
        envelope(gain, null, attack, 0.01, release !== null ? release : (duration - attack - 0.01), peak);
        src.connect(filter);
        filter.connect(gain);
        gain.connect(masterGain);
        src.start();
        src.stop(ctx.currentTime + duration + 0.02);
    }

    // -------------------------------------------------------------------------
    // Banque synthèse — fallback si WAV pas encore chargé
    // -------------------------------------------------------------------------

    const SOUNDS = {
        start() {
            noiseBurst({ duration: 0.25, peak: 0.3, filterType: 'bandpass', filterFreq: 3000 });
            tone({ freq: 900, type: 'triangle', duration: 0.2, peak: 0.2, slideTo: 600 });
        },
        hit() {
            tone({ freq: 180, type: 'sine', duration: 0.14, peak: 0.55, attack: 0.002, hold: 0.01, slideTo: 80 });
            noiseBurst({ duration: 0.1, peak: 0.25, filterType: 'lowpass', filterFreq: 1200 });
        },
        crit() {
            tone({ freq: 140, type: 'triangle', duration: 0.22, peak: 0.7, slideTo: 60 });
            noiseBurst({ duration: 0.2, peak: 0.4, filterType: 'bandpass', filterFreq: 2200 });
            setTimeout(() => tone({ freq: 1400, type: 'square', duration: 0.18, peak: 0.22, attack: 0.001, hold: 0.005, slideTo: 900 }), 30);
        },
        dodge() {
            const src = ctx.createBufferSource();
            src.buffer = noiseBuffer(0.3);
            const filter = ctx.createBiquadFilter();
            filter.type = 'bandpass';
            filter.frequency.setValueAtTime(400, ctx.currentTime);
            filter.frequency.exponentialRampToValueAtTime(2400, ctx.currentTime + 0.28);
            filter.Q.value = 8;
            const gain = ctx.createGain();
            envelope(gain, null, 0.01, 0.05, 0.24, 0.35);
            src.connect(filter);
            filter.connect(gain);
            gain.connect(masterGain);
            src.start();
            src.stop(ctx.currentTime + 0.32);
        },
        counter() {
            tone({ freq: 1800, type: 'square', duration: 0.12, peak: 0.25, slideTo: 1200 });
            setTimeout(() => tone({ freq: 2400, type: 'triangle', duration: 0.15, peak: 0.18, slideTo: 1600 }), 40);
        },
        regen() {
            tone({ freq: 520, type: 'sine', duration: 0.25, peak: 0.28, slideTo: 880 });
            setTimeout(() => tone({ freq: 660, type: 'sine', duration: 0.2, peak: 0.22, slideTo: 1100 }), 60);
        },
        lifesteal() {
            tone({ freq: 220, type: 'triangle', duration: 0.3, peak: 0.35, slideTo: 140 });
            setTimeout(() => tone({ freq: 440, type: 'sine', duration: 0.2, peak: 0.2, slideTo: 330 }), 80);
        },
        ko() {
            tone({ freq: 160, type: 'sine', duration: 0.4, peak: 0.6, slideTo: 40 });
            noiseBurst({ duration: 0.3, peak: 0.35, filterType: 'lowpass', filterFreq: 400 });
        },
        timeout() {
            tone({ freq: 440, type: 'sine', duration: 0.5, peak: 0.3 });
            setTimeout(() => tone({ freq: 660, type: 'sine', duration: 0.5, peak: 0.25 }), 100);
        },
        victory() {
            const notes = [523, 659, 784, 1047];
            notes.forEach((freq, i) => setTimeout(() => tone({ freq, type: 'triangle', duration: 0.25, peak: 0.4 }), i * 110));
        },
        click() {
            tone({ freq: 1200, type: 'square', duration: 0.04, peak: 0.15, attack: 0.001, hold: 0.005 });
        },
        achievement() {
            tone({ freq: 660, type: 'sine', duration: 0.15, peak: 0.35, slideTo: 880 });
            setTimeout(() => tone({ freq: 880, type: 'triangle', duration: 0.2, peak: 0.32, slideTo: 1320 }), 80);
            setTimeout(() => tone({ freq: 1320, type: 'sine', duration: 0.35, peak: 0.3, slideTo: 1760 }), 180);
        },
        forge() {
            tone({ freq: 240, type: 'square', duration: 0.08, peak: 0.5, slideTo: 120 });
            noiseBurst({ duration: 0.15, peak: 0.4, filterType: 'bandpass', filterFreq: 1800 });
            setTimeout(() => tone({ freq: 2800, type: 'triangle', duration: 0.3, peak: 0.18, slideTo: 1600 }), 50);
        },
    };

    // -------------------------------------------------------------------------
    // Système d'ambiance synthétique
    // -------------------------------------------------------------------------

    let ambientGain   = null; // GainNode dédié à l'ambiance (fade in/out)
    let ambientActive = [];   // AudioNodes en cours, à stopper proprement
    let pendingAmbient = null; // type mis en attente avant le premier clic

    // Ambiance arène : drone basse tension + murmure de foule filtré
    function _buildFightAmbient(ag) {
        const nodes = [];

        // Basse fondamentale (41 Hz)
        const osc1 = ctx.createOscillator();
        osc1.type = 'sine';
        osc1.frequency.value = 41.2;
        const g1 = ctx.createGain();
        g1.gain.value = 0.14;
        osc1.connect(g1); g1.connect(ag);
        osc1.start();
        nodes.push(osc1);

        // Harmonique (82 Hz)
        const osc2 = ctx.createOscillator();
        osc2.type = 'triangle';
        osc2.frequency.value = 82;
        const g2 = ctx.createGain();
        g2.gain.value = 0.06;
        osc2.connect(g2); g2.connect(ag);
        osc2.start();
        nodes.push(osc2);

        // Murmure de foule (bruit filtré en boucle)
        const crowdBuf = ctx.createBuffer(1, Math.floor(ctx.sampleRate * 3), ctx.sampleRate);
        const d = crowdBuf.getChannelData(0);
        for (let i = 0; i < d.length; i++) d[i] = Math.random() * 2 - 1;
        const crowd = ctx.createBufferSource();
        crowd.buffer = crowdBuf;
        crowd.loop = true;
        const filt = ctx.createBiquadFilter();
        filt.type = 'bandpass';
        filt.frequency.value = 650;
        filt.Q.value = 0.35;
        const g3 = ctx.createGain();
        g3.gain.value = 0.045;
        crowd.connect(filt); filt.connect(g3); g3.connect(ag);
        crowd.start();
        nodes.push(crowd);

        return nodes;
    }

    // Ambiance lobby : drone doux harmonique (A2 + quinte + octave)
    function _buildLobbyAmbient(ag) {
        const nodes = [];
        const freqs = [110, 165, 220];
        const vols  = [0.07, 0.035, 0.02];
        freqs.forEach((f, i) => {
            const osc = ctx.createOscillator();
            osc.type = 'sine';
            osc.frequency.value = f;
            const g = ctx.createGain();
            g.gain.value = vols[i];
            osc.connect(g); g.connect(ag);
            osc.start();
            nodes.push(osc);
        });
        return nodes;
    }

    function _launchAmbient(type) {
        pendingAmbient = null;
        const ag = ctx.createGain();
        ag.gain.setValueAtTime(0, ctx.currentTime);
        ag.gain.linearRampToValueAtTime(1, ctx.currentTime + 2.5); // fondu 2.5 s
        ag.connect(masterGain);
        ambientGain   = ag;
        ambientActive = type === 'fight' ? _buildFightAmbient(ag) : _buildLobbyAmbient(ag);
    }

    function stopAmbient() {
        pendingAmbient = null;
        if (!ambientGain || !ctx) return;
        const ag    = ambientGain;
        const nodes = ambientActive.splice(0);
        ambientGain = null;
        const now = ctx.currentTime;
        ag.gain.cancelScheduledValues(now);
        ag.gain.setValueAtTime(ag.gain.value, now);
        ag.gain.linearRampToValueAtTime(0, now + 0.6);
        setTimeout(() => {
            nodes.forEach((n) => { try { n.stop(); } catch (e) {} });
            try { ag.disconnect(); } catch (e) {}
        }, 800);
    }

    function startAmbient(type) {
        stopAmbient();
        if (!ensureCtx()) return;
        if (ctx.state === 'suspended') { pendingAmbient = type; return; }
        _launchAmbient(type);
    }

    // -------------------------------------------------------------------------
    // API publique
    // -------------------------------------------------------------------------

    function play(name) {
        if (state.muted) return;
        if (!ensureCtx()) return;
        resumeCtx();
        if (playBuffer(name)) return;
        const fn = SOUNDS[name];
        if (fn) try { fn(); } catch (e) {}
    }

    function setVolume(v) {
        state.volume = Math.max(0, Math.min(1, v));
        saveState();
        if (masterGain && !state.muted) masterGain.gain.value = state.volume;
    }

    function toggleMute() {
        state.muted = !state.muted;
        saveState();
        if (masterGain) masterGain.gain.value = state.muted ? 0 : state.volume;
        updateButton();
        return state.muted;
    }

    function isMuted()  { return state.muted; }
    function getVolume() { return state.volume; }

    // -------------------------------------------------------------------------
    // Bannière "activer le son"
    // -------------------------------------------------------------------------

    function showAudioHint() {
        if (state.muted) return;
        const hint = document.createElement('div');
        hint.id = 'sfx-hint';
        hint.className = 'sfx-hint';
        hint.textContent = '🔊 Cliquer pour activer le son';
        document.body.appendChild(hint);
        // double rAF pour déclencher la transition CSS
        requestAnimationFrame(() => requestAnimationFrame(() => hint.classList.add('sfx-hint--in')));

        const dismiss = () => {
            hint.classList.remove('sfx-hint--in');
            hint.classList.add('sfx-hint--out');
            setTimeout(() => { if (hint.parentNode) hint.remove(); }, 350);
        };
        document.addEventListener('click', dismiss, { once: true });
        setTimeout(dismiss, 6000); // auto-dismiss après 6 s
    }

    // -------------------------------------------------------------------------
    // Bouton mute flottant
    // -------------------------------------------------------------------------

    function mountButton() {
        if (document.getElementById('sfx-toggle')) return;
        const btn = document.createElement('button');
        btn.id = 'sfx-toggle';
        btn.type = 'button';
        btn.className = 'sfx-toggle';
        btn.setAttribute('aria-label', 'Activer / désactiver les sons');
        btn.addEventListener('click', () => {
            ensureCtx();
            resumeCtx();
            toggleMute();
            if (!state.muted) play('click');
        });
        document.body.appendChild(btn);
        updateButton();

        // Indice visuel "cliquer pour activer"
        showAudioHint();

        // Ambiance synthétique uniquement sur la page de combat.
        // Les autres pages sont gérées par music.js (vraie musique).
        const isFight = document.body.classList.contains('fight-page');
        pendingAmbient = isFight ? 'fight' : null;
    }

    function updateButton() {
        const btn = document.getElementById('sfx-toggle');
        if (!btn) return;
        btn.classList.toggle('muted', state.muted);
        btn.innerHTML = state.muted
            ? '<svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor"><path d="M3 9v6h4l5 5V4L7 9H3zm13.59 3L20 8.41 18.59 7 15 10.59 11.41 7 10 8.41 13.59 12 10 15.59 11.41 17 15 13.41 18.59 17 20 15.59 16.41 12z"/></svg>'
            : '<svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor"><path d="M3 9v6h4l5 5V4L7 9H3zm13.5 3A4.5 4.5 0 0 0 14 7.97v8.05c1.48-.73 2.5-2.25 2.5-4.02zM14 3.23v2.06c2.89.86 5 3.54 5 6.71s-2.11 5.85-5 6.71v2.06c4.01-.91 7-4.49 7-8.77s-2.99-7.86-7-8.77z"/></svg>';
    }

    // -------------------------------------------------------------------------
    // Init
    // -------------------------------------------------------------------------

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', mountButton);
    } else {
        mountButton();
    }

    // Premier clic : déverrouille l'AudioContext ET lance l'ambiance en attente
    document.addEventListener('click', () => {
        ensureCtx();
        if (ctx) {
            ctx.resume().then(() => {
                if (pendingAmbient) _launchAmbient(pendingAmbient);
            });
        }
    }, { once: true });

    window.SFX = { play, startAmbient, stopAmbient, toggleMute, setVolume, isMuted, getVolume };
})();
