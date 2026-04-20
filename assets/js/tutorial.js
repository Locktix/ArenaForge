// ArenaForge — Tutoriel guidé (overlay + spotlight + bulle)
//
// Lit window.TUTORIAL injecté par le serveur. Affiche l'overlay quand un step
// est actif pour la page courante. Gère advance / skip / restart via
// ../api/tutorial.php.

(function () {
    const ENDPOINT = '../api/tutorial.php';
    let mounted = false;
    let observer = null;

    function ready(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else { fn(); }
    }

    ready(() => {
        const step = window.TUTORIAL;
        if (!step) return;
        tryMount(step);
    });

    // Tente de monter l'overlay : si la cible n'existe pas encore,
    // attend son apparition via MutationObserver jusqu'à 3 secondes.
    // Si introuvable, on saute le step automatiquement plutôt que de piéger
    // l'utilisateur avec une modale centrée qui bloque la page.
    function tryMount(step) {
        if (!step.target || step.position === 'center') {
            mount(step, null);
            return;
        }
        const el = document.querySelector(step.target);
        if (el) { mount(step, el); return; }

        // Observateur bref au cas où la cible apparaîtrait après chargement
        observer = new MutationObserver(() => {
            const found = document.querySelector(step.target);
            if (found) {
                observer.disconnect();
                mount(step, found);
            }
        });
        observer.observe(document.body, { childList: true, subtree: true });
        setTimeout(() => {
            if (observer) { observer.disconnect(); observer = null; }
            if (mounted) return;
            const found = document.querySelector(step.target);
            if (found) {
                mount(step, found);
            } else {
                // Cible absente : auto-advance pour ne pas bloquer la navigation.
                silentAdvance();
            }
        }, 3000);
    }

    function silentAdvance() {
        const fd = new FormData();
        fd.append('csrf', window.TUTORIAL_CSRF || '');
        fd.append('action', 'advance');
        fetch(ENDPOINT, { method: 'POST', body: fd }).catch(() => {});
    }

    function mount(step, targetEl) {
        if (mounted) return;
        mounted = true;

        const root = document.createElement('div');
        root.className = 'tutorial-root';
        root.innerHTML = `
            <div class="tutorial-backdrop" data-bg></div>
            <div class="tutorial-spotlight" data-spot></div>
            <div class="tutorial-bubble" data-bubble role="dialog" aria-modal="true" aria-labelledby="tut-title">
                <div class="tutorial-progress">
                    <span class="tutorial-step-idx">Étape ${step.index + 1} / ${step.total}</span>
                    <div class="tutorial-bar"><div class="tutorial-bar-fill" style="width:${Math.round((step.index + 1) / step.total * 100)}%"></div></div>
                </div>
                <h3 id="tut-title">${escapeHtml(step.title)}</h3>
                <p>${escapeHtml(step.text)}</p>
                ${step.action_hint ? `<p class="tutorial-hint">${escapeHtml(step.action_hint)}</p>` : ''}
                <div class="tutorial-actions">
                    <button type="button" class="btn btn-ghost" data-skip>Passer le tutoriel</button>
                    ${step.action_hint ? '' : `<button type="button" class="btn btn-secondary" data-next>${escapeHtml(step.next_label || 'Suivant')}</button>`}
                </div>
            </div>
        `;
        document.body.appendChild(root);

        const bubble = root.querySelector('[data-bubble]');
        const spot   = root.querySelector('[data-spot]');

        if (targetEl) {
            positionSpotlight(spot, targetEl);
            positionBubble(bubble, targetEl, step.position || 'bottom');
        } else {
            spot.style.display = 'none';
            bubble.classList.add('tutorial-bubble-center');
        }

        // Resize / scroll : repositionne
        const reposition = () => {
            if (targetEl && document.contains(targetEl)) {
                positionSpotlight(spot, targetEl);
                positionBubble(bubble, targetEl, step.position || 'bottom');
            }
        };
        window.addEventListener('resize', reposition);
        window.addEventListener('scroll', reposition, { passive: true });

        // Boutons
        root.querySelector('[data-skip]').addEventListener('click', onSkip);
        const nextBtn = root.querySelector('[data-next]');
        if (nextBtn) nextBtn.addEventListener('click', () => onNext(step));

        // Action-hint : avancer quand l'utilisateur clique la cible
        if (step.action_hint && targetEl) {
            const once = (e) => {
                // Laisser l'action native se faire, on avance le step en beacon
                advanceBeacon();
                targetEl.removeEventListener('click', once);
                // Si la page change, le beacon aura déjà été envoyé
            };
            targetEl.addEventListener('click', once);
        }

        // Clic sur backdrop : rappel subtil (ne ferme pas)
        root.querySelector('[data-bg]').addEventListener('click', (e) => {
            // Pas de fermeture accidentelle ; léger wiggle pour attirer l'œil
            bubble.classList.add('tutorial-wiggle');
            setTimeout(() => bubble.classList.remove('tutorial-wiggle'), 400);
        });

        // Auto-scroll si cible hors viewport
        if (targetEl) {
            const rect = targetEl.getBoundingClientRect();
            if (rect.top < 80 || rect.bottom > window.innerHeight - 40) {
                targetEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                setTimeout(reposition, 400);
            }
        }
    }

    function positionSpotlight(spot, el) {
        const r = el.getBoundingClientRect();
        const pad = 8;
        spot.style.top    = (r.top - pad) + 'px';
        spot.style.left   = (r.left - pad) + 'px';
        spot.style.width  = (r.width + pad * 2) + 'px';
        spot.style.height = (r.height + pad * 2) + 'px';
    }

    function positionBubble(bubble, el, pos) {
        const r  = el.getBoundingClientRect();
        const bw = 360; // largeur cible de la bulle
        const gap = 18;
        bubble.style.width = bw + 'px';
        bubble.classList.remove('tutorial-bubble-top', 'tutorial-bubble-bottom', 'tutorial-bubble-left', 'tutorial-bubble-right', 'tutorial-bubble-center');

        // Place la bulle selon la position demandée, avec fallback si hors écran
        let top, left;
        const bh = bubble.offsetHeight || 180;
        const vw = window.innerWidth;
        const vh = window.innerHeight;

        let effective = pos;

        switch (pos) {
            case 'top':
                top  = r.top - bh - gap;
                left = r.left + r.width / 2 - bw / 2;
                if (top < 10) effective = 'bottom';
                break;
            case 'left':
                top  = r.top + r.height / 2 - bh / 2;
                left = r.left - bw - gap;
                if (left < 10) effective = 'right';
                break;
            case 'right':
                top  = r.top + r.height / 2 - bh / 2;
                left = r.right + gap;
                if (left + bw > vw - 10) effective = 'left';
                break;
            case 'bottom':
            default:
                top  = r.bottom + gap;
                left = r.left + r.width / 2 - bw / 2;
                if (top + bh > vh - 10) effective = 'top';
        }

        // Recalcule si fallback
        if (effective !== pos) {
            if (effective === 'top')    { top = r.top - bh - gap; left = r.left + r.width / 2 - bw / 2; }
            if (effective === 'bottom') { top = r.bottom + gap;   left = r.left + r.width / 2 - bw / 2; }
            if (effective === 'left')   { top = r.top + r.height / 2 - bh / 2; left = r.left - bw - gap; }
            if (effective === 'right')  { top = r.top + r.height / 2 - bh / 2; left = r.right + gap; }
        }

        // Clamp dans le viewport
        left = Math.max(12, Math.min(vw - bw - 12, left));
        top  = Math.max(12, Math.min(vh - bh - 12, top));

        bubble.style.top  = top + 'px';
        bubble.style.left = left + 'px';
        bubble.classList.add('tutorial-bubble-' + effective);
    }

    async function onNext(step) {
        try {
            const fd = new FormData();
            fd.append('csrf', window.TUTORIAL_CSRF || '');
            fd.append('action', 'advance');
            const res = await fetch(ENDPOINT, { method: 'POST', body: fd });
            const data = await res.json();
            if (!data.ok) { alert(data.error || 'Erreur'); return; }
            if (step.next_url) { window.location.href = step.next_url; }
            else { window.location.reload(); }
        } catch (e) { alert('Erreur réseau'); }
    }

    async function onSkip() {
        if (!confirm('Passer le tutoriel ? Tu pourras le relancer depuis la navigation.')) return;
        try {
            const fd = new FormData();
            fd.append('csrf', window.TUTORIAL_CSRF || '');
            fd.append('action', 'skip');
            await fetch(ENDPOINT, { method: 'POST', body: fd });
            unmount();
        } catch (e) {}
    }

    // Beacon non bloquant pour avancer le step sans interrompre l'action
    // (utilisé quand l'utilisateur clique la cible d'un step action_required)
    function advanceBeacon() {
        const fd = new FormData();
        fd.append('csrf', window.TUTORIAL_CSRF || '');
        fd.append('action', 'advance');
        if (navigator.sendBeacon) {
            navigator.sendBeacon(ENDPOINT, fd);
        } else {
            fetch(ENDPOINT, { method: 'POST', body: fd, keepalive: true });
        }
    }

    function unmount() {
        const r = document.querySelector('.tutorial-root');
        if (r) r.remove();
        mounted = false;
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    // Point d'entrée externe pour le lien "Relancer le tuto"
    window.arenaforgeTutorial = {
        restart: async () => {
            const fd = new FormData();
            fd.append('csrf', window.TUTORIAL_CSRF || '');
            fd.append('action', 'restart');
            const res = await fetch(ENDPOINT, { method: 'POST', body: fd });
            const data = await res.json();
            if (data.ok && data.redirect) window.location.href = data.redirect;
        },
    };
})();
