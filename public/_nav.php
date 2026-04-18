<?php
// Barre de navigation partagée
if (!function_exists('current_user_id')) {
    require_once __DIR__ . '/../includes/auth.php';
}
$navBrute = current_brute();
?>
<nav class="topnav">
    <a class="brand" href="/ArenaForge/public/dashboard.php">
        <img src="/ArenaForge/assets/svg/logo/logo.svg" alt="ArenaForge" class="brand-logo">
    </a>
    <ul class="nav-links">
        <?php if ($navBrute): ?>
            <li><a href="/ArenaForge/public/brute.php?id=<?= (int)$navBrute['id'] ?>">
                <img src="/ArenaForge/assets/svg/ui/nav_fight.svg" alt=""> Combat
            </a></li>
            <li><a href="/ArenaForge/public/tournament.php">
                <img src="/ArenaForge/assets/svg/ui/trophy.svg" alt=""> Tournoi
            </a></li>
            <li><a href="/ArenaForge/public/quests.php">
                <img src="/ArenaForge/assets/svg/ui/scroll.svg" alt=""> Quêtes
            </a></li>
            <li><a href="/ArenaForge/public/achievements.php">
                <img src="/ArenaForge/assets/svg/ui/trophy.svg" alt=""> Trophées
            </a></li>
            <li><a href="/ArenaForge/public/forge.php">
                <img src="/ArenaForge/assets/svg/weapons/axe.svg" alt=""> Forge
            </a></li>
            <li><a href="/ArenaForge/public/clans.php">
                <img src="/ArenaForge/assets/svg/ui/nav_pupils.svg" alt=""> Clans
            </a></li>
        <?php endif; ?>
        <li><a href="/ArenaForge/public/ranking.php">
            <img src="/ArenaForge/assets/svg/ui/nav_ranking.svg" alt=""> Classement
        </a></li>
        <?php if ($navBrute): ?>
            <li><a href="/ArenaForge/public/pupils.php">
                <img src="/ArenaForge/assets/svg/ui/nav_pupils.svg" alt=""> Pupilles
            </a></li>
        <?php endif; ?>
        <li><a href="#" class="nav-help" title="Relancer le tutoriel" onclick="event.preventDefault(); if (window.arenaforgeTutorial) window.arenaforgeTutorial.restart();">
            <span aria-hidden="true">?</span> Aide
        </a></li>
        <li><a href="/ArenaForge/public/logout.php">
            <img src="/ArenaForge/assets/svg/ui/nav_settings.svg" alt=""> Déconnexion
        </a></li>
    </ul>
</nav>
<script src="/ArenaForge/assets/js/sfx.js" defer></script>
<script src="/ArenaForge/assets/js/toast.js" defer></script>
<?php include __DIR__ . '/_tutorial.php'; ?>
