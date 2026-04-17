// Création de gladiateur

const form = document.getElementById('create-form');
if (form) {
    const msg = form.querySelector('[data-msg]');
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        msg.className = 'form-msg';
        msg.textContent = 'Création en cours…';
        try {
            const res = await fetch('/ArenaForge/api/create_brute.php', {
                method: 'POST',
                body: new FormData(form),
            });
            const data = await res.json();
            if (data.ok) {
                window.location.href = data.redirect;
            } else {
                msg.className = 'form-msg error';
                msg.textContent = data.error || 'Erreur';
            }
        } catch (err) {
            msg.className = 'form-msg error';
            msg.textContent = 'Erreur réseau';
        }
    });
}
