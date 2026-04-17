<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/brute_generator.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM brutes WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$brute = $stmt->fetch();

if (!$brute) {
    http_response_code(404);
    echo 'Gladiateur introuvable.';
    exit;
}

$isOwner = ((int)$brute['user_id'] === current_user_id());

// Armes / compétences
$weapons = db()->prepare('SELECT w.* FROM weapons w JOIN brute_weapons bw ON bw.weapon_id=w.id WHERE bw.brute_id=?');
$weapons->execute([$id]);
$weapons = $weapons->fetchAll();

$skills = db()->prepare('SELECT s.* FROM skills s JOIN brute_skills bs ON bs.skill_id=s.id WHERE bs.brute_id=?');
$skills->execute([$id]);
$skills = $skills->fetchAll();

// Historique des 10 derniers combats
$history = db()->prepare('
    SELECT f.*, b1.name AS n1, b2.name AS n2
    FROM fights f
    JOIN brutes b1 ON b1.id = f.brute1_id
    JOIN brutes b2 ON b2.id = f.brute2_id
    WHERE f.brute1_id = ? OR f.brute2_id = ?
    ORDER BY f.created_at DESC
    LIMIT 10
');
$history->execute([$id, $id]);
$history = $history->fetchAll();

// Pupilles
$pupils = db()->prepare('
    SELECT b.id, b.name, b.level FROM pupils p
    JOIN brutes b ON b.id = p.pupil_id
    WHERE p.master_id = ?
    ORDER BY b.level DESC
');
$pupils->execute([$id]);
$pupils = $pupils->fetchAll();

// Proposition de bonus level-up
$bonusChoices = [];
if ($isOwner && (int)$brute['pending_levelup'] === 1) {
    $pool = [];
    foreach (['hp_max' => '+5 PV max', 'strength' => '+1 Force', 'agility' => '+1 Agilité', 'endurance' => '+1 Endurance'] as $k => $lbl) {
        $pool[] = ['key' => "stat:$k", 'label' => $lbl, 'icon' => '/ArenaForge/assets/svg/ui/nav_fight.svg'];
    }
    // Armes non possédées
    $ownedW = array_column($weapons, 'id');
    $allW = db()->query('SELECT * FROM weapons')->fetchAll();
    foreach ($allW as $w) {
        if (!in_array((int)$w['id'], array_map('intval', $ownedW), true)) {
            $pool[] = ['key' => 'weapon:' . $w['id'], 'label' => 'Arme : ' . $w['name'], 'icon' => '/ArenaForge/' . $w['icon_path']];
        }
    }
    // Compétences non possédées
    $ownedS = array_column($skills, 'id');
    $allS = db()->query('SELECT * FROM skills')->fetchAll();
    foreach ($allS as $s) {
        if (!in_array((int)$s['id'], array_map('intval', $ownedS), true)) {
            $pool[] = ['key' => 'skill:' . $s['id'], 'label' => $s['name'] . ' — ' . $s['description'], 'icon' => '/ArenaForge/' . $s['icon_path']];
        }
    }
    shuffle($pool);
    $bonusChoices = array_slice($pool, 0, 3);
}

$csrf = csrf_token();
$xpCur  = (int)$brute['xp'];
$xpNext = xp_for_level((int)$brute['level'] + 1);
$xpPrev = xp_for_level((int)$brute['level']);
$xpPct  = $xpNext > $xpPrev ? max(0, min(100, (int)round(($xpCur - $xpPrev) * 100 / ($xpNext - $xpPrev)))) : 0;

$appearance = json_decode((string)$brute['appearance_seed'], true) ?: [];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title><?= h($brute['name']) ?> – ArenaForge</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" href="/ArenaForge/assets/svg/logo/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="/ArenaForge/assets/css/main.css">
</head>
<body>
<?php include __DIR__ . '/_nav.php'; ?>

<main class="wrap">
    <section class="card brute-card">
        <div class="brute-portrait">
            <?php include __DIR__ . '/_gladiator.php'; ?>
        </div>
        <div class="brute-info">
            <h1><?= h($brute['name']) ?> <span class="level">Niv. <?= (int)$brute['level'] ?></span></h1>

            <div class="bars">
                <div class="bar hp">
                    <div class="bar-fill" style="width:100%"></div>
                    <span class="bar-label"><?= (int)$brute['hp_max'] ?> / <?= (int)$brute['hp_max'] ?> PV</span>
                </div>
                <div class="bar xp">
                    <div class="bar-fill" style="width: <?= $xpPct ?>%"></div>
                    <span class="bar-label"><?= $xpCur ?> / <?= $xpNext ?> XP</span>
                </div>
            </div>

            <ul class="stats">
                <li><span>Force</span><strong><?= (int)$brute['strength'] ?></strong></li>
                <li><span>Agilité</span><strong><?= (int)$brute['agility'] ?></strong></li>
                <li><span>Endurance</span><strong><?= (int)$brute['endurance'] ?></strong></li>
            </ul>

            <?php if ($isOwner): ?>
                <?php
                  $fightsLeft = 6 - ((int)$brute['fights_today']);
                  if ($brute['last_fight_date'] !== date('Y-m-d')) { $fightsLeft = 6; }
                ?>
                <p class="fights-left"><?= max(0,$fightsLeft) ?> combat(s) restant(s) aujourd'hui</p>
                <?php if ((int)$brute['pending_levelup'] === 1): ?>
                    <p class="levelup-alert">Niveau gagné ! Choisis ton bonus ci-dessous.</p>
                <?php else: ?>
                    <form id="fight-form">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="brute_id" value="<?= (int)$brute['id'] ?>">
                        <button class="btn btn-primary btn-large" <?= $fightsLeft <= 0 ? 'disabled' : '' ?>>
                            ⚔ Lancer un combat
                        </button>
                        <p class="form-msg" data-msg></p>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </section>

    <?php if ($isOwner && !empty($bonusChoices)): ?>
        <section class="card levelup-card">
            <h2>Choisis ton bonus de niveau</h2>
            <div class="bonus-grid">
                <?php foreach ($bonusChoices as $b): ?>
                    <form class="bonus-choice levelup-form">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="brute_id" value="<?= (int)$brute['id'] ?>">
                        <input type="hidden" name="choice" value="<?= h($b['key']) ?>">
                        <img src="<?= h($b['icon']) ?>" alt="">
                        <span><?= h($b['label']) ?></span>
                        <button class="btn btn-secondary">Choisir</button>
                    </form>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <section class="card">
        <h2>Arsenal</h2>
        <div class="icon-grid">
            <?php foreach ($weapons as $w): ?>
                <div class="icon-item" title="<?= h($w['name']) ?> (<?= (int)$w['damage_min'] ?>-<?= (int)$w['damage_max'] ?> dég.)">
                    <img src="/ArenaForge/<?= h($w['icon_path']) ?>" alt="<?= h($w['name']) ?>">
                    <span><?= h($w['name']) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="card">
        <h2>Compétences</h2>
        <?php if (empty($skills)): ?>
            <p class="muted">Aucune compétence apprise.</p>
        <?php else: ?>
            <div class="icon-grid">
                <?php foreach ($skills as $s): ?>
                    <div class="icon-item" title="<?= h($s['description']) ?>">
                        <img src="/ArenaForge/<?= h($s['icon_path']) ?>" alt="<?= h($s['name']) ?>">
                        <span><?= h($s['name']) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="card">
        <h2>Derniers combats</h2>
        <?php if (empty($history)): ?>
            <p class="muted">Aucun combat pour l'instant.</p>
        <?php else: ?>
            <ul class="history">
                <?php foreach ($history as $f): ?>
                    <?php $won = (int)$f['winner_id'] === $id; ?>
                    <li class="<?= $won ? 'win' : 'loss' ?>">
                        <a href="fight.php?id=<?= (int)$f['id'] ?>">
                            <?= h($f['n1']) ?> vs <?= h($f['n2']) ?>
                            <span class="result"><?= $won ? 'Victoire' : 'Défaite' ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="card" id="pupils">
        <h2>Pupilles</h2>
        <?php if (empty($pupils)): ?>
            <p class="muted">Aucun pupille pour l'instant.</p>
        <?php else: ?>
            <ul class="pupil-list">
                <?php foreach ($pupils as $p): ?>
                    <li><a href="brute.php?id=<?= (int)$p['id'] ?>"><?= h($p['name']) ?> (Niv. <?= (int)$p['level'] ?>)</a></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</main>

<script>window.APPEARANCE = <?= json_encode($appearance) ?>;</script>
<script src="/ArenaForge/assets/js/brute.js"></script>
</body>
</html>
