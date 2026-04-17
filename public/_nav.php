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
        <?php endif; ?>
        <li><a href="/ArenaForge/public/ranking.php">
            <img src="/ArenaForge/assets/svg/ui/nav_ranking.svg" alt=""> Classement
        </a></li>
        <?php if ($navBrute): ?>
            <li><a href="/ArenaForge/public/brute.php?id=<?= (int)$navBrute['id'] ?>#pupils">
                <img src="/ArenaForge/assets/svg/ui/nav_pupils.svg" alt=""> Pupilles
            </a></li>
        <?php endif; ?>
        <li><a href="/ArenaForge/public/logout.php">
            <img src="/ArenaForge/assets/svg/ui/nav_settings.svg" alt=""> Déconnexion
        </a></li>
    </ul>
</nav>
