(function () {
    // Modals
    const confirmModal = document.getElementById('sacrifice-confirm');
    const revealModal  = document.getElementById('sacrifice-modal');
    const stage        = document.getElementById('sac-reveal-stage');
    const result       = document.getElementById('sac-reveal-result');
    const titleEl      = result.querySelector('.sac-reveal-title');
    const labelEl      = result.querySelector('.sac-reveal-label');
    const resIcon      = result.querySelector('.sac-reveal-result-icon');
    const closeBtn     = document.getElementById('sac-reveal-close');

    // Confirm modal parts
    const confirmIcon    = confirmModal.querySelector('.sac-confirm-icon');
    const confirmName    = confirmModal.querySelector('.sac-confirm-name');
    const confirmCostTxt = confirmModal.querySelector('.sac-confirm-cost-text');
    const cancelBtn      = document.getElementById('sac-confirm-cancel');
    const validateBtn    = document.getElementById('sac-confirm-validate');

    const ICONS = {
        jackpot: '✨', good: '⚜', bad: '💀', nothing: '☠', weapon: '⚔',
    };
    const TITLES = {
        jackpot: 'Les dieux te bénissent',
        good:    'Une faveur t\'est accordée',
        bad:     'Le destin se retourne',
        nothing: 'Les dieux restent silencieux',
        weapon:  'Les dieux te bénissent',
    };

    let pendingForm = null;

    function openModal(modal) {
        modal.hidden = false;
        document.body.style.overflow = 'hidden';
    }
    function closeModal(modal) {
        modal.hidden = true;
        document.body.style.overflow = '';
    }

    function showReveal() {
        stage.hidden  = false;
        result.hidden = true;
        openModal(revealModal);
    }
    function reveal(code, label) {
        stage.hidden  = true;
        result.hidden = false;
        resIcon.textContent = ICONS[code] || '⚔';
        result.className = 'sac-reveal-result sac-out-' + code;
        titleEl.textContent = TITLES[code] || 'Le rituel s\'achève';
        labelEl.textContent = label;
    }

    closeBtn.addEventListener('click', () => {
        closeModal(revealModal);
        window.location.reload();
    });

    // ---- Modal de confirmation ----
    function showConfirm(form) {
        pendingForm = form;
        const card = form.closest('.sacrifice-card');
        const icon = card ? card.querySelector('.sacrifice-icon')?.textContent : '☠';
        const name = form.dataset.name || 'ce rituel';
        const cost = card ? card.querySelector('.sacrifice-cost strong')?.textContent : '';
        confirmIcon.textContent    = icon || '☠';
        confirmName.textContent    = name;
        confirmCostTxt.textContent = cost || '';
        validateBtn.disabled = false;
        validateBtn.textContent = 'Accomplir le rituel';
        openModal(confirmModal);
    }

    cancelBtn.addEventListener('click', () => {
        closeModal(confirmModal);
        pendingForm = null;
    });
    // Fermeture par clic en dehors
    confirmModal.addEventListener('click', (e) => {
        if (e.target === confirmModal) {
            closeModal(confirmModal);
            pendingForm = null;
        }
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !confirmModal.hidden) {
            closeModal(confirmModal);
            pendingForm = null;
        }
    });

    validateBtn.addEventListener('click', async () => {
        if (!pendingForm) return;
        const form = pendingForm;
        validateBtn.disabled = true;
        validateBtn.textContent = '⏳ Invocation...';

        const msg = form.parentElement.querySelector('[data-msg]');
        if (msg) { msg.className = 'form-msg'; msg.textContent = ''; }

        closeModal(confirmModal);
        showReveal();

        try {
            const res  = await fetch('../api/sacrifice.php', { method: 'POST', body: new FormData(form) });
            const data = await res.json();

            // Laisser tourner l'animation au moins 1.5s pour la tension
            await new Promise(r => setTimeout(r, 1500));

            if (data.ok) {
                reveal(data.outcome_code, data.outcome_label);
            } else {
                closeModal(revealModal);
                if (msg) { msg.className = 'form-msg error'; msg.textContent = data.error || 'Erreur'; }
            }
        } catch {
            closeModal(revealModal);
            if (msg) { msg.className = 'form-msg error'; msg.textContent = 'Erreur réseau'; }
        } finally {
            pendingForm = null;
        }
    });

    // ---- Forms de sacrifice ----
    document.querySelectorAll('.sacrifice-form').forEach(form => {
        form.addEventListener('submit', (e) => {
            e.preventDefault();
            showConfirm(form);
        });
    });
})();
