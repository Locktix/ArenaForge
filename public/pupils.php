<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/pupil_helper.php';
require_login();

$brute = current_brute();
if (!$brute) {
    header('Location: dashboard.php');
    exit;
}

$bruteId    = (int)$brute['id'];
$ancestors  = get_ancestors($bruteId);
$pupils     = get_descendants($bruteId);
$stats      = pupil_stats($bruteId);

const PUPIL_BONUS_THRESHOLD_UI = 10;
$bonusProgress = (int)$brute['pupil_bonus_progress'];
$bonusPct      = min(100, (int)round($bonusProgress * 100 / PUPIL_BONUS_THRESHOLD_UI));

// URL de parrainage : permet à un nouveau joueur de s'inscrire comme pupille
$hostBase   = 'http' . (!empty($_SERVER['HTTPS']) ? 's' : '') . '://' . $_SERVER['HTTP_HOST'];
$scriptDir  = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$sponsorUrl = $hostBase . $scriptDir . '/dashboard.php?master=' . urlencode($brute['name']);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Pupilles – ArenaForge</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" href="../assets/svg/logo/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="../assets/css/main.css">
</head>
<body>
<?php include __DIR__ . '/_nav.php'; ?>

<main class="wrap">
    <section class="card">
        <h1><img src="../assets/svg/ui/nav_pupils.svg" alt="" class="inline-icon"> Lignée de <?= h($brute['name']) ?></h1>
        <p class="muted">
            Chaque combat gagné ou perdu par un de vos pupilles vous rapporte 1 XP passif.
            Partagez votre lien de parrainage pour recruter des apprentis.
        </p>
        <div class="pupil-stats">
            <div class="stat-tile">
                <span class="stat-label">Pupilles directs</span>
                <strong><?= (int)$stats['direct_count'] ?></strong>
            </div>
            <div class="stat-tile">
                <span class="stat-label">XP passif accumulé</span>
                <strong><?= (int)$stats['xp_earned'] ?></strong>
            </div>
            <div class="stat-tile">
                <span class="stat-label">Prochain combat bonus</span>
                <div class="bar xp"><div class="bar-fill" style="width:<?= $bonusPct ?>%"></div></div>
                <small class="muted"><?= $bonusProgress ?> / <?= PUPIL_BONUS_THRESHOLD_UI ?> combats de pupilles</small>
            </div>
        </div>
        <div class="sponsor-link">
            <label>Votre lien de parrainage :
                <input type="text" value="<?= h($sponsorUrl) ?>" readonly onclick="this.select();">
            </label>
        </div>
    </section>

    <?php if (!empty($ancestors)): ?>
        <section class="card">
            <h2>Vos maîtres</h2>
            <div class="ancestor-chain">
                <?php foreach (array_reverse($ancestors) as $a): ?>
                    <a class="ancestor-node" href="brute.php?id=<?= (int)$a['id'] ?>">
                        <span><?= h($a['name']) ?></span>
                        <small>Niv. <?= (int)$a['level'] ?></small>
                    </a>
                    <span class="ancestor-arrow">→</span>
                <?php endforeach; ?>
                <span class="ancestor-node current">
                    <span><?= h($brute['name']) ?></span>
                    <small>Niv. <?= (int)$brute['level'] ?></small>
                </span>
            </div>
        </section>
    <?php endif; ?>

    <section class="card">
        <h2>Vos pupilles</h2>
        <?php if (empty($pupils)): ?>
            <p class="muted">Personne n'a encore rejoint votre lignée. Partagez ce lien pour recruter un apprenti :</p>
            <div class="sponsor-link">
                <input type="text" value="<?= h($sponsorUrl) ?>" readonly onclick="this.select();">
            </div>
        <?php else: ?>
            <ul class="pupil-tree">
                <?php foreach ($pupils as $p): ?>
                    <li>
                        <a class="pupil-node" href="brute.php?id=<?= (int)$p['id'] ?>">
                            <span class="pupil-name"><?= h($p['name']) ?></span>
                            <span class="pupil-level">Niv. <?= (int)$p['level'] ?></span>
                        </a>
                        <?php if (!empty($p['grand_pupils'])): ?>
                            <ul class="pupil-tree pupil-tree-sub">
                                <?php foreach ($p['grand_pupils'] as $gp): ?>
                                    <li>
                                        <a class="pupil-node" href="brute.php?id=<?= (int)$gp['id'] ?>">
                                            <span class="pupil-name"><?= h($gp['name']) ?></span>
                                            <span class="pupil-level">Niv. <?= (int)$gp['level'] ?></span>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
