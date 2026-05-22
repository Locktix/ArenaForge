<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/forge_engine.php';
require_login();

$brute = current_brute();
if (!$brute) {
    header('Location: dashboard.php');
    exit;
}

$bruteId  = (int)$brute['id'];
$csrf     = csrf_token();
$fragments = (int)$brute['fragments'];
$weapons  = get_brute_weapons_with_upgrades($bruteId);
$armors   = get_armors_for_brute($bruteId);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Forge – ArenaForge</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" href="../assets/svg/logo/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="../assets/css/main.css">
</head>
<body>
<?php include __DIR__ . '/_nav.php'; ?>

<main class="wrap">
    <section class="card">
        <div class="forge-header">
            <h1>⚒ La Forge Royale</h1>
            <div class="fragment-count">
                <span class="frag-icon">◆</span>
                <span class="frag-value" data-fragments><?= $fragments ?></span>
                <span class="frag-label">fragments</span>
            </div>
        </div>
        <p class="muted">
            Les <strong>fragments</strong> sont l'essence même de la création. Gagnés à la sueur du front en arène (3 par victoire, 1 par défaite), ils permettent de transcender tes armes et de revêtir les armures des plus grands champions.
        </p>
    </section>

    <section class="card">
        <h2>⚔️ Armes de Maître</h2>
        <p class="muted small">Chaque niveau d'amélioration ajoute +10 % de dégâts infligés. L'excellence n'attend pas.</p>
        <div class="workbench-grid">
            <?php foreach ($weapons as $w): ?>
                <?php
                    $lvl  = (int)$w['upgrade_level'];
                    $cost = upgrade_cost($lvl);
                    $max  = $lvl >= FORGE_WEAPON_MAX_UPGRADE;
                ?>
                <div class="workbench-card">
                    <div class="workbench-header">
                        <div class="workbench-icon-wrap">
                            <img src="../<?= h($w['icon_path']) ?>" alt="" class="workbench-icon">
                        </div>
                        <div class="workbench-info">
                            <h3><?= h($w['name']) ?></h3>
                            <div class="upgrade-track">
                                <?php for ($i = 1; $i <= FORGE_WEAPON_MAX_UPGRADE; $i++): ?>
                                    <div class="upgrade-dot <?= $i <= $lvl ? 'filled' : '' ?>"></div>
                                <?php endfor; ?>
                            </div>
                        </div>
                    </div>

                    <div class="workbench-stats">
                        <?php if ((int)$w['defense_bonus'] > 0): ?>
                            <div class="stat-chip">🛡 -<?= (int)floor($w['defense_bonus'] * (1 + 0.10 * $lvl)) ?> dég. reçus</div>
                        <?php else: ?>
                            <div class="stat-chip">⚔ <?= (int)$w['damage_min'] ?>–<?= (int)$w['damage_max'] ?> de base</div>
                        <?php endif; ?>
                        <?php if ($lvl > 0): ?>
                            <div class="stat-chip hp"><?= (int)$w['defense_bonus'] > 0 ? 'Défense' : 'Excellence' ?> +<?= $lvl * 10 ?>%</div>
                        <?php endif; ?>
                    </div>

                    <div class="workbench-footer">
                        <?php if ($max): ?>
                            <span class="forge-maxed">ARTEFACT SUPRÊME</span>
                        <?php else: ?>
                            <div class="forge-cost">
                                <span>◆ <?= $cost ?></span>
                            </div>
                            <form class="forge-upgrade-form" data-weapon-id="<?= (int)$w['id'] ?>">
                                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                <input type="hidden" name="brute_id" value="<?= $bruteId ?>">
                                <input type="hidden" name="weapon_id" value="<?= (int)$w['id'] ?>">
                                <button type="submit" class="btn btn-secondary btn-sm" <?= $fragments < $cost ? 'disabled' : '' ?>>Améliorer</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="card">
        <h2>🛡️ Armures de Légende</h2>
        <p class="muted small">Seule une pièce d'armure peut être portée par emplacement pour protéger votre essence.</p>
        <div class="workbench-grid">
            <?php foreach ($armors as $a):
                $owned    = (int)$a['owned']    === 1;
                $equipped = (int)$a['equipped'] === 1;
                $canBuy   = !$owned && $fragments >= (int)$a['cost_fragments'];
                $tier     = (int)$a['tier'];
                $tierRoman = ['I', 'II', 'III'][$tier - 1] ?? (string)$tier;
            ?>
                <div class="workbench-card <?= $equipped ? 'armor-equipped' : '' ?>">
                    <div class="workbench-header">
                        <div class="workbench-icon-wrap">
                            <img src="../<?= h($a['icon_path']) ?>" alt="" class="workbench-icon">
                        </div>
                        <div class="workbench-info">
                            <h3><?= h($a['name']) ?></h3>
                            <div class="armor-meta">
                                <span class="armor-slot"><?= $a['slot'] === 'head' ? 'Heaume' : 'Plastron' ?></span>
                                <span class="armor-tier tier-<?= $tier ?>">Tier <?= h($tierRoman) ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="workbench-stats">
                        <?php if ((int)$a['hp_bonus']): ?><div class="stat-chip hp">+<?= (int)$a['hp_bonus'] ?> Vitalité</div><?php endif; ?>
                        <?php if ((int)$a['damage_reduction']): ?><div class="stat-chip red">Défense +<?= (int)$a['damage_reduction'] ?></div><?php endif; ?>
                    </div>

                    <div class="workbench-footer">
                        <?php if (!$owned): ?>
                            <div class="forge-cost">
                                <span>◆ <?= (int)$a['cost_fragments'] ?></span>
                            </div>
                            <form class="forge-armor-form" data-armor-id="<?= (int)$a['id'] ?>" data-action="buy">
                                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                <input type="hidden" name="brute_id" value="<?= $bruteId ?>">
                                <input type="hidden" name="armor_id" value="<?= (int)$a['id'] ?>">
                                <input type="hidden" name="action" value="buy">
                                <button type="submit" class="btn btn-secondary btn-sm" <?= !$canBuy ? 'disabled' : '' ?>>Forger</button>
                            </form>
                        <?php else: ?>
                            <span class="muted small"><?= $equipped ? 'Actuellement portée' : 'En réserve' ?></span>
                            <form class="forge-armor-form" data-armor-id="<?= (int)$a['id'] ?>" data-action="equip">
                                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                <input type="hidden" name="brute_id" value="<?= $bruteId ?>">
                                <input type="hidden" name="armor_id" value="<?= (int)$a['id'] ?>">
                                <input type="hidden" name="action" value="equip">
                                <button class="btn <?= $equipped ? 'btn-primary' : 'btn-secondary' ?> btn-sm" type="submit">
                                    <?= $equipped ? 'Déséquiper' : 'Revêtir' ?>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
</main>

<script src="../assets/js/forge.js"></script>
</body>
</html>
