// Page défis : envoi, accept/decline/cancel via fetch()

async function postFormData(form, endpoint) {
    const msg = form.querySelector('[data-msg]');
    if (msg) { msg.className = 'form-msg'; msg.textContent = '…'; }
    try {
        const res = await fetch(endpoint, { method: 'POST', body: new FormData(form) });
        const data = await res.json();
        if (data.ok) {
            if (data.redirect) {
                window.location.href = data.redirect;
            } else {
                window.location.reload();
            }
        } else if (msg) {
            msg.className = 'form-msg error';
            msg.textContent = data.error || 'Erreur';
        } else if (window.Toast) {
            window.Toast.queue([{ title: 'Erreur', description: data.error || 'Action refusée', icon_path: 'assets/svg/ui/nav_settings.svg' }]);
        }
    } catch (err) {
        if (msg) {
            msg.className = 'form-msg error';
            msg.textContent = 'Erreur réseau';
        }
    }
}

const sendForm = document.getElementById('challenge-send-form');
if (sendForm) {
    sendForm.addEventListener('submit', (e) => {
        e.preventDefault();
        postFormData(sendForm, '../api/challenge_send.php');
    });
}

document.querySelectorAll('.challenge-respond-form').forEach((f) => {
    f.addEventListener('submit', (e) => {
        e.preventDefault();
        postFormData(f, '../api/challenge_respond.php');
    });
});
