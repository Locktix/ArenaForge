<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/brute_generator.php';
require_once __DIR__ . '/../includes/quest_engine.php';
require_once __DIR__ . '/../includes/combat_engine.php';
require_once __DIR__ . '/../includes/title_engine.php';

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

$currentUid = current_user_id();
$isOwner    = ($currentUid !== null && (int)$brute['user_id'] === $currentUid);

// Armes
$weapons = $fighter['weapons'];

// Compétences — nœuds de l'arbre débloqués
$unlockedNodes = skill_tree_nodes_for_brute($id);
$skillPoints   = $isOwner ? (int)($brute['skill_points'] ?? 0) : 0;

// Compagnon animal
$pets = db()->prepare('SELECT p.* FROM pets p JOIN brute_pets bp ON bp.pet_id = p.id WHERE bp.brute_id = ? ORDER BY bp.acquired_at');
$pets->execute([$id]);
$pets = $pets->fetchAll();

// Évolution disponible pour le pet actuel
$petEvolution = null;
if (!empty($pets)) {
    $stmt = db()->prepare('SELECT * FROM pets WHERE evolves_from = ? LIMIT 1');
    $stmt->execute([(int)$pets[0]['id']]);
    $petEvolution = $stmt->fetch() ?: null;
}

// Pets de base proposés si la brute n'en a pas encore
$basePetsChoice = [];
if ($isOwner && empty($pets)) {
    $basePetsChoice = db()->query("SELECT id, name, description, icon_path FROM pets WHERE evolves_from IS NULL AND rarity = 'commun' ORDER BY id")->fetchAll();
}

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


