<?php
// Barre de navigation partagée
if (!function_exists('current_user_id')) {
    require_once __DIR__ . '/../includes/auth.php';
}
$navBrute = current_brute();
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
<script src="../assets/js/sfx.js" defer></script>
<script src="../assets/js/toast.js" defer></script>
<?php include __DIR__ . '/_tutorial.php'; ?>
