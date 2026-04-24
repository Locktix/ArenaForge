// Quêtes : réclamation d'une récompense

document.querySelectorAll('.weekly-quest-claim-form').forEach((form) => {
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const btn = form.querySelector('button');
        if (btn) { btn.disabled = true; btn.textContent = '...'; }
        try {
            const res = await fetch('../api/weekly_quest_claim.php', {
                method: 'POST',
                body: new FormData(form),
            });
            const data = await res.json();
            if (data.ok) {
                window.location.href = data.redirect || window.location.href;
            } else {
                alert(data.error || 'Erreur');
                if (btn) { btn.disabled = false; btn.textContent = 'Réclamer'; }
            }
        } catch (err) {
            alert('Erreur réseau');
            if (btn) { btn.disabled = false; btn.textContent = 'Réclamer'; }
        }
    });
});

document.querySelectorAll('.quest-claim-form').forEach((form) => {
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const btn = form.querySelector('button');
        if (btn) { btn.disabled = true; btn.textContent = '...'; }
        try {
            const res = await fetch('../api/quest_claim.php', {
                method: 'POST',
                body: new FormData(form),
            });
            const data = await res.json();
            if (data.ok) {
                window.location.href = data.redirect || window.location.href;
            } else {
                alert(data.error || 'Erreur');
                if (btn) { btn.disabled = false; btn.textContent = 'Réclamer'; }
            }
        } catch (err) {
            alert('Erreur réseau');
            if (btn) { btn.disabled = false; btn.textContent = 'Réclamer'; }
        }
    });
});
