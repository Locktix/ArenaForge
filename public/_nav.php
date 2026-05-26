<?php
if (!function_exists('current_user_id')) {
    require_once __DIR__ . '/../includes/auth.php';
}
require_once __DIR__ . '/../includes/streak_engine.php';
require_once __DIR__ . '/../includes/challenge_engine.php';
require_once __DIR__ . '/../includes/bot_engine.php';
require_once __DIR__ . '/../includes/changelog.php';

$navBrute        = current_brute();
$navUid          = current_user_id();
$navStreakInfo    = null;
$navStreakReward  = null;
$navInboxCount   = 0;
$navChangelog    = [];

if ($navUid !== null) {
    $tick = tick_login_streak($navUid);
    $navStreakInfo  = ['streak' => (int)$tick['streak']];
    $navStreakReward = $tick['reward'];
    // Tick "bots auto-fight" — peut résoudre 0..3 combats par chargement
    try { maybe_tick_bot_fights(); } catch (Throwable $e) { /* silent */ }

    // Changelog non vu
    try {
        $stmt = db()->prepare('SELECT last_seen_changelog_version FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$navUid]);
        $navChangelog = changelog_unseen_for((string)($stmt->fetchColumn() ?: ''));
    } catch (Throwable $e) { $navChangelog = []; }
}
if ($navBrute) {
    try { $navInboxCount = pending_inbox_count((int)$navBrute['id']); } catch (Throwable $e) { $navInboxCount = 0; }
}
// Badge : notifications non lues (persistantes) + level-up + défis reçus
$navNotifBadge = 0;
if ($navBrute) {
    $navNotifBadge = (int)($navBrute['notifs_unread_count'] ?? 0)
                   + (int)($navBrute['pending_levelup']     ?? 0)
                   + $navInboxCount;
}

$cp = basename($_SERVER['PHP_SELF'], '.php'); // page courante pour lien actif
function nav_active(string $page, string $current): string {
    return $page === $current ? ' nav-active' : '';
}
?>
<div class="nav-overlay"></div>

<aside class="nav-drawer" aria-label="Menu principal" hx-boost="true" hx-target="main" hx-select="main" hx-swap="outerHTML">
    <div class="nav-drawer-header">
        <a href="dashboard.php" class="brand">
            <img src="../assets/svg/logo/logo.svg" alt="ArenaForge" class="brand-logo" style="height:34px">
        </a>
        <button class="nav-close" id="nav-close" aria-label="Fermer le menu">✕</button>
    </div>

    <?php if ($navBrute): ?>
    <div class="nav-drawer-brute">
        <span class="d-name"><?= h($navBrute['name']) ?></span>
        <div class="d-sub-row">
            <span class="d-sub-lvl">Niveau <?= (int)$navBrute['level'] ?></span>
            <span class="d-sub-xp"><?= (int)$navBrute['xp'] ?> XP</span>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($navBrute): ?>
    <div class="nav-section">
        <span class="nav-section-label">⚔ Citadelle</span>
        <ul>
            <li><a href="brute.php?id=<?= (int)$navBrute['id'] ?>" class="<?= nav_active('brute', $cp) ?>">
                <img src="../assets/svg/ui/nav_fight.svg" alt=""> Profil du Héros
            </a></li>
            <li><a href="tournament.php" class="<?= nav_active('tournament', $cp) ?>">
                <img src="../assets/svg/ui/trophy.svg" alt=""> Grands Tournois
            </a></li>
            <li><a href="boss.php" class="<?= nav_active('boss', $cp) ?>">
                <img src="../assets/svg/skills/rage.svg" alt=""> Antre du Boss
            </a></li>
            <li><a href="dungeon.php" class="<?= nav_active('dungeon', $cp) ?>">
                <img src="../assets/svg/ui/scroll.svg" alt=""> Les Donjons
            </a></li>
            <li><a href="challenges.php" class="<?= nav_active('challenges', $cp) ?>">
                <img src="../assets/svg/weapons/sword.svg" alt=""> Salle des Défis
                <?php if ($navInboxCount > 0): ?><span class="nav-badge"><?= $navInboxCount ?></span><?php endif; ?>
            </a></li>
        </ul>
    </div>

    <div class="nav-section">
        <span class="nav-section-label">☠ Rituels</span>
        <ul>
            <li><a href="sacrifice.php" class="<?= nav_active('sacrifice', $cp) ?>">
                <img src="../assets/svg/skills/rage.svg" alt=""> L'Autel
            </a></li>
        </ul>
    </div>

    <div class="nav-section">
        <span class="nav-section-label">🎮 Divertissements</span>
        <ul>
            <li><a href="minigames.php" class="<?= nav_active('minigames', $cp) ?>">
                <img src="../assets/svg/ui/scroll.svg" alt=""> Mini-Jeux
            </a></li>
        </ul>
    </div>

    <div class="nav-section">
        <span class="nav-section-label">📜 Chroniques</span>
        <ul>
            <li><a href="quests.php" class="<?= nav_active('quests', $cp) ?>">
                <img src="../assets/svg/ui/scroll.svg" alt=""> Quêtes de l'Aube
            </a></li>
            <li><a href="achievements.php" class="<?= nav_active('achievements', $cp) ?>">
                <img src="../assets/svg/ui/trophy.svg" alt=""> Salle des Trophées
            </a></li>
            <li><a href="titles.php" class="<?= nav_active('titles', $cp) ?>">
                <img src="../assets/svg/quests/crown.svg" alt=""> Titres de Gloire
            </a></li>
            <li><a href="skills.php" class="<?= nav_active('skills', $cp) ?>">
                <img src="../assets/svg/skills/strength.svg" alt=""> Compétences
            </a></li>
            <li><a href="codex.php" class="<?= nav_active('codex', $cp) ?>">
                <img src="../assets/svg/ui/scroll.svg" alt=""> Codex Ancien
            </a></li>
            <li><a href="ranking.php" class="<?= nav_active('ranking', $cp) ?>">
                <img src="../assets/svg/ui/nav_ranking.svg" alt=""> Panthéon
            </a></li>
            <li><a href="hof.php" class="<?= nav_active('hof', $cp) ?>">
                <img src="../assets/svg/ui/trophy.svg" alt=""> Hall of Fame
            </a></li>
        </ul>
    </div>

    <div class="nav-section">
        <span class="nav-section-label">👥 Alliances</span>
        <ul>
            <li><a href="pupils.php" class="<?= nav_active('pupils', $cp) ?>">
                <img src="../assets/svg/ui/nav_pupils.svg" alt=""> Ordre des Pupilles
            </a></li>
            <li><a href="clans.php" class="<?= nav_active('clans', $cp) ?>">
                <img src="../assets/svg/ui/nav_pupils.svg" alt=""> Hall des Clans
            </a></li>
        </ul>
    </div>

    <div class="nav-section">
        <span class="nav-section-label">⚖ Commerce</span>
        <ul>
            <li><a href="forge.php" class="<?= nav_active('forge', $cp) ?>">
                <img src="../assets/svg/weapons/axe.svg" alt=""> La Forge Royale
            </a></li>
            <li><a href="market.php" class="<?= nav_active('market', $cp) ?>">
                <img src="../assets/svg/quests/hammer.svg" alt=""> Marché de l'Ombre
            </a></li>
        </ul>
    </div>
    <?php else: ?>
    <div class="nav-section">
        <ul>
            <li><a href="ranking.php" class="<?= nav_active('ranking', $cp) ?>">
                <img src="../assets/svg/ui/nav_ranking.svg" alt=""> Classement
            </a></li>
        </ul>
    </div>
    <?php endif; ?>

    <div class="nav-drawer-footer">
        <button class="nav-help-btn" onclick="if(window.arenaforgeTutorial) window.arenaforgeTutorial.restart();">
            <img src="../assets/svg/ui/nav_settings.svg" alt=""> Aide / Tutoriel
        </button>
        <a href="logout.php" class="logout" hx-boost="false">
            <img src="../assets/svg/ui/nav_settings.svg" alt=""> Déconnexion
        </a>
    </div>
</aside>

<nav class="topnav">
    <button class="nav-toggle" id="nav-toggle" aria-label="Menu" aria-expanded="false">
        <span></span><span></span><span></span>
    </button>
    <a class="brand" href="dashboard.php">
        <img src="../assets/svg/logo/logo.svg" alt="ArenaForge" class="brand-logo">
    </a>
    <div class="nav-right">
        <?php if ($navStreakInfo && (int)$navStreakInfo['streak'] > 0): ?>
            <span class="nav-streak-chip" title="Connexions consécutives — récompense aux paliers 3 / 7 / 14 / 30 jours">
                🔥 <?= (int)$navStreakInfo['streak'] ?>
            </span>
        <?php endif; ?>
        <?php if ($navBrute): ?>
            <a href="brute.php?id=<?= (int)$navBrute['id'] ?>" class="nav-brute-chip">
                <span class="chip-name"><?= h($navBrute['name']) ?></span>
                <span class="chip-lvl">Niv.<?= (int)$navBrute['level'] ?></span>
                <?php if ($navInboxCount > 0): ?><span class="nav-badge"><?= $navInboxCount ?></span><?php endif; ?>
            </a>
        <?php endif; ?>
        <button id="chat-btn" class="chat-btn" aria-label="Chat global" aria-expanded="false" title="Chat de l'arène">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/>
            </svg>
            <span class="chat-new-badge" id="chat-new-badge" hidden>•</span>
        </button>
        <?php if ($navBrute): ?>
        <button id="notif-btn" class="notif-btn" aria-label="Notifications" aria-expanded="false" title="Centre de notifications">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-6v-5c0-3.07-1.64-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.63 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z"/>
            </svg>
            <span class="notif-badge" id="notif-badge-srv"<?= $navNotifBadge <= 0 ? ' hidden' : '' ?>><?= $navNotifBadge > 9 ? '9+' : $navNotifBadge ?></span>
        </button>
        <?php endif; ?>
    </div>
</nav>

<script src="../assets/js/sfx.js" defer></script>
<script src="../assets/js/music.js" defer></script>
<script src="../assets/js/toast.js" defer></script>
<script src="../assets/js/chat.js" defer></script>
<script src="https://unpkg.com/htmx.org@2.0.4/dist/htmx.min.js" defer></script>
<script>
// PWA — injection manifest + theme-color + enregistrement service worker
(function () {
    var head = document.head;
    if (!head.querySelector('link[rel="manifest"]')) {
        var lnk = document.createElement('link');
        lnk.rel  = 'manifest';
        lnk.href = 'manifest.json';
        head.appendChild(lnk);
    }
    if (!head.querySelector('meta[name="theme-color"]')) {
        var mc = document.createElement('meta');
        mc.name    = 'theme-color';
        mc.content = '#0f0a07';
        head.appendChild(mc);
    }
    if (!head.querySelector('meta[name="mobile-web-app-capable"]')) {
        var mw = document.createElement('meta');
        mw.name    = 'mobile-web-app-capable';
        mw.content = 'yes';
        head.appendChild(mw);
        var mwa = document.createElement('meta');
        mwa.name    = 'apple-mobile-web-app-capable';
        mwa.content = 'yes';
        head.appendChild(mwa);
    }
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('sw.js').catch(function () {});
    }
})();
</script>
<div id="htmx-bar" class="htmx-loading-bar" aria-hidden="true"></div>
<script>
// Navigation HTMX programmatique — remplace window.location.href pour garder l'AudioContext vivant
window.arenaNavigate = function (url) {
    if (window.htmx) {
        history.pushState(null, '', url);
        htmx.ajax('GET', url, { target: 'main', swap: 'outerHTML', select: 'main' });
    } else {
        window.location.href = url;
    }
};

