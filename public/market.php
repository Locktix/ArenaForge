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
            <h1>🌑 Marché de l'Ombre</h1>
            <div class="gold-counter">
                <span class="gold-pill gold-pill-lg"><?= $gold ?> 🪙</span>
                <small class="muted">Trésor personnel</small>
            </div>
        </div>
        <p class="muted">
            Certains trésors ne se forgent pas, ils s'échangent dans le secret. Chaque jour à minuit, de nouvelles marchandises rares apparaissent. Saisis ta chance, car une fois acquises par un marchand, elles disparaissent dans la brume.
        </p>

        <div class="mystic-grid">
            <?php foreach ($offers as $o):
                $bought = (int)$o['bought'] === 1;
                $canBuy = !$bought && $gold >= (int)$o['cost_gold'];
            ?>
                <div class="mystic-card <?= $bought ? 'is-bought' : '' ?>">
                    <img src="../<?= h($o['icon_path']) ?>" alt="" class="mystic-icon">
                    <h3><?= h($o['label']) ?></h3>
                    
                    <div class="mystic-effect">
                        +<?= (int)$o['item_value'] ?>
                        <small><?= h(strtoupper(offer_label_type((string)$o['item_type']))) ?></small>
                    </div>

                    <?php if ($bought): ?>
                        <span class="mystic-cost" style="border-color: var(--muted); color: var(--muted);">ACQUIS</span>
                    <?php else: ?>
                        <form class="market-buy-form">
                            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                            <input type="hidden" name="brute_id" value="<?= $bruteId ?>">
                            <input type="hidden" name="offer_id" value="<?= (int)$o['id'] ?>">
                            <button type="submit" class="btn btn-secondary" <?= $canBuy ? '' : 'disabled' ?>>
                                S'approprier (<?= (int)$o['cost_gold'] ?> 🪙)
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <p class="muted small market-footer">
            🪙 Or = monnaie alternative aux fragments. Gagne 1-2 🪙 par combat
            d'arène, davantage en remportant un défi direct ou en finissant
            la saison à un palier élevé.
        </p>
    </section>
<script src="../assets/js/market.js"></script>
</main>
</body>
</html>
