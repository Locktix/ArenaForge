(function () {
    'use strict';

    document.querySelectorAll('.title-form').forEach(function (form) {
        form.addEventListener('submit', async function (e) {
            e.preventDefault();
            const btn = form.querySelector('button');
            if (btn) { btn.disabled = true; }

            try {
                const fd = new FormData(form);
                const res = await fetch('../api/set_title.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (!data.ok) {
                    alert(data.error || 'Erreur lors du changement de titre.');
                    if (btn) { btn.disabled = false; }
                    return;
                }
                window.location.reload();
            } catch (err) {
                alert('Erreur réseau.');
                if (btn) { btn.disabled = false; }
            }
        });
    });
})();
