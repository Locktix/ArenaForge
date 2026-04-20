// ArenaForge — Toasts d'achievements
// API : Toast.achievement(obj), Toast.queue(list), Toast.flush()
// Les toasts sont persistés en sessionStorage pour survivre aux redirections
// (ex: start_fight → fight.php, level_up → brute.php).

(function () {
    const STORAGE_KEY = 'arenaforge_toasts';

    function ensureStack() {
        let stack = document.getElementById('toast-stack');
        if (stack) return stack;
        stack = document.createElement('div');
        stack.id = 'toast-stack';
        stack.className = 'toast-stack';
        document.body.appendChild(stack);
        return stack;
    }

    function show(item) {
        const stack = ensureStack();
        const el = document.createElement('div');
        el.className = 'toast';
        const iconUrl = item.icon_path ? '../' + item.icon_path : '../assets/svg/quests/trophy.svg';
        el.innerHTML = `
            <img class="toast-icon" src="${escapeAttr(iconUrl)}" alt="">
            <div class="toast-body">
                <span class="toast-title">${escapeHtml(item.kind || 'Trophée débloqué')}</span>
                <span class="toast-text">${escapeHtml(item.title || '')}${item.reward_xp ? ' <em class="muted">(+' + item.reward_xp + ' XP)</em>' : ''}</span>
            </div>
        `;
        stack.appendChild(el);
        // Purge après l'animation de sortie (~5s)
        setTimeout(() => el.remove(), 5200);
        // Son de succès
        if (window.SFX) window.SFX.play('achievement');
    }

    function showAchievement(a) {
        show({
            kind: 'Trophée débloqué',
            title: a.title,
            reward_xp: a.reward_xp,
            icon_path: a.icon_path,
        });
    }

    function queue(list) {
        if (!Array.isArray(list) || !list.length) return;
        const existing = loadQueue();
        existing.push(...list);
        saveQueue(existing);
    }

    function loadQueue() {
        try {
            const raw = sessionStorage.getItem(STORAGE_KEY);
            return raw ? JSON.parse(raw) : [];
        } catch (e) { return []; }
    }

    function saveQueue(list) {
        try {
            if (list.length) sessionStorage.setItem(STORAGE_KEY, JSON.stringify(list));
            else sessionStorage.removeItem(STORAGE_KEY);
        } catch (e) {}
    }

    function flush() {
        const list = loadQueue();
        if (!list.length) return;
        saveQueue([]);
        // Empiler avec un léger décalage pour l'animation
        list.forEach((a, i) => setTimeout(() => showAchievement(a), i * 300));
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }
    function escapeAttr(s) { return escapeHtml(s); }

    // Auto-flush à chaque chargement
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', flush);
    } else {
        flush();
    }

    window.Toast = { show, achievement: showAchievement, queue, flush };
})();
