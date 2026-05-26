// ArenaForge — Musique de fond
// Expose window.MUSIC : play(category), stinger(n), stop(), toggleMute(),
//                       setVolume(v), isMuted(), getVolume()
//
// Pages lobby/menus  → Background.mp3 (boucle infinie)
// Pages fight-page   → Action 1-5 (aléatoire, enchaînement)
// Pages boss-page    → Dark Ambient 1-5 (aléatoire, enchaînement)
//
// Persistance entre pages :
//   - La position exacte (piste + timestamp) est sauvegardée en sessionStorage
//     avant chaque navigation, et restaurée au chargement suivant.
//   - Autoplay direct si consentement accordé (Chrome/Brave/Edge).
//   - Firefox : reprend sur mousedown / keydown / touchstart (première interaction).

(function () {
    const STORAGE_KEY = 'arenaforge_music';
    const CONSENT_KEY = 'arenaforge_audio_ok';
    const SESSION_KEY = 'arenaforge_music_pos';
    const DEFAULTS    = { muted: false, volume: 0.18 };

    const state = loadState();
    let audio      = null;
    let currentCat = null;
    let pendingCat = null;
    let pendingSeek = 0;       // position à restaurer si autoplay échoue
    let pendingTrack = null;   // piste exacte à restaurer

    function loadState() {
        try {
            const raw = localStorage.getItem(STORAGE_KEY);
            return raw ? { ...DEFAULTS, ...JSON.parse(raw) } : { ...DEFAULTS };
        } catch (e) { return { ...DEFAULTS }; }
    }
    function saveState() {
        try { localStorage.setItem(STORAGE_KEY, JSON.stringify(state)); } catch (e) {}
    }
    function _hasConsent() {
        try { return !!localStorage.getItem(CONSENT_KEY); } catch (e) { return false; }
    }
    function _grantConsent() {
        try { localStorage.setItem(CONSENT_KEY, '1'); } catch (e) {}
    }

    const BASE_URL = (function () {
        const el = document.querySelector('script[src*="music.js"]');
        return el ? el.src.replace(/\/assets\/js\/music\.js(\?.*)?$/, '') : '';
    })();

    // -------------------------------------------------------------------------
    // Sauvegarde / restauration de position (sessionStorage)
    // -------------------------------------------------------------------------

    function _savePosition() {
        if (!audio || !currentCat) return;
        try {
            sessionStorage.setItem(SESSION_KEY, JSON.stringify({
                cat:   currentCat,
                track: audio._trackName || null,
                time:  audio.currentTime || 0,
                ts:    Date.now(),
            }));
        } catch (e) {}
    }

    function _loadSavedPosition() {
        try {
            const raw = sessionStorage.getItem(SESSION_KEY);
            if (!raw) return null;
            const s = JSON.parse(raw);
            // Ignoré si vieux de plus d'1 heure (session fermée entre-temps)
            if (Date.now() - s.ts > 3_600_000) return null;
            return s;
        } catch (e) { return null; }
    }

    // Enregistré sur pagehide (navigation, fermeture onglet) et visibilitychange
    window.addEventListener('pagehide', _savePosition);
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) _savePosition();
    });

    // -------------------------------------------------------------------------
    // Catalogue
    // -------------------------------------------------------------------------

    const TRACKS = {
        action: [1,2,3,4,5].map(n => `Action ${n}.mp3`),
        dark:   [1,2,3,4,5].map(n => `Dark Ambient ${n}.mp3`),
        light:  [1,2,3,4,5].map(n => `Light Ambience ${n}.mp3`),
        ambient:[1,2,3,4,5,6,7,8,9,10].map(n => `Ambient ${n}.mp3`),
        // 'lobby' → Background.mp3 (voir _buildAudio)
    };

    function _pickRandom(arr, exclude) {
        const pool = arr.length > 1 ? arr.filter(t => t !== exclude) : arr;
        return pool[Math.floor(Math.random() * pool.length)];
    }

    function _autoCategory() {
        const cls = document.body.classList;
        if (cls.contains('fight-page')) return 'action';
        if (cls.contains('boss-page'))  return 'dark';
        return 'lobby';
    }

    // Construit l'<audio> pour la catégorie donnée.
    // forcedTrack permet de restaurer la piste exacte d'une session précédente.
    function _buildAudio(category, forcedTrack, prevTrack) {
        if (category === 'lobby') {
            const el = new Audio(BASE_URL + '/assets/Background.mp3');
            return { el, trackName: 'Background.mp3', loop: true };
        }
        const list = TRACKS[category] || TRACKS.ambient;
        const file = forcedTrack || _pickRandom(list, prevTrack);
        const el   = new Audio(BASE_URL + '/assets/music/mp3/' + encodeURIComponent(file));
        return { el, trackName: file, loop: false };
    }

    // -------------------------------------------------------------------------
    // Lecture
    // -------------------------------------------------------------------------

    function _load(category, seekTo, forcedTrack) {
        seekTo = seekTo || 0;
        pendingCat   = null;
        pendingSeek  = 0;
        pendingTrack = null;
        currentCat   = category;

        const prev = audio ? audio._trackName : null;
        if (audio) { audio.pause(); audio.src = ''; }

        const { el, trackName, loop } = _buildAudio(category, forcedTrack || null, prev);
        el._trackName = trackName;
        el.volume     = state.muted ? 0 : state.volume;
        el.loop       = loop;

        // Seek après chargement des métadonnées
        if (seekTo > 0) {
            el.addEventListener('loadedmetadata', () => {
                if (el.duration && seekTo < el.duration - 1) {
                    el.currentTime = seekTo;
                }
            }, { once: true });
        }

        if (!loop) {
            el.addEventListener('ended', () => {
                if (!state.muted) _load(category);
            }, { once: true });
        }

        el.play()
            .then(() => _grantConsent())
            .catch(() => {
                // Autoplay bloqué : mise en attente jusqu'à la prochaine interaction
                audio      = null;
                pendingCat   = category;
                pendingSeek  = seekTo;
                pendingTrack = forcedTrack || null;
            });

        audio = el;
    }

    function play(category) { _load(category || _autoCategory()); }

    // -------------------------------------------------------------------------
    // Stinger one-shot bass (Fx 1/2/3)
    // -------------------------------------------------------------------------

    function stinger(n) {
        if (state.muted) return;
        const idx = Math.min(3, Math.max(1, n || 1));
        const a   = new Audio(BASE_URL + '/assets/music/mp3/' + encodeURIComponent(`Fx ${idx}.mp3`));
        a.volume  = Math.min(1, state.volume * 1.4);
        a.play().catch(() => {});
    }

    // -------------------------------------------------------------------------
    // Arrêt avec fondu
    // -------------------------------------------------------------------------

    function stop() {
        pendingCat = null;
        currentCat = null;
        if (!audio) return;
        const a    = audio;
        audio      = null;
        let v      = a.volume;
        const step = Math.max(0.005, v / 10);
        const iv   = setInterval(() => {
            v = Math.max(0, v - step);
            a.volume = v;
            if (v <= 0) { clearInterval(iv); a.pause(); a.src = ''; }
        }, 50);
    }

    // -------------------------------------------------------------------------
    // Volume / mute
    // -------------------------------------------------------------------------

    function setVolume(v) {
        state.volume = Math.max(0, Math.min(1, v));
        saveState();
        if (audio && !state.muted) audio.volume = state.volume;
    }

    function toggleMute() {
        state.muted = !state.muted;
        saveState();
        if (state.muted) {
            if (audio) audio.volume = 0;
        } else {
            if (audio) {
                audio.volume = state.volume;
            } else {
                _load(currentCat || _autoCategory());
            }
        }
        _updateButton();
        return state.muted;
    }

    function isMuted()   { return state.muted; }
    function getVolume() { return state.volume; }

    // -------------------------------------------------------------------------
    // Bouton flottant
    // -------------------------------------------------------------------------

    const ICON_ON  = `<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M12 3v10.55A4 4 0 1 0 14 17V7h4V3h-6z"/></svg>`;
    const ICON_OFF = `<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M12 3v10.55A4 4 0 1 0 14 17V7h4V3h-6z" opacity=".35"/><line x1="3" y1="3" x2="21" y2="21" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg>`;

    function _updateButton() {
        const btn = document.getElementById('music-toggle');
        if (!btn) return;
        btn.classList.toggle('muted', state.muted);
        btn.innerHTML = state.muted ? ICON_OFF : ICON_ON;
    }

    function _mountButton() {
        if (document.getElementById('music-toggle')) return;
        const btn = document.createElement('button');
        btn.id        = 'music-toggle';
        btn.type      = 'button';
        btn.className = 'music-toggle';
        btn.setAttribute('aria-label', 'Activer / désactiver la musique');
        btn.addEventListener('click', () => toggleMute());
        document.body.appendChild(btn);
        _updateButton();

        if (state.muted) return;

        // Lire la position sauvegardée — ne restaurer que si même catégorie que la page actuelle
        const saved   = _loadSavedPosition();
        const cat     = _autoCategory();
        const sameCtx = saved && saved.cat === cat;
        const seek    = sameCtx ? (saved.time  || 0)    : 0;
        const track   = sameCtx ? (saved.track || null) : null;

        // Tente l'autoplay seulement si le navigateur l'autorise (consentement connu
        // ET activation utilisateur détectée via userActivation ou HTMX navigation).
        const activated = navigator.userActivation
            ? navigator.userActivation.hasBeenActive
            : document.hasFocus();
        if (_hasConsent() && activated) {
            _load(cat, seek, track);
        } else {
            pendingCat   = cat;
            pendingSeek  = seek;
            pendingTrack = track;
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', _mountButton);
    } else {
        _mountButton();
    }

    // Fallback : reprend sur toute interaction utilisateur (Firefox / Safari).
    // mousedown et keydown comptent comme "user activation" même sans click complet.
    (function () {
        const ac = new AbortController();
        const resume = () => {
            if (!state.muted && pendingCat) {
                _load(pendingCat, pendingSeek, pendingTrack);
            }
            ac.abort();
        };
        ['mousedown', 'keydown', 'touchstart', 'click'].forEach((evt) => {
            document.addEventListener(evt, resume, { signal: ac.signal, once: true });
        });
    })();

    // Appelé par HTMX après un swap de <main> : relance la bonne catégorie si elle change.
    function pageChanged() {
        const newCat = _autoCategory();
        if (newCat !== currentCat) {
            _savePosition();
            _load(newCat);
        }
    }

    window.MUSIC = { play, stinger, stop, toggleMute, setVolume, isMuted, getVolume, pageChanged };
})();
