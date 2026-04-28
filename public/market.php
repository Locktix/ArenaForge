<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/market_engine.php';
require_login();

$brute = current_brute();
if (!$brute) {
    header('Location: dashboard.php');
    exit;
}

$bruteId = (int)$brute['id'];
$offers  = list_today_market($bruteId);
$gold    = get_brute_gold($bruteId);
$csrf    = csrf_token();

function offer_label_type(string $t): string {
    return [
        'fragments'   => 'Fragments',
        'xp'          => 'Expérience',
        'bonus_fight' => 'Combat bonus',
        'potion'      => 'PV permanents',
    ][$t] ?? $t;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Marché noir — ArenaForge</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" href="../assets/svg/logo/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="../assets/css/main.css">
</head>
<body>
<?php include __DIR__ . '/_nav.php'; ?>

<main class="wrap">
    <section class="card">
        <div class="market-header">
            <h1><img src="../assets/svg/quests/hammer.svg" alt="" class="inline-icon"> Marché noir</h1>
            <div class="gold-counter">
                <span class="gold-pill gold-pill-lg"><?= $gold ?> 🪙</span>
                <small class="muted">Or disponible</small>
            </div>
        </div>
        <p class="muted">
            Quatre offres tirées au sort chaque jour à minuit. Identique pour tous
            les gladiateurs, mais limitée à un seul achat par offre.
            L'or se gagne en remportant des combats.
        </p>

        <div class="market-grid">
            <?php foreach ($offers as $o):
                $bought = (int)$o['bought'] === 1;
                $canBuy = !$bought && $gold >= (int)$o['cost_gold'];
            ?>
                <article class="market-tile <?= $bought ? 'is-bought' : '' ?>">
                    <img class="market-icon" src="../<?= h($o['icon_path']) ?>" alt="">
                    <h3><?= h($o['label']) ?></h3>
                    <p class="market-effect">
                        <strong>+<?= (int)$o['item_value'] ?></strong>
                        <span class="muted small"><?= h(offer_label_type((string)$o['item_type'])) ?></span>
                    </p>
                    <div class="market-cost">
                        <span class="gold-pill"><?= (int)$o['cost_gold'] ?> 🪙</span>
                    </div>
                    <?php if ($bought): ?>
                        <button class="btn btn-ghost" disabled>Acheté ✓</button>
                    <?php else: ?>
                        <form class="market-buy-form">
                            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                            <input type="hidden" name="brute_id" value="<?= $bruteId ?>">
                            <input type="hidden" name="offer_id" value="<?= (int)$o['id'] ?>">
                            <button class="btn btn-secondary" type="submit" <?= $canBuy ? '' : 'disabled' ?>>
                                <?= $canBuy ? 'Acheter' : 'Or insuffisant' ?>
                            </button>
                        </form>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>

        <p class="muted small market-footer">
            🪙 Or = monnaie alternative aux fragments. Gagne 1-2 🪙 par combat
            d'arène, davantage en remportant un défi direct ou en finissant
            la saison à un palier élevé.
        </p>
    </section>
</main>

<script src="../assets/js/market.js"></script>
</body>
</html>
