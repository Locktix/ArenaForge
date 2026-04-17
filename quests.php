<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/quest_engine.php';
require_login();

$brute = current_brute();
if (!$brute) {
    header('Location: dashboard.php');
    exit;
}

$bruteId = (int)$brute['id'];
$quests  = get_daily_quests($bruteId);
$csrf    = csrf_token();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Quêtes du jour – ArenaForge</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" href="assets/svg/logo/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="assets/css/main.css">
</head>
<body>
<?php include __DIR__ . '/_nav.php'; ?>

<main class="wrap">
    <section class="card">
        <h1><img src="assets/svg/ui/scroll.svg" alt="" class="inline-icon"> Quêtes du jour</h1>
        <p class="muted">
            3 défis renouvelés chaque jour. Chaque combat met à jour la progression.
            Réclamez la récompense pour empocher l'XP bonus.
        </p>

        <div class="quest-list">
            <?php foreach ($quests as $q): ?>
                <?php
                    $target   = (int)$q['target'];
                    $progress = (int)$q['progress'];
                    $claimed  = (int)$q['claimed'] === 1;
                    $done     = $progress >= $target;
                    $pct      = $target > 0 ? min(100, (int)round($progress * 100 / $target)) : 0;
                ?>
                <div class="quest-tile <?= $claimed ? 'quest-claimed' : ($done ? 'quest-done' : '') ?>">
                    <img class="quest-icon" src="<?= h($q['icon_path']) ?>" alt="">
                    <div class="quest-body">
                        <h3><?= h($q['label']) ?></h3>
                        <p class="muted"><?= h($q['description']) ?></p>
                        <div class="quest-progress">
                            <div class="bar xp"><div class="bar-fill" style="width:<?= $pct ?>%"></div></div>
                            <span><?= min($target, $progress) ?> / <?= $target ?></span>
                        </div>
                    </div>
                    <div class="quest-reward">
                        <span class="reward-xp">+<?= (int)$q['reward_xp'] ?> XP</span>
                        <?php if ((int)$q['reward_bonus_fights'] > 0): ?>
                            <span class="reward-bonus" title="Combat bonus">+<?= (int)$q['reward_bonus_fights'] ?> ⚔</span>
                        <?php endif; ?>
                        <?php if ($claimed): ?>
                            <span class="quest-status">Réclamée ✓</span>
                        <?php elseif ($done): ?>
                            <form class="quest-claim-form">
                                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                <input type="hidden" name="brute_id" value="<?= $bruteId ?>">
                                <input type="hidden" name="code" value="<?= h($q['quest_code']) ?>">
                                <button class="btn btn-secondary" type="submit">Réclamer</button>
                            </form>
                        <?php else: ?>
                            <span class="quest-status">En cours...</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
</main>

<script src="assets/js/quests.js"></script>
</body>
</html>
