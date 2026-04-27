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

$season  = current_season();
$me      = current_brute();
$myRank  = $me ? brute_rank((int)$me['id']) : null;
$myDiv   = $me ? elo_division_for((int)$me['mmr']) : null;
$myRew   = ($me && $season) ? season_pending_reward((int)$me['mmr'], (string)$season['label']) : null;
$pastRewards = $me ? brute_season_rewards((int)$me['id']) : [];
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

        <?php if ($me && $myRank && $myDiv): ?>
            <div class="my-rank">
                <span class="my-rank-position">#<?= (int)$myRank ?></span>
                <span class="my-rank-name"><?= h($me['name']) ?></span>
                <span class="tier-badge" style="--tier-color: <?= h($myDiv['tier']['color']) ?>">
                    <?= h($myDiv['division_label']) ?>
                </span>
                <span class="my-rank-mmr"><?= (int)$me['mmr'] ?> MMR</span>
                <?php if (!empty($myDiv['next_threshold'])): ?>
                    <div class="division-progress">
                        <div class="bar small"><div class="bar-fill" style="width:<?= (int)$myDiv['progress_pct'] ?>%; background: <?= h($myDiv['tier']['color']) ?>;"></div></div>
                        <small class="muted">→ <?= (int)$myDiv['next_threshold'] ?> MMR</small>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($myRew && $season): ?>
            <div class="season-reward-banner" style="border-color: <?= h($myDiv['tier']['color']) ?>;">
                <div>
                    <strong>🏅 Récompense de fin de saison (<?= h($season['label']) ?>)</strong>
                    <p class="muted small">Si la saison se terminait maintenant, tu recevrais :</p>
                </div>
                <div class="season-reward-payout">
                    <?php if ((int)$myRew['gold'] > 0): ?>
                        <span class="gold-pill"><?= (int)$myRew['gold'] ?> 🪙</span>
                    <?php else: ?>
                        <span class="muted">Aucune récompense — atteins Argent pour gagner de l'or.</span>
                    <?php endif; ?>
                    <?php if ($myRew['title']): ?>
                        <span class="title-pill">« <?= h($myRew['title']) ?> »</span>
                    <?php endif; ?>
                </div>
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
                <?php foreach ($rows as $i => $r): $div = elo_division_for((int)$r['mmr']); ?>
                    <tr<?= $me && (int)$r['id'] === (int)$me['id'] ? ' class="rank-me"' : '' ?>>
                        <td><?= $i + 1 ?></td>
                        <td><a href="brute.php?id=<?= (int)$r['id'] ?>"><?= h($r['name']) ?></a></td>
                        <td><span class="tier-badge" style="--tier-color: <?= h($div['tier']['color']) ?>"><?= h($div['division_label']) ?></span></td>
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

    <?php if (!empty($pastRewards)): ?>
        <section class="card">
            <h2>Tes récompenses de saison</h2>
            <ul class="season-rewards-list">
                <?php foreach ($pastRewards as $sr): ?>
                    <li>
                        <strong><?= h($sr['label']) ?></strong>
                        <span class="tier-badge tier-badge-sm">
                            <?= h(strtoupper((string)$sr['tier_code'])) ?> <?= h((string)$sr['tier_division']) ?>
                        </span>
                        <span class="muted small">MMR final <?= (int)$sr['final_mmr'] ?> · pic <?= (int)$sr['peak_mmr'] ?></span>
                        <?php if ((int)$sr['gold_awarded'] > 0): ?>
                            <span class="gold-pill"><?= (int)$sr['gold_awarded'] ?> 🪙</span>
                        <?php endif; ?>
                        <?php if (!empty($sr['title_awarded'])): ?>
                            <span class="title-pill">« <?= h($sr['title_awarded']) ?> »</span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>
</main>
</body>
</html>
