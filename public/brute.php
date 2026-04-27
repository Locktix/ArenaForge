<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/brute_generator.php';
require_once __DIR__ . '/../includes/quest_engine.php';
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

// Compagnon animal
$pets = db()->prepare('SELECT p.* FROM pets p JOIN brute_pets bp ON bp.pet_id = p.id WHERE bp.brute_id = ? ORDER BY bp.acquired_at');
$pets->execute([$id]);
$pets = $pets->fetchAll();

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

// Quêtes du jour (pour le joueur propriétaire)
$dailyQuests = [];
if ($isOwner) {
    $dailyQuests = get_daily_quests($id);
}

// Proposition de bonus level-up
$bonusChoices = [];
if ($isOwner && (int)$brute['pending_levelup'] === 1) {
    $pool = [];
    foreach (['hp_max' => '+5 PV max', 'strength' => '+1 Force', 'agility' => '+1 Agilité', 'endurance' => '+1 Endurance'] as $k => $lbl) {
        $pool[] = ['key' => "stat:$k", 'label' => $lbl, 'icon' => '../assets/svg/ui/nav_fight.svg'];
    }
    // Armes non possédées
    $ownedW = array_column($weapons, 'id');
    $allW = db()->query('SELECT * FROM weapons')->fetchAll();
    foreach ($allW as $w) {
        if (!in_array((int)$w['id'], array_map('intval', $ownedW), true)) {
            $pool[] = ['key' => 'weapon:' . $w['id'], 'label' => 'Arme : ' . $w['name'], 'icon' => '../' . $w['icon_path']];
        }
    }
    // Compétences non possédées (les ultimes sont préfixés par ⚡)
    $ownedS = array_column($skills, 'id');
    $allS = db()->query('SELECT * FROM skills')->fetchAll();
    foreach ($allS as $s) {
        if (!in_array((int)$s['id'], array_map('intval', $ownedS), true)) {
            $isUlt = (int)($s['is_ultimate'] ?? 0) === 1;
            $label = ($isUlt ? '⚡ ULTIME — ' : '') . $s['name'] . ' — ' . $s['description'];
            $pool[] = ['key' => 'skill:' . $s['id'], 'label' => $label, 'icon' => '../' . $s['icon_path']];
        }
    }
    // Animaux : uniquement si le joueur n'en a pas encore (1 pet max)
    if (empty($pets)) {
        $allPets = db()->query('SELECT * FROM pets')->fetchAll();
        foreach ($allPets as $p) {
            $pool[] = ['key' => 'pet:' . $p['id'], 'label' => 'Compagnon : ' . $p['name'] . ' — ' . $p['description'], 'icon' => '../' . $p['icon_path']];
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
<link rel="icon" href="../assets/svg/logo/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="../assets/css/main.css">
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
                  $baseLeft = 6 - ((int)$brute['fights_today']);
                  if ($brute['last_fight_date'] !== date('Y-m-d')) { $baseLeft = 6; }
                  $baseLeft  = max(0, $baseLeft);
                  $bonusLeft = (int)$brute['bonus_fights_available'];
                  $totalLeft = $baseLeft + $bonusLeft;
                ?>
                <p class="fights-left">
                    <?= $baseLeft ?> combat(s) du jour
                    <?php if ($bonusLeft > 0): ?>
                        <span class="bonus-count" title="Gagn&eacute;s via qu&ecirc;tes, tournoi et pupilles">+ <?= $bonusLeft ?> bonus ⚔</span>
                    <?php endif; ?>
                </p>
                <p class="muted small currency-row">
                    <span title="Fragments — utilisés à la forge"><img src="../assets/svg/weapons/axe.svg" alt="" class="inline-icon-sm"> <?= (int)$brute['fragments'] ?></span>
                    <span title="Or — monnaie du marché noir">🪙 <?= (int)($brute['gold'] ?? 0) ?></span>
                </p>
                <?php if ((int)$brute['pending_levelup'] === 1): ?>
                    <p class="levelup-alert">Niveau gagné ! Choisis ton bonus ci-dessous.</p>
                <?php else: ?>
                    <form id="fight-form">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="brute_id" value="<?= (int)$brute['id'] ?>">
                        <button class="btn btn-primary btn-large" <?= $totalLeft <= 0 ? 'disabled' : '' ?>>
                            ⚔ Lancer un combat
                            <?php if ($baseLeft === 0 && $bonusLeft > 0): ?>
                                <small>(bonus)</small>
                            <?php endif; ?>
                        </button>
                        <p class="form-msg" data-msg></p>
                    </form>

                    <?php if (!empty($pupils)): ?>
                        <form id="duo-fight-form" class="duo-launch">
                            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                            <input type="hidden" name="brute_id" value="<?= (int)$brute['id'] ?>">
                            <label class="duo-partner-label">
                                Avec ton pupille
                                <select name="partner_id" class="duo-partner-select">
                                    <?php foreach ($pupils as $p): ?>
                                        <option value="<?= (int)$p['id'] ?>"><?= h($p['name']) ?> (Niv. <?= (int)$p['level'] ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <button class="btn btn-secondary" <?= $totalLeft <= 0 ? 'disabled' : '' ?>>
                                ⚔⚔ Combat duo (2v2)
                            </button>
                            <p class="form-msg" data-msg></p>
                        </form>
                    <?php endif; ?>
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

    <?php if ($isOwner && !empty($dailyQuests)): ?>
        <section class="card quests-preview">
            <h2><img src="../assets/svg/ui/scroll.svg" alt="" class="inline-icon"> Quêtes du jour</h2>
            <div class="quests-preview-grid">
                <?php foreach ($dailyQuests as $q):
                    $target   = (int)$q['target'];
                    $progress = (int)$q['progress'];
                    $claimed  = (int)$q['claimed'] === 1;
                    $done     = $progress >= $target;
                    $pct      = $target > 0 ? min(100, (int)round($progress * 100 / $target)) : 0;
                ?>
                    <div class="quest-mini <?= $claimed ? 'quest-claimed' : ($done ? 'quest-done' : '') ?>">
                        <img src="../<?= h($q['icon_path']) ?>" alt="">
                        <div>
                            <strong><?= h($q['label']) ?></strong>
                            <div class="bar xp"><div class="bar-fill" style="width:<?= $pct ?>%"></div></div>
                            <small><?= min($target, $progress) ?>/<?= $target ?> • +<?= (int)$q['reward_xp'] ?> XP</small>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <p><a href="quests.php">Voir toutes les quêtes →</a></p>
        </section>
    <?php endif; ?>

    <?php if (!empty($pets)): ?>
        <section class="card">
            <h2>Compagnon</h2>
            <div class="pet-grid">
                <?php foreach ($pets as $p): ?>
                    <div class="pet-item" title="<?= h($p['description']) ?>">
                        <img src="../<?= h($p['icon_path']) ?>" alt="<?= h($p['name']) ?>">
                        <div>
                            <strong><?= h($p['name']) ?></strong>
                            <p class="muted small"><?= h($p['description']) ?></p>
                            <small><?= (int)$p['hp_max'] ?> PV · <?= (int)$p['damage_min'] ?>-<?= (int)$p['damage_max'] ?> dég. · <?= (int)$p['agility'] ?> agi.</small>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <section class="card">
        <h2>Arsenal</h2>
        <div class="icon-grid">
            <?php foreach ($weapons as $w): ?>
                <div class="icon-item" title="<?= h($w['name']) ?> (<?= (int)$w['damage_min'] ?>-<?= (int)$w['damage_max'] ?> dég.)">
                    <img src="../<?= h($w['icon_path']) ?>" alt="<?= h($w['name']) ?>">
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
                <?php foreach ($skills as $s): $isUlt = (int)($s['is_ultimate'] ?? 0) === 1; ?>
                    <div class="icon-item <?= $isUlt ? 'is-ultimate' : '' ?>" title="<?= h($s['description']) ?>">
                        <img src="../<?= h($s['icon_path']) ?>" alt="<?= h($s['name']) ?>">
                        <span><?= h($s['name']) ?></span>
                        <?php if ($isUlt): ?><em class="ult-badge">⚡ Ultime</em><?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="card">
        <h2>Derniers combats</h2>
        <p class="muted small"><a href="stats.php?id=<?= $id ?>">📊 Voir les statistiques détaillées →</a></p>
        <?php if (empty($history)): ?>
            <p class="muted">Aucun combat pour l'instant.</p>
        <?php else: ?>
            <ul class="history">
                <?php foreach ($history as $f): ?>
                    <?php $won = (int)$f['winner_id'] === $id; ?>
                    <li class="<?= $won ? 'win' : 'loss' ?>">
                        <a href="fight.php?id=<?= (int)$f['id'] ?>">
                            <span>
                                <?= h($f['n1']) ?> vs <?= h($f['n2']) ?>
                                <?php if (($f['context'] ?? 'arena') === 'tournament'): ?>
                                    <em class="tag-ctx">Tournoi</em>
                                <?php endif; ?>
                            </span>
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
            <p class="muted">Aucun pupille pour l'instant. <?php if ($isOwner): ?><a href="pupils.php">Obtenir votre lien de parrainage →</a><?php endif; ?></p>
        <?php else: ?>
            <ul class="pupil-list">
                <?php foreach ($pupils as $p): ?>
                    <li><a href="brute.php?id=<?= (int)$p['id'] ?>"><?= h($p['name']) ?> (Niv. <?= (int)$p['level'] ?>)</a></li>
                <?php endforeach; ?>
            </ul>
            <?php if ($isOwner): ?>
                <p><a href="pupils.php">Voir l'arbre complet →</a></p>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</main>

<script>window.APPEARANCE = <?= json_encode($appearance) ?>;</script>
<script src="../assets/js/brute.js"></script>
</body>
</html>
