<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare('
    SELECT f.*, b1.name AS n1, b2.name AS n2,
           b1.appearance_seed AS a1, b2.appearance_seed AS a2,
           b1.hp_max AS hp1, b2.hp_max AS hp2
    FROM fights f
    JOIN brutes b1 ON b1.id = f.brute1_id
    JOIN brutes b2 ON b2.id = f.brute2_id
    WHERE f.id = ? LIMIT 1
');
$stmt->execute([$id]);
$f = $stmt->fetch();

if (!$f) {
    http_response_code(404);
    echo 'Combat introuvable.';
    exit;
}

$log = json_decode((string)$f['log_json'], true) ?: [];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Combat #<?= (int)$f['id'] ?> – ArenaForge</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" href="/ArenaForge/assets/svg/logo/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="/ArenaForge/assets/css/main.css">
</head>
<body class="fight-page">
<?php include __DIR__ . '/_nav.php'; ?>

<main class="wrap">
    <section class="card arena-card">
        <div class="arena" id="arena">
            <div class="arena-bg"></div>
            <div class="fighter fighter-left" id="fighter1">
                <div class="name-tag"><?= h($f['n1']) ?></div>
                <div class="bar hp small"><div class="bar-fill" data-hp-bar></div></div>
                <?php $appearance = json_decode((string)$f['a1'], true) ?: []; ?>
                <div class="sprite"><?php include __DIR__ . '/_gladiator.php'; ?></div>
            </div>
            <div class="fighter fighter-right" id="fighter2">
                <div class="name-tag"><?= h($f['n2']) ?></div>
                <div class="bar hp small"><div class="bar-fill" data-hp-bar></div></div>
                <?php $appearance = json_decode((string)$f['a2'], true) ?: []; ?>
                <div class="sprite flip"><?php include __DIR__ . '/_gladiator.php'; ?></div>
            </div>
            <div class="arena-flash" id="flash"></div>
        </div>

        <div class="combat-log" id="combat-log"></div>

        <div class="combat-controls">
            <button class="btn btn-secondary" id="replay-btn">Rejouer</button>
            <a class="btn btn-primary" href="brute.php?id=<?= (int)$f['brute1_id'] ?>">Retour au gladiateur</a>
        </div>
    </section>
</main>

<script>
window.FIGHT = {
    id: <?= (int)$f['id'] ?>,
    hp1Max: <?= (int)$f['hp1'] ?>,
    hp2Max: <?= (int)$f['hp2'] ?>,
    n1: <?= json_encode($f['n1']) ?>,
    n2: <?= json_encode($f['n2']) ?>,
    winnerId: <?= (int)$f['winner_id'] ?>,
    brute1Id: <?= (int)$f['brute1_id'] ?>,
    brute2Id: <?= (int)$f['brute2_id'] ?>,
    log: <?= json_encode($log, JSON_UNESCAPED_UNICODE) ?>
};
</script>
<script src="/ArenaForge/assets/js/fight.js"></script>
</body>
</html>
