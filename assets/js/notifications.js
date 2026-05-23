(function () {
    'use strict';

    const btn      = document.getElementById('notif-btn');
    if (!btn) return;

    const panel    = document.getElementById('notif-panel');
    const overlay  = document.getElementById('notif-overlay');
    const closeBtn = document.getElementById('notif-panel-close');
    const body     = document.getElementById('notif-body');
    const badgeEl  = document.getElementById('notif-badge-srv');

    let isOpen  = false;
    let loaded  = false;

    // ---- Open / Close ----

    function openPanel() {
        isOpen = true;
        panel.classList.add('notif-panel--open');
        overlay.classList.add('notif-overlay--open');
        document.body.classList.add('notif-open');
        btn.setAttribute('aria-expanded', 'true');
        panel.removeAttribute('aria-hidden');
        if (!loaded) load();
    }

    function closePanel() {
        isOpen = false;
        panel.classList.remove('notif-panel--open');
        overlay.classList.remove('notif-overlay--open');
        document.body.classList.remove('notif-open');
        btn.setAttribute('aria-expanded', 'false');
        panel.setAttribute('aria-hidden', 'true');
    }

    // ---- Fetch ----

    async function load() {
        body.innerHTML =
            '<div class="notif-spinner">' +
            '<span class="notif-spinner-icon">⚔</span>' +
            '<p>Les oracles délibèrent…</p>' +
            '</div>';
        try {
            const res  = await fetch('../api/notifications.php');
            const data = await res.json();
            if (!data.ok) throw new Error(data.error || 'Erreur');
            loaded = true;
            renderAll(data.notifications);
            updateBadge(data.urgent_count);
        } catch {
            body.innerHTML =
                '<div class="notif-empty">' +
                '<div class="notif-empty-glyph">⚡</div>' +
                '<p>Impossible de consulter les oracles.<br><small>Réessaie dans un moment.</small></p>' +
                '</div>';
        }
    }

    // ---- Badge ----

    function updateBadge(count) {
        if (!badgeEl) return;
        if (count > 0) {
            badgeEl.textContent = count > 9 ? '9+' : String(count);
            badgeEl.hidden = false;
        } else {
            badgeEl.hidden = true;
        }
    }

    // ---- Render ----

    const CATEGORY_LABELS = {
        urgent:   '⚠ Urgent',
        action:   '⚔ À faire',
        activity: '📜 Activité récente',
        info:     '✦ En cours',
    };

    function renderAll(notifs) {
        if (!notifs || !notifs.length) {
            body.innerHTML =
                '<div class="notif-empty">' +
                '<div class="notif-empty-glyph">☮</div>' +
                '<p>Aucune nouvelle pour l\'instant.<br><small>Les dieux observent en silence.</small></p>' +
                '</div>';
            return;
        }

        const groups = {};
        const ORDER  = ['urgent', 'action', 'activity', 'info'];
        for (const n of notifs) {
            const cat = n.category || 'info';
            if (!groups[cat]) groups[cat] = [];
            groups[cat].push(n);
        }

        let html = '';
        for (const cat of ORDER) {
            if (!groups[cat] || !groups[cat].length) continue;
            html += '<div class="notif-section-label">' + esc(CATEGORY_LABELS[cat] || cat) + '</div>';
            for (const n of groups[cat]) html += renderItem(n);
        }

        body.innerHTML = html;
    }

    function renderItem(n) {
        const cls = ['notif-item'];
        if (n.urgent)                        cls.push('notif-item--urgent');
        if (n.kind === 'fight' && n.win)  cls.push('notif-item--win');
        if (n.kind === 'fight' && !n.win) cls.push('notif-item--loss');

        const iconSrc = '../' + esc(n.icon || 'assets/svg/ui/scroll.svg');
        const arrow   = n.urgent
            ? '<span class="notif-item-excl" aria-hidden="true">!</span>'
            : '<span class="notif-item-chevron" aria-hidden="true">›</span>';

        const inner =
            '<span class="notif-item-media">' +
            '<img src="' + iconSrc + '" alt="" class="notif-item-icon" loading="lazy">' +
            '</span>' +
            '<span class="notif-item-content">' +
            '<strong class="notif-item-title">' + esc(n.title || '') + '</strong>' +
            '<span class="notif-item-body">' + esc(n.body || '') + '</span>' +
            '</span>' +
            arrow;

        if (n.href) {
            return '<a href="' + esc(n.href) + '" class="' + cls.join(' ') + '">' + inner + '</a>';
        }
        return '<div class="' + cls.join(' ') + '">' + inner + '</div>';
    }

    function esc(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // ---- Events ----

    btn.addEventListener('click', () => isOpen ? closePanel() : openPanel());
    if (closeBtn)  closeBtn.addEventListener('click', closePanel);
    if (overlay)   overlay.addEventListener('click', closePanel);

    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && isOpen) closePanel();
    });
})();
