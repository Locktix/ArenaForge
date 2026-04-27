// Marché noir : achat via fetch()

document.querySelectorAll('.market-buy-form').forEach((form) => {
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const btn = form.querySelector('button[type=submit]');
        const original = btn ? btn.textContent : '';
        if (btn) { btn.disabled = true; btn.textContent = 'Achat…'; }

        try {
            const res = await fetch('../api/market_buy.php', {
                method: 'POST',
                body: new FormData(form),
            });
            const data = await res.json();
            if (data.ok) {
                if (window.Toast) {
                    window.Toast.queue([{
                        title: 'Achat réussi',
                        description: 'Reste : ' + data.remaining + ' or',
                        icon_path: 'assets/svg/quests/trophy.svg',
                    }]);
                }
                setTimeout(() => window.location.reload(), 600);
            } else {
                if (btn) { btn.disabled = false; btn.textContent = original; }
                if (window.Toast) {
                    window.Toast.queue([{
                        title: 'Achat refusé',
                        description: data.error || 'Erreur',
                        icon_path: 'assets/svg/ui/nav_settings.svg',
                    }]);
                }
            }
        } catch (err) {
            if (btn) { btn.disabled = false; btn.textContent = original; }
        }
    });
});
