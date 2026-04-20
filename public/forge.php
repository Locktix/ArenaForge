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
            <h1><img src="../assets/svg/weapons/axe.svg" alt="" class="inline-icon"> Forge</h1>
            <div class="fragment-count">
                <span class="frag-icon">◆</span>
                <span class="frag-value" data-fragments><?= $fragments ?></span>
                <span class="frag-label">fragments</span>
            </div>
        </div>
        <p class="muted">
            Les <strong>fragments</strong> sont gagnés à chaque combat d'arène (3 en victoire, 1 en défaite).
            Utilise-les pour améliorer tes armes ou acheter des armures.
        </p>
    </section>

    <section class="card">
        <h2>Amélioration d'armes</h2>
        <p class="muted small">Chaque niveau d'amélioration ajoute +10 % de dégâts infligés. Maximum niveau 5.</p>
        <div class="forge-grid">
            <?php foreach ($weapons as $w): ?>
                <?php
                    $lvl  = (int)$w['upgrade_level'];
                    $cost = upgrade_cost($lvl);
                    $max  = $lvl >= FORGE_WEAPON_MAX_UPGRADE;
                ?>
                <div class="forge-item">
                    <img class="forge-icon" src="../<?= h($w['icon_path']) ?>" alt="">
                    <div class="forge-body">
                        <strong><?= h($w['name']) ?></strong>
                        <p class="muted small">
                            <?= (int)$w['damage_min'] ?>–<?= (int)$w['damage_max'] ?> dégâts
                            <?php if ($lvl > 0): ?>
                                · <span class="upgrade-badge">+<?= $lvl * 10 ?>%</span>
                            <?php endif; ?>
                        </p>
                        <div class="upgrade-track">
                            <?php for ($i = 1; $i <= FORGE_WEAPON_MAX_UPGRADE; $i++): ?>
                                <span class="upgrade-dot <?= $i <= $lvl ? 'filled' : '' ?>"></span>
                            <?php endfor; ?>
                        </div>
                    </div>
                    <div class="forge-action">
                        <?php if ($max): ?>
                            <span class="forge-maxed">Max</span>
                        <?php else: ?>
                            <form class="forge-upgrade-form" data-weapon-id="<?= (int)$w['id'] ?>">
                                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                <input type="hidden" name="brute_id" value="<?= $bruteId ?>">
                                <input type="hidden" name="weapon_id" value="<?= (int)$w['id'] ?>">
                                <span class="forge-cost">◆ <?= $cost ?></span>
                                <button class="btn btn-secondary" type="submit" <?= $fragments < $cost ? 'disabled' : '' ?>>
                                    Forger
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="card">
        <h2>Armures</h2>
        <p class="muted small">Les armures ajoutent des PV et réduisent les dégâts subis. Une armure par slot (tête / corps) équipée à la fois.</p>
        <div class="forge-grid">
            <?php foreach ($armors as $a):
                $owned    = (int)$a['owned']    === 1;
                $equipped = (int)$a['equipped'] === 1;
                $canBuy   = !$owned && $fragments >= (int)$a['cost_fragments'];
                $tier     = (int)$a['tier'];
                $tierRoman = ['I', 'II', 'III'][$tier - 1] ?? (string)$tier;
            ?>
                <div class="forge-item armor-tier-<?= $tier ?> <?= $equipped ? 'armor-equipped' : '' ?>">
                    <img class="forge-icon" src="../<?= h($a['icon_path']) ?>" alt="">
                    <div class="forge-body">
                        <strong><?= h($a['name']) ?></strong>
                        <div class="armor-meta">
                            <span class="armor-slot"><?= $a['slot'] === 'head' ? 'Tête' : 'Corps' ?></span>
                            <span class="armor-tier tier-<?= $tier ?>">Tier <?= h($tierRoman) ?></span>
                        </div>
                        <p class="armor-stats">
                            <?php if ((int)$a['hp_bonus']): ?><span class="stat-chip hp">+<?= (int)$a['hp_bonus'] ?> PV</span><?php endif; ?>
                            <?php if ((int)$a['damage_reduction']): ?><span class="stat-chip red">-<?= (int)$a['damage_reduction'] ?> dégâts</span><?php endif; ?>
                        </p>
                    </div>
                    <div class="forge-action">
                        <?php if (!$owned): ?>
                            <form class="forge-armor-form" data-armor-id="<?= (int)$a['id'] ?>" data-action="buy">
                                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                <input type="hidden" name="brute_id" value="<?= $bruteId ?>">
                                <input type="hidden" name="armor_id" value="<?= (int)$a['id'] ?>">
                                <input type="hidden" name="action" value="buy">
                                <span class="forge-cost">◆ <?= (int)$a['cost_fragments'] ?></span>
                                <button class="btn btn-secondary" type="submit" <?= !$canBuy ? 'disabled' : '' ?>>Acheter</button>
                            </form>
                        <?php else: ?>
                            <form class="forge-armor-form" data-armor-id="<?= (int)$a['id'] ?>" data-action="equip">
                                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                <input type="hidden" name="brute_id" value="<?= $bruteId ?>">
                                <input type="hidden" name="armor_id" value="<?= (int)$a['id'] ?>">
                                <input type="hidden" name="action" value="equip">
                                <button class="btn <?= $equipped ? 'btn-primary' : 'btn-secondary' ?>" type="submit">
                                    <?= $equipped ? 'Déséquiper' : 'Équiper' ?>
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
