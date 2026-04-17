// ArenaForge — Librairie SFX (Web Audio API, sons 100% synthétisés)
// Expose window.SFX : play(name), toggleMute(), setVolume(v), isMuted()

(function () {
    const STORAGE_KEY = 'arenaforge_sfx';
    const DEFAULTS = { muted: false, volume: 0.5 };

    const state = loadState();
    let ctx = null;
    let masterGain = null;

    function loadState() {
        try {
            const raw = localStorage.getItem(STORAGE_KEY);
            if (!raw) return { ...DEFAULTS };
            const parsed = JSON.parse(raw);
            return { ...DEFAULTS, ...parsed };
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
        return ctx;
    }

    // Reprise du contexte suspendu (exigence navigateurs modernes)
    function resumeCtx() {
        if (ctx && ctx.state === 'suspended') ctx.resume();
    }

    // ----- Primitives -----
    function envelope(node, gain, attack, hold, release, peak) {
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
        if (slideTo !== null) {
            osc.frequency.exponentialRampToValueAtTime(slideTo, ctx.currentTime + duration);
        }
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

    // ----- Banque de sons -----
    const SOUNDS = {
        // Impact sourd (coup classique)
        hit() {
            tone({ freq: 180, type: 'sine', duration: 0.14, peak: 0.55, attack: 0.002, hold: 0.01, slideTo: 80 });
            noiseBurst({ duration: 0.1, peak: 0.25, filterType: 'lowpass', filterFreq: 1200 });
        },
        // Critique : impact plus lourd + clang métallique + flash
        crit() {
            tone({ freq: 140, type: 'triangle', duration: 0.22, peak: 0.7, slideTo: 60 });
            noiseBurst({ duration: 0.2, peak: 0.4, filterType: 'bandpass', filterFreq: 2200 });
            setTimeout(() => {
                tone({ freq: 1400, type: 'square', duration: 0.18, peak: 0.22, attack: 0.001, hold: 0.005, slideTo: 900 });
            }, 30);
        },
        // Esquive : whoosh filtré
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
        // Contre-attaque : tintement métallique
        counter() {
            tone({ freq: 1800, type: 'square', duration: 0.12, peak: 0.25, slideTo: 1200 });
            setTimeout(() => tone({ freq: 2400, type: 'triangle', duration: 0.15, peak: 0.18, slideTo: 1600 }), 40);
        },
        // Régénération : scintillement ascendant
        regen() {
            tone({ freq: 520, type: 'sine', duration: 0.25, peak: 0.28, slideTo: 880 });
            setTimeout(() => tone({ freq: 660, type: 'sine', duration: 0.2, peak: 0.22, slideTo: 1100 }), 60);
        },
        // Vol de vie : pulsation sombre
        lifesteal() {
            tone({ freq: 220, type: 'triangle', duration: 0.3, peak: 0.35, slideTo: 140 });
            setTimeout(() => tone({ freq: 440, type: 'sine', duration: 0.2, peak: 0.2, slideTo: 330 }), 80);
        },
        // Mise KO : thud grave + descente
        ko() {
            tone({ freq: 160, type: 'sine', duration: 0.4, peak: 0.6, slideTo: 40 });
            noiseBurst({ duration: 0.3, peak: 0.35, filterType: 'lowpass', filterFreq: 400 });
        },
        // Timeout : cloche douce
        timeout() {
            tone({ freq: 440, type: 'sine', duration: 0.5, peak: 0.3 });
            setTimeout(() => tone({ freq: 660, type: 'sine', duration: 0.5, peak: 0.25 }), 100);
        },
        // Fin / victoire : fanfare ascendante
        victory() {
            const notes = [523, 659, 784, 1047]; // Do Mi Sol Do
            notes.forEach((freq, i) => {
                setTimeout(() => {
                    tone({ freq, type: 'triangle', duration: 0.25, peak: 0.4 });
                }, i * 110);
            });
        },
        // Click UI
        click() {
            tone({ freq: 1200, type: 'square', duration: 0.04, peak: 0.15, attack: 0.001, hold: 0.005 });
        },
        // Succès débloqué : glissando cristallin
        achievement() {
            tone({ freq: 660, type: 'sine', duration: 0.15, peak: 0.35, slideTo: 880 });
            setTimeout(() => tone({ freq: 880, type: 'triangle', duration: 0.2, peak: 0.32, slideTo: 1320 }), 80);
            setTimeout(() => tone({ freq: 1320, type: 'sine', duration: 0.35, peak: 0.3, slideTo: 1760 }), 180);
        },
        // Forge : marteau sur enclume
        forge() {
            tone({ freq: 240, type: 'square', duration: 0.08, peak: 0.5, slideTo: 120 });
            noiseBurst({ duration: 0.15, peak: 0.4, filterType: 'bandpass', filterFreq: 1800 });
            setTimeout(() => {
                tone({ freq: 2800, type: 'triangle', duration: 0.3, peak: 0.18, slideTo: 1600 });
            }, 50);
        }
    };

    // ----- API publique -----
    function play(name) {
        if (state.muted) return;
        if (!ensureCtx()) return;
        resumeCtx();
        const fn = SOUNDS[name];
        if (!fn) return;
        try { fn(); } catch (e) { /* silencieux : ne jamais casser l'UI */ }
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

    function isMuted() { return state.muted; }
    function getVolume() { return state.volume; }

    // ----- Bouton son flottant -----
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
    }

    function updateButton() {
        const btn = document.getElementById('sfx-toggle');
        if (!btn) return;
        btn.classList.toggle('muted', state.muted);
        btn.innerHTML = state.muted
            ? '<svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor"><path d="M3 9v6h4l5 5V4L7 9H3zm13.59 3L20 8.41 18.59 7 15 10.59 11.41 7 10 8.41 13.59 12 10 15.59 11.41 17 15 13.41 18.59 17 20 15.59 16.41 12z"/></svg>'
            : '<svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor"><path d="M3 9v6h4l5 5V4L7 9H3zm13.5 3A4.5 4.5 0 0 0 14 7.97v8.05c1.48-.73 2.5-2.25 2.5-4.02zM14 3.23v2.06c2.89.86 5 3.54 5 6.71s-2.11 5.85-5 6.71v2.06c4.01-.91 7-4.49 7-8.77s-2.99-7.86-7-8.77z"/></svg>';
    }

    // Init
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', mountButton);
    } else {
        mountButton();
    }

    // Unlock context on first click anywhere (au cas où le bouton n'est pas cliqué)
    document.addEventListener('click', () => { ensureCtx(); resumeCtx(); }, { once: true });

    window.SFX = { play, toggleMute, setVolume, isMuted, getVolume };
})();
