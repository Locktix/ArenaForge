<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_login();

$brute = current_brute();
if (!$brute) { header('Location: dashboard.php'); exit; }

$bruteId    = (int)$brute['id'];
$bruteLevel = (int)$brute['level'];
$gold       = (int)$brute['gold'];
$csrf       = csrf_token();

// Armes possédées
$stmt = db()->prepare('SELECT weapon_id FROM brute_weapons WHERE brute_id = ?');
$stmt->execute([$bruteId]);
$ownedIds = array_column($stmt->fetchAll(), 'weapon_id');
$ownedIds = array_map('intval', $ownedIds);

// Toutes les armes sauf Poings nus
$allWeapons = db()->query("SELECT * FROM weapons WHERE name != 'Poings nus' ORDER BY FIELD(rarity,'commun','rare','epique'), min_level")->fetchAll();

// Prix
const SHOP_PRICES_DISPLAY = ['commun' => 20, 'rare' => 90, 'epique' => 275];
const SHOP_OVERRIDES_DISPLAY = [
    'Dague'            => 20,
    'Lance'            => 80,
    'Epee'             => 100,
    'Bouclier'         => 90,
    'Masse'            => 110,
    'Hache'            => 250,
    'Bouclier en acier'=> 300,
];

function shop_price(array $w): int {
    return SHOP_OVERRIDES_DISPLAY[$w['name']] ?? SHOP_PRICES_DISPLAY[$w['rarity'] ?? 'commun'] ?? 20;
}

// Grouper par rareté
$byRarity = ['commun' => [], 'rare' => [], 'epique' => []];
foreach ($allWeapons as $w) {
    $r = $w['rarity'] ?? 'commun';
    if (isset($byRarity[$r])) $byRarity[$r][] = $w;
}

$rarityLabels = ['commun' => 'Commun', 'rare' => 'Rare', 'epique' => 'Épique'];
$rarityIcons  = ['commun' => '⚔', 'rare' => '✦', 'epique' => '★'];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Armurerie – ArenaForge</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" href="../assets/svg/logo/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="../assets/css/main.css">
</head>
<body>
<?php include __DIR__ . '/_nav.php'; ?>

<main class="wrap" data-body-class="">

<div class="shop-hero">
    <div class="shop-hero-bg"></div>
    <div class="shop-hero-content">
        <h1 class="shop-title">⚒ L'Armurerie Royale</h1>
        <p class="shop-subtitle muted">Forges les plus redoutables de la Cité. Chaque lame a son prix — chaque victoire a son prix.</p>
        <div class="shop-gold-display">
            <img src="../assets/svg/ui/gold.svg" alt="" class="shop-gold-icon" onerror="this.style.display='none'">
            <span class="shop-gold-amount"><?= number_format($gold) ?></span>
            <span class="shop-gold-label">or</span>
        </div>
    </div>
</div>

<?php foreach ($byRarity as $rarity => $weapons):
    if (empty($weapons)) continue;
?>
<section class="shop-section">
    <div class="shop-section-header rarity-<?= $rarity ?>">
        <span class="shop-section-icon"><?= $rarityIcons[$rarity] ?></span>
        <h2 class="shop-section-title"><?= $rarityLabels[$rarity] ?></h2>
        <span class="shop-section-line"></span>
    </div>
    <div class="shop-grid">
        <?php foreach ($weapons as $w):
            $wId     = (int)$w['id'];
            $price   = shop_price($w);
            $owned   = in_array($wId, $ownedIds, true);
            $locked  = $bruteLevel < (int)($w['min_level'] ?? 0);
            $broke   = !$owned && !$locked && $gold < $price;
            $isShield = (int)($w['defense_bonus'] ?? 0) > 0 && (int)($w['damage_max'] ?? 0) === 0;
            $statLine = $isShield
                ? 'Défense +' . (int)$w['defense_bonus']
                : (int)$w['damage_min'] . '–' . (int)$w['damage_max'] . ' dégâts';
            $cardClass = 'shop-card rarity-' . $rarity;
            if ($owned)  $cardClass .= ' shop-card--owned';
            if ($locked) $cardClass .= ' shop-card--locked';
        ?>
        <div class="<?= $cardClass ?>">
            <div class="shop-card-glow"></div>
            <div class="shop-card-body">
                <div class="shop-card-icon-wrap">
                    <img src="../<?= h($w['icon_path']) ?>" alt="" class="shop-card-icon">
                    <?php if ($owned): ?>
                        <span class="shop-owned-badge">✓</span>
                    <?php elseif ($locked): ?>
                        <span class="shop-locked-badge">🔒</span>
                    <?php endif; ?>
                </div>
                <div class="shop-card-info">
                    <h3 class="shop-card-name"><?= h($w['name']) ?></h3>
                    <p class="shop-card-stat muted"><?= $statLine ?></p>
                    <?php if ((int)($w['min_level'] ?? 0) > 0): ?>
                        <p class="shop-card-req <?= $locked ? 'shop-req--locked' : 'shop-req--ok' ?>">
                            Niveau <?= (int)$w['min_level'] ?> requis
                        </p>
                    <?php endif; ?>
                </div>
            </div>
            <div class="shop-card-footer">
                <?php if ($owned): ?>
                    <span class="shop-card-owned-msg">Dans ton arsenal</span>
                <?php elseif ($locked): ?>
                    <span class="shop-card-locked-msg">Niveau <?= (int)$w['min_level'] ?> requis</span>
                <?php else: ?>
                    <div class="shop-price <?= $broke ? 'shop-price--broke' : '' ?>">
                        <span class="shop-price-coin">⬡</span>
                        <span class="shop-price-val"><?= $price ?></span>
                        <span class="shop-price-unit">or</span>
                    </div>
                    <button class="btn shop-buy-btn <?= $broke ? 'shop-buy-btn--broke' : 'btn-primary' ?>"
                            data-weapon-id="<?= $wId ?>"
                            data-weapon-name="<?= h($w['name']) ?>"
                            data-price="<?= $price ?>"
                            data-csrf="<?= h($csrf) ?>"
                            <?= $broke ? 'disabled' : '' ?>>
                        <?= $broke ? 'Or insuffisant' : 'Acheter' ?>
                    </button>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endforeach; ?>

<script src="../assets/js/shop.js"></script>
</main>
</body>
</html>
