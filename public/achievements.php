<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/achievement_engine.php';
require_login();

$brute = current_brute();
if (!$brute) {
    header('Location: dashboard.php');
    exit;
}

$bruteId = (int)$brute['id'];
$grouped = get_all_achievements_for_brute($bruteId);
$stats   = achievement_stats($bruteId);
$pct     = $stats['total'] > 0 ? (int)round($stats['earned'] * 100 / $stats['total']) : 0;

$CATEGORY_LABELS = [
    'combat'      => ['Combat',        'assets/svg/quests/sword.svg'],
    'progression' => ['Progression',   'assets/svg/ui/nav_ranking.svg'],
    'collection'  => ['Collection',    'assets/svg/weapons/sword.svg'],
    'social'      => ['Social',        'assets/svg/ui/nav_pupils.svg'],
    'tournament'  => ['Tournoi',       'assets/svg/quests/trophy.svg'],
    'forge'       => ['Forge',         'assets/svg/weapons/axe.svg'],
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Trophées – ArenaForge</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" href="/ArenaForge/assets/svg/logo/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="/ArenaForge/assets/css/main.css">
</head>
<body>
<?php include __DIR__ . '/_nav.php'; ?>

<main class="wrap">
    <section class="card">
        <h1><img src="/ArenaForge/assets/svg/quests/trophy.svg" alt="" class="inline-icon"> Trophées</h1>
        <p class="muted">
            Objectifs permanents qui récompensent les faits d'armes marquants de ta carrière.
            Chaque trophée débloqué rapporte de l'XP bonus immédiatement.
        </p>

        <div class="achievement-summary">
            <div class="bar xp"><div class="bar-fill" style="width:<?= $pct ?>%"></div></div>
            <span><?= $stats['earned'] ?> / <?= $stats['total'] ?> (<?= $pct ?>%)</span>
        </div>
    </section>

    <?php foreach ($CATEGORY_LABELS as $catKey => [$catLabel, $catIcon]): ?>
        <?php if (empty($grouped[$catKey])) continue; ?>
        <section class="card">
            <h2><img src="/ArenaForge/<?= h($catIcon) ?>" alt="" class="inline-icon"> <?= h($catLabel) ?></h2>
            <div class="achievement-grid">
                <?php foreach ($grouped[$catKey] as $a): ?>
                    <?php $unlocked = !empty($a['unlocked_at']); ?>
                    <div class="achievement <?= $unlocked ? 'achievement-unlocked' : 'achievement-locked' ?>">
                        <img class="achievement-icon" src="/ArenaForge/<?= h($a['icon_path']) ?>" alt="">
                        <div class="achievement-body">
                            <strong><?= h($a['title']) ?></strong>
                            <p><?= h($a['description']) ?></p>
                            <?php if ($unlocked): ?>
                                <small class="achievement-date">Obtenu le <?= h(date('d/m/Y', strtotime((string)$a['unlocked_at']))) ?></small>
                            <?php endif; ?>
                        </div>
                        <div class="achievement-reward">
                            <?php if ((int)$a['reward_xp'] > 0): ?>
                                <span class="reward-xp">+<?= (int)$a['reward_xp'] ?> XP</span>
                            <?php endif; ?>
                            <?php if ($unlocked): ?>
                                <span class="achievement-status">✓</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>
</main>
</body>
</html>
