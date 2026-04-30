// Chat de clan : polling toutes les 30 s + envoi instantané

(function () {
    const win  = document.getElementById('clan-chat-window');
    const form = document.getElementById('clan-chat-form');
    if (!win || !form) return;

    const bruteId = parseInt(win.dataset.bruteId, 10);
    let lastId = 0;
    let pollTimer = null;

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }
    function fmtTime(ts) {
        try {
            const d = new Date(ts.replace(' ', 'T'));
            return d.toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
        } catch (e) { return ts; }
    }

    function appendMessages(list) {
        if (!list.length) return;
        const wasAtBottom = (win.scrollHeight - win.scrollTop - win.clientHeight) < 60;
        if (lastId === 0) win.innerHTML = '';
        list.forEach((m) => {
            if (m.id <= lastId) return;
            lastId = Math.max(lastId, m.id);
            const isMe = parseInt(m.brute_id, 10) === bruteId;
            const div = document.createElement('div');
            div.className = 'chat-msg ' + (isMe ? 'chat-msg-me' : 'chat-msg-other');
            div.innerHTML = `
                <div class="chat-meta">
                    <a href="brute.php?id=${parseInt(m.brute_id, 10)}" class="chat-author">${escapeHtml(m.name)}</a>
                    <span class="chat-time muted small">${escapeHtml(fmtTime(m.created_at))}</span>
                </div>
                <div class="chat-body">${escapeHtml(m.body)}</div>
            `;
            win.appendChild(div);
        });
        if (wasAtBottom) win.scrollTop = win.scrollHeight;
    }

    async function poll() {
        try {
            const res = await fetch('../api/clan_message_list.php?brute_id=' + bruteId + '&since_id=' + lastId);
            const data = await res.json();
            if (data.ok) {
                if (lastId === 0 && (!data.messages || !data.messages.length)) {
                    win.innerHTML = '<p class="muted small">Aucun message pour l\'instant. Lance la conversation.</p>';
                } else {
                    appendMessages(data.messages || []);
                }
            }
        } catch (e) { /* ignore */ }
    }

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const input = form.querySelector('input[name=body]');
        const btn   = form.querySelector('button');
        if (!input.value.trim()) return;
        btn.disabled = true;
        try {
            const res = await fetch('../api/clan_message_send.php', { method: 'POST', body: new FormData(form) });
            const data = await res.json();
            if (data.ok) {
                input.value = '';
                poll();
            } else if (window.Toast) {
                window.Toast.queue([{ title: data.error || 'Erreur', icon_path: 'assets/svg/ui/nav_settings.svg' }]);
            }
        } catch (err) { /* ignore */ }
        finally {
            btn.disabled = false;
            input.focus();
        }
    });

    // Premier chargement + polling
    poll();
    pollTimer = setInterval(poll, 30000);

    // Stoppe le polling quand l'onglet est masqué (économie batterie)
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            clearInterval(pollTimer);
        } else {
            poll();
            pollTimer = setInterval(poll, 30000);
        }
    });
})();
