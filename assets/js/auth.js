// Inscription + connexion via fetch()

function bindForm(formId, endpoint) {
    const form = document.getElementById(formId);
    if (!form) return;
    const msg = form.querySelector('[data-msg]');

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        msg.className = 'form-msg';
        msg.textContent = '…';

        try {
            const res = await fetch(endpoint, {
                method: 'POST',
                body: new FormData(form),
            });
            const data = await res.json();
            if (data.ok) {
                msg.className = 'form-msg success';
                msg.textContent = 'Succès, redirection…';
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

bindForm('login-form', '/ArenaForge/api/login.php');
bindForm('register-form', '/ArenaForge/api/register.php');
