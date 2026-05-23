(function () {
    const modal       = document.getElementById('sacrifice-modal');
    const stage       = document.getElementById('sac-reveal-stage');
    const result      = document.getElementById('sac-reveal-result');
    const titleEl     = result.querySelector('.sac-reveal-title');
    const labelEl     = result.querySelector('.sac-reveal-label');
    const resIcon     = result.querySelector('.sac-reveal-result-icon');
    const closeBtn    = document.getElementById('sac-reveal-close');

    const ICONS = {
        jackpot: '✨',
        good:    '⚜',
        bad:     '💀',
        nothing: '☠',
        weapon:  '⚔',
    };
    const TITLES = {
        jackpot: 'Les dieux te bénissent',
        good:    'Une faveur t\'est accordée',
        bad:     'Le destin se retourne',
        nothing: 'Les dieux restent silencieux',
        weapon:  'Les dieux te bénissent',
    };

    function showModal() {
        modal.hidden = false;
        stage.hidden = false;
        result.hidden = true;
        document.body.style.overflow = 'hidden';
    }
    function hideModal() {
        modal.hidden = true;
        document.body.style.overflow = '';
    }
    function reveal(code, label) {
        stage.hidden = true;
        result.hidden = false;
        resIcon.textContent = ICONS[code] || '⚔';
        result.className = 'sac-reveal-result sac-out-' + code;
        titleEl.textContent = TITLES[code] || 'Le rituel s\'achève';
        labelEl.textContent = label;
    }

    closeBtn.addEventListener('click', () => {
        hideModal();
        window.location.reload();
    });

    document.querySelectorAll('.sacrifice-form').forEach(form => {
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const name = form.dataset.name || 'ce rituel';
            if (!confirm(`Accomplir « ${name} » ?\n\nLe sacrifice est irréversible.`)) {
                return;
            }
            const btn = form.querySelector('button[type=submit]');
            const msg = form.parentElement.querySelector('[data-msg]');
            if (btn) btn.disabled = true;
            if (msg) { msg.className = 'form-msg'; msg.textContent = ''; }

            showModal();

            try {
                const res  = await fetch('../api/sacrifice.php', { method: 'POST', body: new FormData(form) });
                const data = await res.json();

                // Laisser tourner l'animation au moins 1.5s pour la tension
                await new Promise(r => setTimeout(r, 1500));

                if (data.ok) {
                    reveal(data.outcome_code, data.outcome_label);
                } else {
                    hideModal();
                    if (msg) { msg.className = 'form-msg error'; msg.textContent = data.error || 'Erreur'; }
                    if (btn) btn.disabled = false;
                }
            } catch {
                hideModal();
                if (msg) { msg.className = 'form-msg error'; msg.textContent = 'Erreur réseau'; }
                if (btn) btn.disabled = false;
            }
        });
    });
})();
