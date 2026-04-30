<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/codex_engine.php';
require_login();

$brute = current_brute();
if (!$brute) {
    header('Location: dashboard.php');
    exit;
}
$bruteId = (int)$brute['id'];
$codex   = get_codex_for_brute($bruteId);
$tab     = ($_GET['tab'] ?? 'weapon');
if (!in_array($tab, ['weapon', 'skill', 'pet'], true)) $tab = 'weapon';

function render_codex_section(string $type, array $items): void {
    $thresholds = CODEX_TIERS;
    foreach ($items as $item):
        $count = (int)($item['count'] ?? 0);
        $tierCur = (int)($item['tier_cur'] ?? 0);
        $nextThreshold = $thresholds[$tierCur] ?? null;
        $progress = $nextThreshold !== null
            ? min(100, (int)round(($count - ($thresholds[$tierCur - 1] ?? 0)) * 100 / max(1, $nextThreshold - ($thresholds[$tierCur - 1] ?? 0))))
            : 100;
?>
        <article class="codex-tile">
            <div class="codex-head">
                <img src="../<?= h($item['icon_path']) ?>" alt="" class="codex-icon">
                <div>
                    <h3><?= h($item['name']) ?></h3>
                    <p class="muted small">
                        <?= $count ?> utilisations
                        · Palier <?= $tierCur ?>/3
                    </p>
                    <div class="codex-progress">
                        <div class="bar small"><div class="bar-fill" style="width:<?= $progress ?>%"></div></div>
                        <small class="muted">
                            <?php if ($nextThreshold): ?>
                                <?= $count ?> / <?= $nextThreshold ?> pour le palier suivant
                            <?php else: ?>
                                Palier maximum atteint
                            <?php endif; ?>
                        </small>
                    </div>
                </div>
            </div>
            <?php if (!empty($item['description'])): ?>
                <p class="codex-desc"><?= h($item['description']) ?></p>
            <?php endif; ?>
            <ol class="codex-lore">
                <?php for ($i = 0; $i < 3; $i++):
                    $unlocked = $tierCur > $i;
                    $line = $item['lore'][$i] ?? '';
                ?>
                    <li class="<?= $unlocked ? 'lore-unlocked' : 'lore-locked' ?>">
                        <?php if ($unlocked && $line): ?>
                            <?= h($line) ?>
                        <?php else: ?>
                            <em class="muted">— Verrouillé —</em>
                        <?php endif; ?>
                    </li>
                <?php endfor; ?>
            </ol>
        </article>
<?php
    endforeach;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Codex — ArenaForge</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" href="../assets/svg/logo/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="../assets/css/main.css">
</head>
<body>
<?php include __DIR__ . '/_nav.php'; ?>

<main class="wrap">
    <section class="card">
        <h1><img src="../assets/svg/ui/scroll.svg" alt="" class="inline-icon"> Codex de l'arène</h1>
        <p class="muted">
            Plus tu utilises une arme, une compétence ou un compagnon, plus tu en
            apprends son histoire. Trois paliers de lore se débloquent à
            <strong><?= CODEX_TIERS[0] ?></strong>, <strong><?= CODEX_TIERS[1] ?></strong>
            et <strong><?= CODEX_TIERS[2] ?></strong> utilisations.
        </p>
    </section>

    <div class="tab-bar">
        <a href="codex.php?tab=weapon" class="tab-btn <?= $tab === 'weapon' ? 'active' : '' ?>">⚔ Armes</a>
        <a href="codex.php?tab=skill"  class="tab-btn <?= $tab === 'skill'  ? 'active' : '' ?>">✨ Compétences</a>
        <a href="codex.php?tab=pet"    class="tab-btn <?= $tab === 'pet'    ? 'active' : '' ?>">🐾 Compagnons</a>
    </div>

    <section class="card">
        <div class="codex-grid">
            <?php
            if ($tab === 'weapon')      render_codex_section('weapon', $codex['weapons']);
            elseif ($tab === 'skill')   render_codex_section('skill',  $codex['skills']);
            else                        render_codex_section('pet',    $codex['pets']);
            ?>
        </div>
    </section>
</main>
</body>
</html>
