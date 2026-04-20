<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/elo_engine.php';
require_login();

$rows = db()->query('
    SELECT b.id, b.name, b.level, b.xp, b.mmr, b.peak_mmr,
           (SELECT COUNT(*) FROM fights f WHERE f.winner_id = b.id) AS wins,
           (SELECT COUNT(*) FROM fights f WHERE (f.brute1_id = b.id OR f.brute2_id = b.id) AND f.winner_id != b.id) AS losses
    FROM brutes b
    ORDER BY b.mmr DESC, b.level DESC, b.id ASC
    LIMIT 100
')->fetchAll();

$season = current_season();
$me     = current_brute();
$myRank = $me ? brute_rank((int)$me['id']) : null;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Classement – ArenaForge</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" href="../assets/svg/logo/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="../assets/css/main.css">
</head>
<body>
<?php include __DIR__ . '/_nav.php'; ?>

<main class="wrap">
    <section class="card">
        <div class="ranking-header">
            <h1>Classement</h1>
            <?php if ($season): ?>
                <span class="season-tag"><?= h($season['label']) ?></span>
            <?php endif; ?>
        </div>
        <p class="muted">Hiérarchie basée sur le MMR (match rating). Chaque victoire te fait monter, chaque défaite te fait chuter selon l'écart avec l'adversaire.</p>

        <?php if ($me && $myRank): ?>
            <?php $myTier = elo_tier_for((int)$me['mmr']); ?>
            <div class="my-rank">
                <span class="my-rank-position">#<?= (int)$myRank ?></span>
                <span class="my-rank-name"><?= h($me['name']) ?></span>
                <span class="tier-badge" style="--tier-color: <?= h($myTier['color']) ?>">
                    <?= h($myTier['label']) ?>
                </span>
                <span class="my-rank-mmr"><?= (int)$me['mmr'] ?> MMR</span>
            </div>
        <?php endif; ?>

        <table class="ranking">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Gladiateur</th>
                    <th>Palier</th>
                    <th>MMR</th>
                    <th>Pic</th>
                    <th>Niv.</th>
                    <th>V</th>
                    <th>D</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $i => $r): $tier = elo_tier_for((int)$r['mmr']); ?>
                    <tr<?= $me && (int)$r['id'] === (int)$me['id'] ? ' class="rank-me"' : '' ?>>
                        <td><?= $i + 1 ?></td>
                        <td><a href="brute.php?id=<?= (int)$r['id'] ?>"><?= h($r['name']) ?></a></td>
                        <td><span class="tier-badge" style="--tier-color: <?= h($tier['color']) ?>"><?= h($tier['label']) ?></span></td>
                        <td class="ranking-mmr"><?= (int)$r['mmr'] ?></td>
                        <td class="muted small"><?= (int)$r['peak_mmr'] ?></td>
                        <td><?= (int)$r['level'] ?></td>
                        <td><?= (int)$r['wins'] ?></td>
                        <td><?= (int)$r['losses'] ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>
</main>
</body>
</html>