document.addEventListener('htmx:beforeRequest', function () {
    var bar = document.getElementById('htmx-bar');
    if (bar) bar.classList.add('htmx-loading--active');
});
document.addEventListener('htmx:afterSwap', function () {
    var bar = document.getElementById('htmx-bar');
    if (bar) bar.classList.remove('htmx-loading--active');
    // Met à jour la classe body depuis data-body-class du nouveau <main>
    var main = document.querySelector('main');
    var pageClass = main ? (main.dataset.bodyClass || '') : '';
    document.body.classList.remove('fight-page', 'boss-page');
    if (pageClass) document.body.classList.add(pageClass);
    // Met à jour le lien actif dans le drawer
    var page = window.location.pathname.split('/').pop().replace(/\.php$/, '') || 'index';
    document.querySelectorAll('.nav-drawer a[href]').forEach(function (a) {
        var ap = a.getAttribute('href').split('/').pop().replace(/\?.*$/, '').replace(/\.php$/, '');
        a.classList.toggle('nav-active', ap === page);
    });
    // Informe le système de musique du changement de page
    if (window.MUSIC && typeof window.MUSIC.pageChanged === 'function') {
        window.MUSIC.pageChanged();
    }
});
</script>
<script>
(function() {
    const toggle  = document.getElementById('nav-toggle');
    const close   = document.getElementById('nav-close');
    const overlay = document.querySelector('.nav-overlay');
    const body    = document.body;

    function openNav()  { body.classList.add('nav-open');    toggle.setAttribute('aria-expanded', 'true'); }
    function closeNav() { body.classList.remove('nav-open'); toggle.setAttribute('aria-expanded', 'false'); }

    toggle.addEventListener('click',  () => body.classList.contains('nav-open') ? closeNav() : openNav());
    close.addEventListener('click',   closeNav);
    overlay.addEventListener('click', closeNav);
    document.addEventListener('keydown', e => { if (e.key === 'Escape') closeNav(); });

    // Ferme le drawer sur navigation (clic sur un lien)
    document.querySelectorAll('.nav-drawer a').forEach(a => {
        a.addEventListener('click', () => { if (window.SFX) SFX.play('click'); closeNav(); });
    });
})();
</script>

