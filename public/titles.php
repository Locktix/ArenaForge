<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/title_engine.php';
require_login();

$brute = current_brute();
if (!$brute) {
    header('Location: dashboard.php');
    exit;
}
$bruteId = (int)$brute['id'];

// Rattrapage rétroactif à chaque ouverture de page (idempotent, peu coûteux)
check_and_award_titles($bruteId);

$titles = get_brute_titles($bruteId);
$active = null;
foreach ($titles as $t) {
    if ($t['active']) { $active = $t; break; }
}

$unlockedCount = 0;
foreach ($titles as $t) if ($t['unlocked']) $unlockedCount++;
$totalCount = count($titles);
$csrf = csrf_token();

function rarity_class(string $r): string {
    return match($r) {
        'rare'        => 'title-rare',
        'epique'      => 'title-epique',
        'legendaire'  => 'title-legendaire',
        default       => 'title-rare',
    };
}
function rarity_label(string $r): string {
    return match($r) {
        'rare'        => 'Rare',
        'epique'      => 'Épique',
        'legendaire'  => 'Légendaire',
        default       => 'Rare',
    };
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Titres de gloire — ArenaForge</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" href="../assets/svg/logo/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="../assets/css/main.css">
</head>
<body>
<?php include __DIR__ . '/_nav.php'; ?>

<main class="wrap">
    <section class="card titles-intro">
        <div class="titles-intro-head">
            <h1>🏛️ Titres de Gloire</h1>
            <div class="titles-progress">
                <strong><?= $unlockedCount ?></strong> / <?= $totalCount ?> débloqués
            </div>
        </div>
        <p class="muted">
            Les titres se gagnent par exploits — non par l'écoulement du temps. Un seul peut orner ton nom à la fois.
            <strong>Son bonus s'applique à chacun de tes combats</strong> tant qu'il est porté.
        </p>

        <?php if ($active): ?>
            <div class="active-title-banner <?= rarity_class($active['rarity']) ?>">
                <img src="../<?= h($active['icon_path']) ?>" alt="">
                <div class="active-title-info">
                    <span class="active-title-label">Titre actuel</span>
                    <h2><?= h($active['label']) ?></h2>
                    <?php $bonus = title_bonus_summary($active['bonus']); ?>
                    <?php if ($bonus !== ''): ?>
                        <p class="active-title-bonus"><?= h($bonus) ?></p>
                    <?php endif; ?>
                </div>
                <form class="title-form" data-code="">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="brute_id" value="<?= $bruteId ?>">
                    <input type="hidden" name="code" value="">
                    <button type="submit" class="btn btn-outline btn-sm">Retirer</button>
                </form>
            </div>
        <?php else: ?>
            <div class="active-title-banner none">
                <span class="active-title-label">Aucun titre porté</span>
                <p class="muted small">Choisis un titre débloqué ci-dessous pour bénéficier de son bonus.</p>
            </div>
        <?php endif; ?>
    </section>

    <section class="card">
        <div class="titles-grid">
            <?php foreach ($titles as $t):
                $cls   = rarity_class($t['rarity']);
                $bonus = title_bonus_summary($t['bonus']);
            ?>
                <article class="title-tile <?= $cls ?> <?= $t['unlocked'] ? 'is-unlocked' : 'is-locked' ?> <?= $t['active'] ? 'is-active' : '' ?>">
                    <div class="title-tile-head">
                        <img src="../<?= h($t['icon_path']) ?>" alt="" class="title-icon">
                        <div>
                            <h3><?= h($t['label']) ?></h3>
                            <span class="title-rarity"><?= rarity_label($t['rarity']) ?></span>
                        </div>
                    </div>
                    <p class="title-condition"><?= h($t['description']) ?></p>
                    <?php if ($t['flavor'] !== ''): ?>
                        <p class="title-flavor">« <?= h($t['flavor']) ?> »</p>
                    <?php endif; ?>
                    <?php if ($bonus !== ''): ?>
                        <div class="title-bonus">
                            <span class="title-bonus-label">Bonus</span>
                            <span class="title-bonus-value"><?= h($bonus) ?></span>
                        </div>
                    <?php endif; ?>
                    <div class="title-tile-foot">
                        <?php if ($t['active']): ?>
                            <span class="title-status active-pill">★ Actuellement porté</span>
                        <?php elseif ($t['unlocked']): ?>
                            <form class="title-form" data-code="<?= h($t['code']) ?>">
                                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                <input type="hidden" name="brute_id" value="<?= $bruteId ?>">
                                <input type="hidden" name="code" value="<?= h($t['code']) ?>">
                                <button type="submit" class="btn btn-secondary btn-sm">Porter ce titre</button>
                            </form>
                        <?php else: ?>
                            <span class="title-status locked-pill">🔒 Verrouillé</span>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
<script src="../assets/js/titles.js"></script>
</main>
</body>
</html>
