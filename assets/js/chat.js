// ArenaForge — Chat global
// Polling toutes les 8 secondes. Lecture publique, envoi réservé aux brutes connectées.

(function () {
    const BASE_URL = (function () {
        const el = document.querySelector('script[src*="chat.js"]');
        return el ? el.src.replace(/\/assets\/js\/chat\.js(\?.*)?$/, '') : '';
    })();

    const btn    = document.getElementById('chat-btn');
    const panel  = document.getElementById('chat-panel');
    const body   = document.getElementById('chat-body');
    const form   = document.getElementById('chat-form');
    const input  = document.getElementById('chat-input');
    const badge  = document.getElementById('chat-new-badge');
    const overlay = document.getElementById('chat-overlay');

    if (!btn || !panel) return;

    let lastId    = 0;
    let isOpen    = false;
    let hasNew    = false;
    let pollTimer = null;

    function timeLabel(dt) {
        return new Date(dt).toLocaleTimeString('fr', { hour: '2-digit', minute: '2-digit' });
    }

    function appendMessage(m, animate) {
        const div = document.createElement('div');
        div.className = 'chat-msg' + (animate ? ' chat-msg--in' : '');
        div.dataset.id = m.id;
        div.innerHTML =
            '<a class="chat-name" href="' + BASE_URL + '/public/brute.php?id=' + (+m.brute_id) + '">' +
            escHtml(m.brute_name) + '</a>' +
            '<span class="chat-text">' + escHtml(m.message) + '</span>' +
            '<span class="chat-time">' + timeLabel(m.created_at) + '</span>';
        body.appendChild(div);
        if (+m.id > lastId) lastId = +m.id;
    }

    function escHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    async function poll() {
        clearTimeout(pollTimer);
        try {
            const res  = await fetch(BASE_URL + '/api/chat_get.php?since=' + lastId);
            const data = await res.json();
            if (data.ok && data.messages.length) {
                data.messages.forEach((m) => appendMessage(m, lastId > 0));
                body.scrollTop = body.scrollHeight;
                if (!isOpen && lastId > 0) {
                    hasNew = true;
                    if (badge) badge.hidden = false;
                }
            }
        } catch (_) {}
        pollTimer = setTimeout(poll, 8000);
    }

    function openPanel() {
        isOpen = true;
        panel.classList.add('chat-open');
        panel.setAttribute('aria-hidden', 'false');
        if (overlay) overlay.classList.add('chat-overlay--open');
        btn.setAttribute('aria-expanded', 'true');
        hasNew = false;
        if (badge) badge.hidden = true;
        setTimeout(() => body.scrollTop = body.scrollHeight, 50);
        if (input) input.focus();
    }

    function closePanel() {
        isOpen = false;
        panel.classList.remove('chat-open');
        panel.setAttribute('aria-hidden', 'true');
        if (overlay) overlay.classList.remove('chat-overlay--open');
        btn.setAttribute('aria-expanded', 'false');
    }

    btn.addEventListener('click', () => isOpen ? closePanel() : openPanel());
    if (overlay) overlay.addEventListener('click', closePanel);

    const closeBtn = document.getElementById('chat-close');
    if (closeBtn) closeBtn.addEventListener('click', closePanel);

    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && isOpen) closePanel(); });

    if (form) {
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const msg = input ? input.value.trim() : '';
            if (!msg) return;
            const csrfEl = form.querySelector('[name=csrf]');
            const fd = new FormData();
            fd.append('csrf', csrfEl ? csrfEl.value : '');
            fd.append('message', msg);
            if (input) input.value = '';
            try {
                const res  = await fetch(BASE_URL + '/api/chat_send.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (!data.ok && window.Toast) {
                    window.Toast.queue([{
                        title: 'Chat',
                        description: data.error || 'Erreur',
                        icon_path: 'assets/svg/ui/nav_settings.svg',
                    }]);
                } else if (data.ok) {
                    // Déclenche un poll immédiat pour afficher le message
                    clearTimeout(pollTimer);
                    poll();
                }
            } catch (_) {}
        });
    }

    poll();
})();