<?php if ($navStreakReward): ?>
<script>
window.addEventListener('DOMContentLoaded', () => {
    if (window.Toast) {
        window.Toast.queue([{
            title: '🔥 Streak <?= (int)$navStreakInfo['streak'] ?> jours !',
            description: <?= json_encode($navStreakReward['label'] . ' — +' . $navStreakReward['gold'] . ' or' . ($navStreakReward['bonus_fights'] ? ', +' . $navStreakReward['bonus_fights'] . ' combat(s) bonus' : '')) ?>,
            icon_path: 'assets/svg/quests/fire.svg',
        }]);
    }
});
</script>
<?php endif; ?>
<?php include __DIR__ . '/_tutorial.php'; ?>

<?php if (!empty($navChangelog)): ?>
<div id="changelog-modal" class="changelog-modal">
    <div class="changelog-modal-inner">
        <div class="changelog-header">
            <span class="changelog-badge">📜 Nouveautés</span>
            <h2>Bienvenue, gladiateur</h2>
            <p class="muted">Voici ce qui a changé depuis ta dernière visite.</p>
        </div>
        <div class="changelog-body">
            <?php foreach ($navChangelog as $entry): ?>
            <article class="changelog-entry">
                <header class="changelog-entry-head">
                    <span class="changelog-version">v<?= h($entry['version']) ?></span>
                    <h3><?= h($entry['title']) ?></h3>
                    <span class="changelog-date muted small"><?= h($entry['date']) ?></span>
                </header>
                <ul class="changelog-items">
                    <?php foreach ($entry['items'] as $item): ?>
                    <li>
                        <span class="changelog-item-icon"><?= $item['icon'] ?></span>
                        <span class="changelog-item-text"><?= $item['text'] ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </article>
            <?php endforeach; ?>
        </div>
        <div class="changelog-footer">
            <button class="btn btn-primary" id="changelog-dismiss"
                    data-csrf="<?= h(csrf_token()) ?>"
                    data-version="<?= h(changelog_latest_version()) ?>">
                Compris, retour à l'arène
            </button>
        </div>
    </div>
