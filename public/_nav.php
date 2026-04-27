<?php
// Barre de navigation partagée
if (!function_exists('current_user_id')) {
    require_once __DIR__ . '/../includes/auth.php';
}
require_once __DIR__ . '/../includes/streak_engine.php';
require_once __DIR__ . '/../includes/challenge_engine.php';

$navBrute      = current_brute();
$navUid        = current_user_id();
$navStreakInfo = null;
$navStreakReward = null;
$navInboxCount = 0;
if ($navUid !== null) {
    $tick = tick_login_streak($navUid);
    $navStreakInfo   = ['streak' => (int)$tick['streak']];
    $navStreakReward = $tick['reward'];
}
if ($navBrute) {
    try { $navInboxCount = pending_inbox_count((int)$navBrute['id']); } catch (Throwable $e) { $navInboxCount = 0; }
}
?>
<nav class="topnav">
    <a class="brand" href="dashboard.php">
        <img src="../assets/svg/logo/logo.svg" alt="ArenaForge" class="brand-logo">
    </a>
    <ul class="nav-links">
        <?php if ($navBrute): ?>
            <li><a href="brute.php?id=<?= (int)$navBrute['id'] ?>">
                <img src="../assets/svg/ui/nav_fight.svg" alt=""> Combat
            </a></li>
            <li><a href="tournament.php">
                <img src="../assets/svg/ui/trophy.svg" alt=""> Tournoi
            </a></li>
            <li><a href="quests.php">
                <img src="../assets/svg/ui/scroll.svg" alt=""> Quêtes
            </a></li>
            <li><a href="achievements.php">
                <img src="../assets/svg/ui/trophy.svg" alt=""> Trophées
            </a></li>
            <li><a href="forge.php">
                <img src="../assets/svg/weapons/axe.svg" alt=""> Forge
            </a></li>
            <li><a href="clans.php">
                <img src="../assets/svg/ui/nav_pupils.svg" alt=""> Clans
            </a></li>
            <li><a href="challenges.php" class="<?= $navInboxCount > 0 ? 'has-badge' : '' ?>">
                <img src="../assets/svg/weapons/sword.svg" alt=""> Défis
                <?php if ($navInboxCount > 0): ?>
                    <span class="nav-badge"><?= $navInboxCount ?></span>
                <?php endif; ?>
            </a></li>
            <li><a href="market.php">
                <img src="../assets/svg/decor/torch.svg" alt=""> Marché
            </a></li>
            <li><a href="boss.php">
                <img src="../assets/svg/skills/rage.svg" alt=""> Boss
            </a></li>
        <?php endif; ?>
        <li><a href="ranking.php">
            <img src="../assets/svg/ui/nav_ranking.svg" alt=""> Classement
        </a></li>
        <?php if ($navBrute): ?>
            <li><a href="pupils.php">
                <img src="../assets/svg/ui/nav_pupils.svg" alt=""> Pupilles
            </a></li>
        <?php endif; ?>
        <li><a href="#" class="nav-help" title="Relancer le tutoriel" onclick="event.preventDefault(); if (window.arenaforgeTutorial) window.arenaforgeTutorial.restart();">
            <span aria-hidden="true">?</span> Aide
        </a></li>
        <li><a href="logout.php">
            <img src="../assets/svg/ui/nav_settings.svg" alt=""> Déconnexion
        </a></li>
    </ul>
</nav>
<?php if ($navStreakInfo && (int)$navStreakInfo['streak'] > 0): ?>
    <div class="streak-pill" title="Visites consécutives — récompense aux paliers 3 / 7 / 14 / 30 jours">
        🔥 <?= (int)$navStreakInfo['streak'] ?>
    </div>
<?php endif; ?>
<script src="../assets/js/sfx.js" defer></script>
<script src="../assets/js/toast.js" defer></script>
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
