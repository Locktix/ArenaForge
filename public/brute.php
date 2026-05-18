<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/brute_generator.php';
require_once __DIR__ . '/../includes/quest_engine.php';
require_once __DIR__ . '/../includes/notification_engine.php';
require_once __DIR__ . '/../includes/combat_engine.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
$fighter = load_fighter($id);
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
$weapons = $fighter['weapons'];
$skills = $fighter['skills'];

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
    if (!empty($brute['levelup_choices'])) {
        $bonusChoices = json_decode($brute['levelup_choices'], true);
    } else {
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
        
        // Sauvegarder les choix pour éviter l'exploit F5
        db()->prepare('UPDATE brutes SET levelup_choices = ? WHERE id = ?')
          ->execute([json_encode($bonusChoices, JSON_UNESCAPED_UNICODE), $id]);
    }
}

$csrf = csrf_token();
$notifications = $isOwner ? get_notifications($brute) : [];
$xpCur  = (int)$brute['xp'];
$xpNext = xp_for_level((int)$brute['level'] + 1);
$xpPrev = xp_for_level((int)$brute['level']);
$xpPct  = $xpNext > $xpPrev ? max(0, min(100, (int)round(($xpCur - $xpPrev) * 100 / ($xpNext - $xpPrev)))) : 0;

$appearance = json_decode((string)$brute['appearance_seed'], true) ?: [];

