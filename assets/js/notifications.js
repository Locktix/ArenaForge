(function () {
    'use strict';

    const btn      = document.getElementById('notif-btn');
    if (!btn) return;

    const panel    = document.getElementById('notif-panel');
    const overlay  = document.getElementById('notif-overlay');
    const closeBtn = document.getElementById('notif-panel-close');
    const body     = document.getElementById('notif-body');
    const badgeEl  = document.getElementById('notif-badge-srv');

    let isOpen = false;
    let loaded = false;
    let csrfToken = '';

    // Récupère le CSRF depuis la meta ou depuis un input existant sur la page
    function getCsrf() {
        const m = document.querySelector('meta[name="csrf-token"]');
        if (m) return m.content;
        const i = document.querySelector('input[name="csrf"]');
        if (i) return i.value;
        return '';
    }

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
            render(data.events || [], data.live || []);
            updateBadge(data.urgent_count || 0);
            // Marquer comme lus si on a des non-lus
            if (data.unread_count > 0) markRead();
        } catch {
            body.innerHTML =
                '<div class="notif-empty">' +
                '<div class="notif-empty-glyph">⚡</div>' +
                '<p>Impossible de consulter les oracles.<br><small>Réessaie dans un moment.</small></p>' +
                '</div>';
        }
    }

    async function markRead() {
        try {
            const fd = new FormData();
            fd.append('csrf', getCsrf());
            await fetch('../api/notifications.php', { method: 'POST', body: fd });
            if (badgeEl) {
                // Garde uniquement le compte urgent (live)
                const urgentSpan = panel.querySelectorAll('.notif-item--urgent').length;
                updateBadge(urgentSpan);
            }
        } catch { /* silent */ }
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

    const KIND_ICONS = {
        achievement : 'assets/svg/ui/trophy.svg',
        quest       : 'assets/svg/ui/scroll.svg',
        sacrifice   : 'assets/svg/skills/rage.svg',
        tournament  : 'assets/svg/ui/trophy.svg',
        info        : 'assets/svg/ui/scroll.svg',
    };

    const LIVE_LABELS = {
        urgent   : '⚠ Urgent',
        action   : '⚔ À faire',
        activity : '📜 Activité récente',
        info     : '✦ En cours',
    };

    function render(events, live) {
        const hasEvents = events && events.length > 0;
        const hasLive   = live   && live.length   > 0;

        if (!hasEvents && !hasLive) {
            body.innerHTML =
                '<div class="notif-empty">' +
                '<div class="notif-empty-glyph">☮</div>' +
                '<p>Aucune nouvelle pour l\'instant.<br><small>Les dieux observent en silence.</small></p>' +
                '</div>';
            return;
        }

        let html = '';

        // Section événements persistants
        if (hasEvents) {
            html += '<div class="notif-section-label">📣 Événements récents</div>';
            for (const e of events) html += renderEvent(e);
        }

        // Section live (urgent + action + activity + info)
        if (hasLive) {
            const groups = {};
            const ORDER  = ['urgent', 'action', 'activity', 'info'];
            for (const n of live) {
                const cat = n.category || 'info';
                if (!groups[cat]) groups[cat] = [];
                groups[cat].push(n);
            }
            for (const cat of ORDER) {
                if (!groups[cat] || !groups[cat].length) continue;
                html += '<div class="notif-section-label">' + esc(LIVE_LABELS[cat] || cat) + '</div>';
                for (const n of groups[cat]) html += renderLiveItem(n);
            }
        }

        body.innerHTML = html;
    }

    // Événement persistant (depuis la BDD)
    function renderEvent(e) {
        const unread  = !!e.unread;
        const cls     = ['notif-item', 'notif-item--event'];
        if (unread) cls.push('notif-item--unread');

        const icon    = '../' + esc(e.icon || KIND_ICONS[e.kind] || 'assets/svg/ui/scroll.svg');
        const timeStr = relativeTime(e.created_at);

        const inner =
            '<span class="notif-item-media">' +
            '<img src="' + icon + '" alt="" class="notif-item-icon" loading="lazy">' +
            (unread ? '<span class="notif-item-dot" aria-hidden="true"></span>' : '') +
            '</span>' +
            '<span class="notif-item-content">' +
            '<strong class="notif-item-title">' + esc(e.title || '') + '</strong>' +
            '<span class="notif-item-body">' + esc(e.body || '') + '</span>' +
            '</span>' +
            '<span class="notif-item-time">' + esc(timeStr) + '</span>';

        if (e.href) {
            return '<a href="' + esc(e.href) + '" class="' + cls.join(' ') + '">' + inner + '</a>';
        }
        return '<div class="' + cls.join(' ') + '">' + inner + '</div>';
    }

    // Notification live (calculée en temps réel)
    function renderLiveItem(n) {
        const cls = ['notif-item'];
        if (n.urgent)                         cls.push('notif-item--urgent');
        if (n.kind === 'fight' && n.win)      cls.push('notif-item--win');
        if (n.kind === 'fight' && n.win === false) cls.push('notif-item--loss');

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

    // Temps relatif (ex: "il y a 2 h", "hier", "lun. 14:32")
    function relativeTime(dateStr) {
        if (!dateStr) return '';
        const d     = new Date(dateStr.replace(' ', 'T'));
        const now   = new Date();
        const diff  = Math.floor((now - d) / 1000);

        if (diff < 60)       return 'À l\'instant';
        if (diff < 3600)     return 'il y a ' + Math.floor(diff / 60) + ' min';
        if (diff < 86400)    return 'il y a ' + Math.floor(diff / 3600) + ' h';
        if (diff < 172800)   return 'hier';

        const days  = ['dim.', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.'];
        const hh    = String(d.getHours()).padStart(2, '0');
        const mm    = String(d.getMinutes()).padStart(2, '0');
        return days[d.getDay()] + ' ' + hh + ':' + mm;
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