</div>
<script>
(function () {
    const modal    = document.getElementById('changelog-modal');
    const btn      = document.getElementById('changelog-dismiss');
    if (!modal || !btn) return;

    document.body.style.overflow = 'hidden';

    btn.addEventListener('click', async () => {
        btn.disabled = true;
        btn.textContent = '⏳ Sauvegarde...';
        const fd = new FormData();
        fd.append('csrf', btn.dataset.csrf);
        try {
            await fetch('../api/changelog_seen.php', { method: 'POST', body: fd });
        } catch {}
        modal.style.opacity = '0';
        setTimeout(() => {
            modal.remove();
            document.body.style.overflow = '';
        }, 300);
    });
})();
</script>
<?php endif; ?>

<?php if ($navBrute): ?>
<!-- ============ NOTIFICATION CENTER ============ -->
<div id="notif-overlay" class="notif-overlay" aria-hidden="true"></div>
<aside id="notif-panel" class="notif-panel" aria-label="Centre de notifications" aria-hidden="true">
    <div class="notif-panel-head">
        <span class="notif-panel-title">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-6v-5c0-3.07-1.64-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.63 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z"/>
            </svg>
            Notifications
        </span>
        <button class="notif-panel-close" id="notif-panel-close" aria-label="Fermer les notifications">✕</button>
    </div>
    <div class="notif-panel-body" id="notif-body">
        <div class="notif-spinner">
            <span class="notif-spinner-icon">⚔</span>
            <p>Les oracles délibèrent…</p>
        </div>
    </div>
</aside>
<script src="../assets/js/notifications.js" defer></script>
<?php endif; ?>

<!-- ============ CHAT GLOBAL ============ -->
<div id="chat-overlay" class="chat-overlay" aria-hidden="true"></div>
<aside id="chat-panel" class="chat-panel" aria-label="Chat de l'arène" aria-hidden="true">
    <div class="chat-panel-head">
        <span class="chat-panel-title">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/>
            </svg>
            Chat de l'arène
        </span>
        <button class="chat-panel-close" id="chat-close" aria-label="Fermer le chat">✕</button>
    </div>
    <div class="chat-body" id="chat-body"></div>
    <?php if ($navBrute): ?>
    <form id="chat-form" class="chat-form" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input id="chat-input" class="chat-input" type="text"
               placeholder="Ton message…" maxlength="200" autocomplete="off">
        <button type="submit" class="chat-send" aria-label="Envoyer">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
                <path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/>
            </svg>
        </button>
    </form>
    <?php else: ?>
    <p class="chat-guest">
        <a href="index.php">Connecte-toi</a> pour participer au chat.
    </p>
    <?php endif; ?>
</aside>