$csrf = csrf_token();
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

    <section class="card brute-profile">
        <div class="profile-header">
            <div class="hero-identity">
                <h1><?= h($brute['name']) ?></h1>
                <?php
                    $activeTitleCode = $brute['active_title_code'] ?? null;
                    $activeTitle = ($activeTitleCode !== null && $activeTitleCode !== '') ? title_get((string)$activeTitleCode) : null;
                ?>
                <?php if ($activeTitle): ?>
                    <span class="hero-title" title="<?= h(title_bonus_summary($activeTitle['bonus'])) ?>">
                        <img src="../<?= h($activeTitle['icon_path']) ?>" alt="">
                        <?= h($activeTitle['label']) ?>
                    </span>
                <?php endif; ?>
                <span class="hero-level">Niveau <?= (int)$brute['level'] ?></span>
                <span class="hero-mmr">MMR <?= (int)($brute['mmr'] ?? 1000) ?></span>
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

                <?php
                    // Bonus de titre actif — pour l'affichage visuel (+N)
                    $activeTitleBonus = [];
                    $activeTitleRarity = 'rare';
                    if (!empty($fighter['active_title'])) {
                        $atDef = title_get((string)$fighter['active_title']);
                        if ($atDef) {
                            $activeTitleBonus  = $atDef['bonus'];
                            $activeTitleRarity = $atDef['rarity'];
                        }
                    }
                    $titleColor = match($activeTitleRarity) {
                        'legendaire' => '#f1c97a',
                        'epique'     => '#b47cd8',
                        default      => '#5fa8e8',
                    };
                ?>
                <div class="hero-stats">
                    <?php foreach ([
                        'strength'  => ['Force',     'strength'],
                        'agility'   => ['Agilité',   'agility'],
                        'endurance' => ['Endurance', 'endurance'],
                    ] as $key => [$label, $fKey]):
                        $baseVal = (int)$brute[$fKey];
                        $bonus   = (int)($activeTitleBonus[$key] ?? 0);
                    ?>
                    <div class="stat-box">
                        <span class="stat-label"><?= $label ?></span>
                        <span class="stat-value">
                            <?= $baseVal ?>
                            <?php if ($bonus > 0): ?>
                                <sup class="stat-title-bonus" style="color:<?= $titleColor ?>" title="Bonus du titre actif">+<?= $bonus ?></sup>
                            <?php endif; ?>
                        </span>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="hero-weapon <?= weapon_rarity_class($currentWeapon['rarity'] ?? 'commun') ?>">
                    <div class="weapon-info">
                        <span class="weapon-name">⚔ <?= h($currentWeapon['name']) ?></span>
                        <em class="rarity-badge"><?= weapon_rarity_label($currentWeapon['rarity'] ?? 'commun') ?></em>
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
                    <?php if ($skillPoints > 0): ?>
                        <div class="skill-points-reminder">
                            ✨ <?= $skillPoints ?> point<?= $skillPoints !== 1 ? 's' : '' ?> de compétence non dépensé<?= $skillPoints !== 1 ? 's' : '' ?> —
                            <a href="skills.php">ouvrir l'arbre →</a>
                        </div>
                    <?php endif; ?>
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
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </section>


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

    <?php if (!empty($basePetsChoice)): ?>
        <section class="card">
            <h2>🐾 Choisir un compagnon</h2>
            <p class="muted small">Tu n'as pas encore de compagnon. Choisis-en un — il t'accompagnera au combat.</p>
            <form id="assign-pet-form" style="margin-top:16px;">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <div class="create-pet-grid">
                    <?php foreach ($basePetsChoice as $i => $p): ?>
                    <label class="create-pet-card <?= $i === 0 ? 'create-pet-card--selected' : '' ?>">
                        <input type="radio" name="pet_id" value="<?= (int)$p['id'] ?>" <?= $i === 0 ? 'checked' : '' ?> required>
                        <img src="../<?= h($p['icon_path']) ?>" alt="<?= h($p['name']) ?>" class="create-pet-img">
                        <strong class="create-pet-name"><?= h($p['name']) ?></strong>
                        <p class="create-pet-desc muted small"><?= h($p['description']) ?></p>
                    </label>
                    <?php endforeach; ?>
                </div>
                <button type="submit" class="btn btn-primary" style="margin-top:14px;">Adopter ce compagnon</button>
                <p class="form-msg" data-assign-pet-msg></p>
            </form>
        </section>
    <?php endif; ?>

    <?php if (!empty($pets)): ?>
        <section class="card">
            <h2>🐾 Compagnon</h2>
            <div class="pet-grid">
                <?php foreach ($pets as $p):
                    $pRarity = $p['rarity'] ?? 'commun';
                ?>
                    <div class="pet-item <?= weapon_rarity_class($pRarity) ?>" title="<?= h($p['description']) ?>">
                        <img src="../<?= h($p['icon_path']) ?>" alt="<?= h($p['name']) ?>">
                        <div>
                            <strong><?= h($p['name']) ?></strong>
                            <em class="rarity-badge"><?= weapon_rarity_label($pRarity) ?></em>
                            <p class="muted small"><?= h($p['description']) ?></p>
                            <small><?= (int)$p['hp_max'] ?> PV · <?= (int)$p['damage_min'] ?>-<?= (int)$p['damage_max'] ?> dég. · <?= (int)$p['agility'] ?> agi.</small>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if ($isOwner && $petEvolution): ?>
            <div class="pet-evolve-wrap">
                <button class="btn btn-outline pet-evolve-btn"
                        data-pet-id="<?= (int)$petEvolution['id'] ?>"
                        data-csrf="<?= h($csrf) ?>">
                    ✨ Évoluer → <?= h($petEvolution['name']) ?> <span class="muted small">(150 or)</span>
                </button>
                <p class="muted small pet-evolve-hint">
                    <?= (int)$petEvolution['hp_max'] ?> PV · <?= (int)$petEvolution['damage_min'] ?>-<?= (int)$petEvolution['damage_max'] ?> dég. · <?= (int)$petEvolution['agility'] ?> agi.
                    — <?= h($petEvolution['description']) ?>
                </p>
            </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <section class="card">
        <h2>⚔ Arsenal</h2>
        <div class="icon-grid">
            <?php foreach ($weapons as $w):
                $wRarity = $w['rarity'] ?? 'commun';
            ?>
                <div class="icon-item <?= weapon_rarity_class($wRarity) ?>" title="<?= h($w['name']) ?><?= (int)$w['defense_bonus'] > 0 ? ' (-'.(int)$w['defense_bonus'].' dég. reçus)' : ' ('.(int)$w['damage_min'].'-'.(int)$w['damage_max'].' dég.)' ?>">
                    <img src="../<?= h($w['icon_path']) ?>" alt="<?= h($w['name']) ?>">
                    <span><?= h($w['name']) ?></span>
                    <em class="rarity-badge"><?= weapon_rarity_label($wRarity) ?></em>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="card">
        <h2>✦ Compétences</h2>
        <?php if ($isOwner): ?>
            <div class="skill-profile-actions">
                <?php if ($skillPoints > 0): ?>
                    <a href="skills.php" class="btn btn-sm btn-secondary">✨ <?= $skillPoints ?> point<?= $skillPoints !== 1 ? 's' : '' ?> à dépenser</a>
                <?php else: ?>
                    <a href="skills.php" class="btn btn-sm btn-ghost">Gérer l'arbre →</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <?php if (empty($unlockedNodes)): ?>
            <p class="muted">Aucun nœud débloqué<?php if ($isOwner): ?> — <a href="skills.php">ouvrir l'arbre →</a><?php endif; ?>.</p>
        <?php else: ?>
            <div class="icon-grid">
                <?php foreach ($unlockedNodes as $nid):
                    $node = skill_tree_get_node($nid);
                    if (!$node) continue;
                ?>
                    <div class="icon-item" title="<?= h($node['desc']) ?>">
                        <span class="skill-emoji"><?= $node['icon'] ?></span>
                        <span><?= h($node['name']) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="card">
        <h2>⚡ Derniers combats</h2>
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
        <h2>👥 Pupilles</h2>
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

    <?php if ($isOwner): ?>
    <div class="share-profile">
        <span class="share-label">🔗 Partager ce profil :</span>
        <button class="btn btn-ghost btn-sm" id="copy-profile-link" type="button"
                data-url="<?= h((isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']) ?>">
            Copier le lien
        </button>
    </div>
    <?php endif; ?>

    <?php if (!$currentUid): ?>
    <section class="card visitor-cta">
        <div class="visitor-cta-inner">
            <p>Impressionné par <strong><?= h($brute['name']) ?></strong> ? Forge ton propre gladiateur et affronte-le dans l'arène !</p>
            <div class="visitor-cta-actions">
                <a href="register.php" class="btn btn-primary">⚔ Créer mon gladiateur</a>
                <a href="index.php" class="btn btn-ghost">Se connecter</a>
            </div>
        </div>
    </section>
    <?php endif; ?>
<script>window.APPEARANCE = <?= json_encode($appearance) ?>;</script>
<script src="../assets/js/brute.js"></script>
<?php if ($isOwner): ?>
<script>
(function () {
    const btn = document.getElementById('copy-profile-link');
    if (!btn) return;
    btn.addEventListener('click', () => {
        navigator.clipboard.writeText(btn.dataset.url).then(() => {
            btn.textContent = '✓ Lien copié !';
            setTimeout(() => { btn.textContent = 'Copier le lien'; }, 2500);
        }).catch(() => {
            prompt('Copie ce lien :', btn.dataset.url);
        });
    });
})();
</script>
<?php endif; ?>
</main>
</body>
</html>
