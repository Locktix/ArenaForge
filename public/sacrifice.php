<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/sacrifice_engine.php';
require_login();

$brute = current_brute();
if (!$brute) { header('Location: dashboard.php'); exit; }
$bruteId = (int)$brute['id'];
$csrf    = csrf_token();

$availability = sacrifice_availability_map($bruteId);
$history      = get_sacrifice_history($bruteId, 10);
$leaderboard  = get_sacrifice_leaderboard(10);
$myRank       = get_sacrifice_rank($bruteId);

function sacrifice_outcome_class(string $code): string {
    return match($code) {
        'jackpot' => 'sac-out-jackpot',
        'good'    => 'sac-out-good',
        'bad'     => 'sac-out-bad',
        'nothing' => 'sac-out-nothing',
        'weapon'  => 'sac-out-jackpot',
        default   => 'sac-out-good',
    };
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>L'Autel — ArenaForge</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" href="../assets/svg/logo/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="../assets/css/main.css">
</head>
<body>
<?php include __DIR__ . '/_nav.php'; ?>

<main class="wrap sacrifice-page">

    <section class="card sacrifice-intro">
        <h1>☠ L'Autel des Sacrifices</h1>
        <p class="muted">
            Échange une ressource certaine contre un gain incertain. Les dieux de l'arène apprécient l'audace —
            mais ils ne récompensent pas toujours ceux qui les invoquent. Chaque rituel est irréversible.
        </p>
    </section>

    <?php $lockedByLevel = (int)$brute['level'] < 10; ?>
    <?php if ($lockedByLevel): ?>
    <section class="card sacrifice-locked">
        <span class="sacrifice-locked-icon">🔒</span>
        <div>
            <strong>Niveau 10 requis</strong>
            <p class="muted">Seuls les gladiateurs aguerris peuvent approcher l'autel. Reviens quand tu auras prouvé ta valeur en arène.</p>
            <div class="sacrifice-locked-progress">
                <div class="sacrifice-locked-bar" style="width:<?= min(100, round((int)$brute['level'] / 10 * 100)) ?>%"></div>
            </div>
            <span class="muted small">Niveau <?= (int)$brute['level'] ?> / 10</span>
        </div>
    </section>
    <?php endif; ?>

    <section class="sacrifice-grid<?= $lockedByLevel ? ' sacrifice-grid-locked' : '' ?>">
    <?php foreach (SACRIFICE_DEFS as $type => $def):
        $isAvailable = $availability[$type] ?? false;
        [$canAfford, $affordErr] = sacrifice_can_afford($brute, $type);
        $isGrand = $def['cooldown'] === 'weekly';
    ?>
        <div class="sacrifice-card<?= $isGrand ? ' sacrifice-grand' : '' ?><?= !$isAvailable ? ' sacrifice-done' : '' ?>"
             data-type="<?= h($type) ?>">

            <div class="sacrifice-head">
                <span class="sacrifice-icon"><?= $def['icon'] ?></span>
                <div>
                    <h2><?= h($def['name']) ?></h2>
                    <span class="sacrifice-cd"><?= $isGrand ? '1 fois par semaine' : '1 fois par jour' ?></span>
                </div>
            </div>

            <p class="sacrifice-desc"><?= h($def['description']) ?></p>

            <div class="sacrifice-cost">
                <span class="sacrifice-cost-label">Coût</span>
                <strong><?= h($def['cost_label']) ?></strong>
            </div>

            <div class="sacrifice-outcomes">
                <span class="sacrifice-cost-label">Résultats possibles</span>
                <ul>
                <?php foreach ($def['outcomes'] as $o): ?>
                    <li class="<?= sacrifice_outcome_class($o['code']) ?>">
                        <span class="sac-weight"><?= (int)$o['weight'] ?>%</span>
                        <span class="sac-label"><?= h($o['label']) ?></span>
                    </li>
                <?php endforeach; ?>
                </ul>
            </div>

            <div class="sacrifice-action">
                <?php if ($lockedByLevel): ?>
                    <button class="btn btn-secondary" disabled>Niveau 10 requis</button>
                <?php elseif (!$isAvailable): ?>
                    <button class="btn btn-secondary" disabled>
                        <?= $isGrand ? 'Rituel hebdomadaire accompli' : 'Rituel du jour accompli' ?>
                    </button>
                <?php elseif (!$canAfford): ?>
                    <button class="btn btn-secondary" disabled><?= h($affordErr) ?></button>
                <?php else: ?>
                    <form class="sacrifice-form" data-type="<?= h($type) ?>" data-name="<?= h($def['name']) ?>">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="brute_id" value="<?= $bruteId ?>">
                        <input type="hidden" name="type" value="<?= h($type) ?>">
                        <button class="btn btn-primary<?= $isGrand ? ' btn-hero' : '' ?>" type="submit">
                            <?= $isGrand ? '☠ Invoquer le Grand Rituel' : 'Accomplir le rituel' ?>
                        </button>
                    </form>
                <?php endif; ?>
                <p class="form-msg" data-msg></p>
            </div>
        </div>
    <?php endforeach; ?>
    </section>

    <?php if (!empty($leaderboard)): ?>
    <section class="card sacrifice-leaderboard-card">
        <h2>🔥 Les Plus Téméraires</h2>
        <p class="muted small">
            Score d'Audace : chaque rituel quotidien vaut 1 point, chaque Grand Sacrifice en vaut 5.
            Seuls les plus intrépides figurent ici.
        </p>
        <div class="sac-lb-list">
            <?php foreach ($leaderboard as $i => $row):
                $isMe = ((int)$row['id'] === $bruteId);
                $rank = $i + 1;
            ?>
            <div class="sac-lb-row<?= $isMe ? ' is-me' : '' ?><?= $rank <= 3 ? ' sac-lb-podium sac-lb-rank-'.$rank : '' ?>">
                <span class="sac-lb-rank">#<?= $rank ?></span>
                <a href="brute.php?id=<?= (int)$row['id'] ?>" class="sac-lb-name">
                    <?= h((string)$row['name']) ?>
                </a>
                <span class="sac-lb-level muted small">Niv. <?= (int)$row['level'] ?></span>
                <span class="sac-lb-score">
                    <strong><?= (int)$row['audacity_score'] ?></strong>
                    <small>Audace</small>
                </span>
                <span class="sac-lb-breakdown muted small">
                    <?= (int)$row['total_sacrifices'] ?> rituel<?= (int)$row['total_sacrifices'] > 1 ? 's' : '' ?>
                    <?php if ((int)$row['grand_count'] > 0): ?>
                        · <?= (int)$row['grand_count'] ?> ☠
                    <?php endif; ?>
                    <?php if ((int)$row['jackpot_count'] > 0): ?>
                        · ✨ <?= (int)$row['jackpot_count'] ?>
                    <?php endif; ?>
                    <?php if ((int)$row['nothing_count'] + (int)$row['bad_count'] > 0): ?>
                        · 💀 <?= (int)$row['nothing_count'] + (int)$row['bad_count'] ?>
                    <?php endif; ?>
                </span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php if ($myRank && $myRank['rank'] > 10): ?>
        <p class="sac-lb-myrank muted small">
            Ton rang : <strong>#<?= $myRank['rank'] ?></strong> ·
            <?= $myRank['audacity_score'] ?> Audace ·
            <?= $myRank['total_sacrifices'] ?> rituel<?= $myRank['total_sacrifices'] > 1 ? 's' : '' ?>
        </p>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if (!empty($history)): ?>
    <section class="card">
        <h2>📜 Chroniques des Rituels</h2>
        <p class="muted small">Tes 10 derniers sacrifices.</p>
        <div class="sacrifice-history">
            <?php foreach ($history as $row):
                $def = SACRIFICE_DEFS[$row['type']] ?? null;
                if (!$def) continue;
                $cls = sacrifice_outcome_class($row['outcome_code']);
            ?>
            <div class="sac-hist-row <?= $cls ?>">
                <span class="sac-hist-icon"><?= $def['icon'] ?></span>
                <span class="sac-hist-name"><?= h($def['name']) ?></span>
                <span class="sac-hist-label"><?= h($row['outcome_label']) ?></span>
                <span class="sac-hist-date muted small"><?= date('d/m H\hi', strtotime($row['created_at'])) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>
<!-- Modal de confirmation -->
<div id="sacrifice-confirm" class="sacrifice-modal" hidden>
    <div class="sacrifice-modal-inner sacrifice-confirm-inner">
        <div class="sac-confirm-icon"></div>
        <h2 class="sac-confirm-title">Confirmer le rituel ?</h2>
        <p class="sac-confirm-name"></p>
        <div class="sac-confirm-cost">
            <span class="muted small">Coût irréversible</span>
            <strong class="sac-confirm-cost-text"></strong>
        </div>
        <p class="sac-confirm-warning muted small">
            Une fois invoqué, ce sacrifice ne peut être annulé. Les dieux ne reviennent jamais sur leur jugement.
        </p>
        <div class="sac-confirm-actions">
            <button class="btn btn-secondary" id="sac-confirm-cancel">Renoncer</button>
            <button class="btn btn-primary" id="sac-confirm-validate">Accomplir le rituel</button>
        </div>
    </div>
</div>

<!-- Modal de révélation -->
<div id="sacrifice-modal" class="sacrifice-modal" hidden>
    <div class="sacrifice-modal-inner">
        <div id="sac-reveal-stage" class="sac-reveal-stage">
            <div class="sac-reveal-icon">☠</div>
            <p class="sac-reveal-msg">Les dieux délibèrent...</p>
        </div>
        <div id="sac-reveal-result" class="sac-reveal-result" hidden>
            <div class="sac-reveal-result-icon"></div>
            <h2 class="sac-reveal-title"></h2>
            <p class="sac-reveal-label"></p>
            <button class="btn btn-primary" id="sac-reveal-close">Continuer</button>
        </div>
    </div>
</div>

<script src="../assets/js/sacrifice.js"></script>
</main>
</body>
</html>
