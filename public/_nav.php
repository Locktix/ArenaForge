<?php
if (!function_exists('current_user_id')) {
    require_once __DIR__ . '/../includes/auth.php';
}
require_once __DIR__ . '/../includes/streak_engine.php';
require_once __DIR__ . '/../includes/challenge_engine.php';

$navBrute        = current_brute();
$navUid          = current_user_id();
$navStreakInfo    = null;
$navStreakReward  = null;
$navInboxCount   = 0;

if ($navUid !== null) {
    $tick = tick_login_streak($navUid);
    $navStreakInfo  = ['streak' => (int)$tick['streak']];
    $navStreakReward = $tick['reward'];
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
        <span class="d-sub">Niveau <?= (int)$navBrute['level'] ?> · <?= (int)$navBrute['xp'] ?> XP</span>
    </div>
    <?php endif; ?>

    <?php if ($navBrute): ?>
    <div class="nav-section">
        <span class="nav-section-label">Arène</span>
        <ul>
            <li><a href="brute.php?id=<?= (int)$navBrute['id'] ?>" class="<?= nav_active('brute', $cp) ?>">
                <img src="../assets/svg/ui/nav_fight.svg" alt=""> Combat
            </a></li>
            <li><a href="tournament.php" class="<?= nav_active('tournament', $cp) ?>">
                <img src="../assets/svg/ui/trophy.svg" alt=""> Tournoi
            </a></li>
            <li><a href="boss.php" class="<?= nav_active('boss', $cp) ?>">
                <img src="../assets/svg/skills/rage.svg" alt=""> Boss du jour
            </a></li>
            <li><a href="challenges.php" class="<?= nav_active('challenges', $cp) ?>">
                <img src="../assets/svg/weapons/sword.svg" alt=""> Défis
                <?php if ($navInboxCount > 0): ?><span class="nav-badge"><?= $navInboxCount ?></span><?php endif; ?>
            </a></li>
        </ul>
    </div>

    <div class="nav-section">
        <span class="nav-section-label">Progression</span>
        <ul>
            <li><a href="quests.php" class="<?= nav_active('quests', $cp) ?>">
                <img src="../assets/svg/ui/scroll.svg" alt=""> Quêtes
            </a></li>
            <li><a href="achievements.php" class="<?= nav_active('achievements', $cp) ?>">
                <img src="../assets/svg/ui/trophy.svg" alt=""> Trophées
            </a></li>
            <li><a href="ranking.php" class="<?= nav_active('ranking', $cp) ?>">
                <img src="../assets/svg/ui/nav_ranking.svg" alt=""> Classement
            </a></li>
        </ul>
    </div>

    <div class="nav-section">
        <span class="nav-section-label">Social</span>
        <ul>
            <li><a href="pupils.php" class="<?= nav_active('pupils', $cp) ?>">
                <img src="../assets/svg/ui/nav_pupils.svg" alt=""> Pupilles
            </a></li>
            <li><a href="clans.php" class="<?= nav_active('clans', $cp) ?>">
                <img src="../assets/svg/ui/nav_pupils.svg" alt=""> Clans
            </a></li>
        </ul>
    </div>

    <div class="nav-section">
        <span class="nav-section-label">Boutique</span>
        <ul>
            <li><a href="forge.php" class="<?= nav_active('forge', $cp) ?>">
                <img src="../assets/svg/weapons/axe.svg" alt=""> Forge
            </a></li>
            <li><a href="market.php" class="<?= nav_active('market', $cp) ?>">
                <img src="../assets/svg/quests/hammer.svg" alt=""> Marché noir
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