// Arme actuelle et dégâts
$currentWeapon = pick_weapon($fighter);
$dmgMin = $currentWeapon['damage_min'] + (int)floor($fighter['strength'] / 2);
$dmgMax = $currentWeapon['damage_max'] + (int)floor($fighter['strength'] / 2);
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
    <?php if (!empty($notifications)): ?>
        <section class="card notif-hub">
            <h2><img src="../assets/svg/ui/scroll.svg" alt="" class="inline-icon"> Centre d'actions</h2>
            <ul class="notif-list">
                <?php foreach ($notifications as $n): ?>
                    <li class="notif-item <?= !empty($n['urgent']) ? 'is-urgent' : '' ?> notif-kind-<?= h($n['kind']) ?>">
                        <a href="<?= h($n['href']) ?>">
                            <img class="notif-icon" src="../<?= h($n['icon']) ?>" alt="">
                            <div class="notif-body">
                                <strong><?= h($n['title']) ?></strong>
                                <span class="muted small"><?= h($n['body']) ?></span>
                            </div>
                            <span class="notif-arrow">›</span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>

    <section class="card brute-profile">
        <div class="profile-header">
            <div class="hero-identity">
                <h1><?= h($brute['name']) ?></h1>
                <span class="hero-level">Niveau <?= (int)$brute['level'] ?></span>
            </div>
            
            <?php if ($isOwner): ?>
            <div class="hero-wallet">
                <div class="wallet-pill" title="Fragments de forge">
                    <img src="../assets/svg/weapons/axe.svg" alt="">
                    <strong><?= (int)$brute['fragments'] ?></strong>
                </div>
                <div class="wallet-pill gold" title="Pièces d'or">
                    <span>🪙</span>
                    <strong><?= (int)($brute['gold'] ?? 0) ?></strong>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="profile-main">
            <div class="profile-portrait">
                <div class="portrait-inner">
                    <?php include __DIR__ . '/_gladiator.php'; ?>
                </div>
            </div>

            <div class="profile-content">
                <div class="hero-bars">
                    <div class="profile-bar hp" title="Points de Vie">
                        <div class="bar-fill" style="width:100%"></div>
                        <div class="bar-text">
                            <span class="label">SANTÉ</span>
                            <span class="value"><?= (int)$fighter['hp_max'] ?> / <?= (int)$fighter['hp_max'] ?></span>
                        </div>
                    </div>
                    <div class="profile-bar xp" title="Expérience">
                        <div class="bar-fill" style="width: <?= $xpPct ?>%"></div>
                        <div class="bar-text">
                            <span class="label">EXP</span>
                            <span class="value"><?= $xpCur ?> / <?= $xpNext ?></span>
                        </div>
                    </div>
                </div>

                <div class="hero-stats">
                    <div class="stat-box">
                        <span class="stat-label">Force</span>
                        <span class="stat-value"><?= (int)$brute['strength'] ?></span>
                    </div>
                    <div class="stat-box">
                        <span class="stat-label">Agilité</span>
                        <span class="stat-value"><?= (int)$brute['agility'] ?></span>
                    </div>
                    <div class="stat-box">
                        <span class="stat-label">Endurance</span>
                        <span class="stat-value"><?= (int)$brute['endurance'] ?></span>
                    </div>
                </div>

                <div class="hero-weapon">
                    <div class="weapon-info">
                        <span class="weapon-name">⚔ <?= h($currentWeapon['name']) ?></span>
                        <span class="weapon-damage"><?= $dmgMin ?> — <?= $dmgMax ?> Dégâts</span>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($isOwner): ?>
            <?php
              $baseLeft = 6 - ((int)$brute['fights_today']);
              if ($brute['last_fight_date'] !== date('Y-m-d')) { $baseLeft = 6; }
              $baseLeft  = max(0, $baseLeft);
              $bonusLeft = (int)$brute['bonus_fights_available'];
              $totalLeft = $baseLeft + $bonusLeft;
            ?>

            <div class="profile-footer">
                <div class="battle-status">
                    <span class="status-indicator">
                        <span class="dot <?= $totalLeft > 0 ? 'online' : 'offline' ?>"></span>
                        <?= $baseLeft ?> / 6 combats disponibles
                        <?php if ($bonusLeft > 0): ?>
                            <strong class="bonus-tag">+<?= $bonusLeft ?> bonus</strong>
                        <?php endif; ?>
                    </span>
                </div>

                <div class="battle-actions">
                    <?php if ((int)$brute['pending_levelup'] === 1): ?>
                        <div class="levelup-cta">
                            <span class="blink">🔥</span> UN NOUVEAU POUVOIR VOUS ATTEND <span class="blink">🔥</span>
                        </div>
                    <?php else: ?>
                        <form id="fight-form">
                            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                            <input type="hidden" name="brute_id" value="<?= (int)$brute['id'] ?>">
                            <button class="btn btn-primary btn-hero" <?= $totalLeft <= 0 ? 'disabled' : '' ?>>
                                ⚔ ENTRER DANS L'ARÈNE
                            </button>
                        </form>

                        <div class="secondary-actions">
                            <form id="training-form">
                                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                <input type="hidden" name="brute_id" value="<?= (int)$brute['id'] ?>">
                                <button type="submit" class="btn btn-outline" title="Entraînement gratuit">🎯 Test</button>
                            </form>

                            <?php if (!empty($pupils)): ?>
                                <form id="duo-fight-form" class="duo-compact">
                                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                    <input type="hidden" name="brute_id" value="<?= (int)$brute['id'] ?>">
                                    <select name="partner_id" class="partner-select">
                                        <?php foreach ($pupils as $p): ?>
                                            <option value="<?= (int)$p['id'] ?>"><?= h($p['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button class="btn btn-outline" <?= $totalLeft <= 0 ? 'disabled' : '' ?>>👥 Duo</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
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

<?php if ($isOwner): ?>
<div id="opponent-modal" class="opponent-modal" aria-hidden="true" style="display:none">
    <div class="opponent-modal-inner card">
        <h3 class="opp-modal-title">⚔ Choisissez votre adversaire</h3>
        <div id="opponent-choices" class="opponent-choices"></div>
        <div class="opp-modal-footer">
            <button type="button" id="opponent-cancel" class="btn btn-ghost">Annuler</button>
        </div>
    </div>
</div>
<?php endif; ?>

<script>window.APPEARANCE = <?= json_encode($appearance) ?>;</script>
<script src="../assets/js/brute.js"></script>
</body>
</html>
