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

$cp = basename($_SERVER['PHP_SELF'], '.php'); // page courante pour lien actif
function nav_active(string $page, string $current): string {
    return $page === $current ? ' nav-active' : '';
}
?>
<div class="nav-overlay"></div>

<aside class="nav-drawer" aria-label="Menu principal">
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
            <li><a href="codex.php" class="<?= nav_active('codex', $cp) ?>">
                <img src="../assets/svg/ui/scroll.svg" alt=""> Codex Ancien
            </a></li>
            <li><a href="ranking.php" class="<?= nav_active('ranking', $cp) ?>">
                <img src="../assets/svg/ui/nav_ranking.svg" alt=""> Panthéon
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
        <a href="logout.php" class="logout">
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
    </div>
</nav>

<script src="../assets/js/sfx.js" defer></script>
<script src="../assets/js/toast.js" defer></script>
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
