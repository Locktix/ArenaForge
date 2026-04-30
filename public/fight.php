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

// Détection de la brute du joueur connecté pour proposer une revanche
$me = current_brute();
$myBruteId = $me ? (int)$me['id'] : 0;

$amInFight  = $myBruteId !== 0 && ((int)$f['brute1_id'] === $myBruteId || (int)$f['brute2_id'] === $myBruteId);
$iLost      = $amInFight && (int)$f['winner_id'] !== $myBruteId && $f['winner_id'] !== null;
$canRematch = $iLost
    && ((string)($f['context'] ?? '')) !== 'boss'
    && (int)$f['brute1_id'] !== (int)$f['brute2_id'];
$opponentName = '';
if ($canRematch) {
    $opponentName = (int)$f['brute1_id'] === $myBruteId ? (string)$f['n2'] : (string)$f['n1'];
}

// Cas particulier du combat de boss : `brutes` n'a pas de ligne pour le boss,
// donc brute2_id == brute1_id. On extrait le nom et l'apparence côté droit
// depuis l'event `start` du log.
$isBossFight = ((string)($f['context'] ?? '')) === 'boss';
if ($isBossFight) {
    foreach ($log as $ev) {
        if (($ev['event'] ?? '') === 'start' && isset($ev['teams']['R']['master'])) {
            $rm = $ev['teams']['R']['master'];
            if (!empty($rm['name']))   $f['n2']  = (string)$rm['name'];
            if (!empty($rm['hp_max'])) $f['hp2'] = (int)$rm['hp_max'];
            if (!empty($rm['appearance'])) {
                $f['a2'] = is_array($rm['appearance'])
                    ? json_encode($rm['appearance'], JSON_UNESCAPED_UNICODE)
                    : (string)$rm['appearance'];
            }
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Combat #<?= (int)$f['id'] ?> – ArenaForge</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" href="../assets/svg/logo/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="../assets/css/main.css">
</head>
<body class="fight-page">
<?php include __DIR__ . '/_nav.php'; ?>

<main class="wrap">
    <section class="card arena-card">
        <div class="weather-banner-row">
            <span id="weather-banner" class="weather-banner" style="display:none"></span>
        </div>
        <div class="arena" id="arena">
            <div class="arena-bg"></div>
            <div class="fighter fighter-left" id="fighter1" data-slot="L0">
                <div class="name-tag"><?= h($f['n1']) ?></div>
                <div class="bar hp small"><div class="bar-fill" data-hp-bar></div></div>
                <?php $appearance = json_decode((string)$f['a1'], true) ?: []; ?>
                <div class="sprite"><?php include __DIR__ . '/_gladiator.php'; ?></div>
            </div>
            <div class="fighter fighter-right" id="fighter2" data-slot="R0">
                <div class="name-tag"><?= h($f['n2']) ?></div>
                <div class="bar hp small"><div class="bar-fill" data-hp-bar></div></div>
                <?php $appearance = json_decode((string)$f['a2'], true) ?: []; ?>
                <div class="sprite flip"><?php include __DIR__ . '/_gladiator.php'; ?></div>
            </div>
            <div class="pet-container pet-left" id="pet-left"></div>
            <div class="pet-container pet-right" id="pet-right"></div>
            <div class="arena-flash" id="flash"></div>
        </div>

        <div class="combat-log" id="combat-log"></div>

        <div class="combat-controls">
            <div class="speed-controls" role="group" aria-label="Vitesse de replay">
                <span class="speed-label">Vitesse</span>
                <button class="speed-btn" data-speed="0.5" type="button">0.5×</button>
                <button class="speed-btn active" data-speed="1" type="button">1×</button>
                <button class="speed-btn" data-speed="2" type="button">2×</button>
                <button class="speed-btn" data-speed="4" type="button">4×</button>
            </div>
            <button class="btn btn-ghost" id="skip-btn" type="button">Aller au résultat</button>
            <button class="btn btn-secondary" id="replay-btn">Rejouer</button>
            <?php if ($canRematch): ?>
                <form id="rematch-form" class="rematch-inline">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="brute_id" value="<?= $myBruteId ?>">
                    <input type="hidden" name="target_name" value="<?= h($opponentName) ?>">
                    <input type="hidden" name="message" value="Revanche !">
                    <button type="submit" class="btn btn-primary">⚔ Revanche contre <?= h($opponentName) ?></button>
                </form>
            <?php endif; ?>
            <a class="btn btn-ghost" href="brute.php?id=<?= (int)($amInFight ? $myBruteId : $f['brute1_id']) ?>">Retour au gladiateur</a>
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
<script src="../assets/js/fight.js"></script>
<script>
(function () {
    const form = document.getElementById('rematch-form');
    if (!form) return;
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const btn = form.querySelector('button[type=submit]');
        const original = btn.textContent;
        btn.disabled = true;
        btn.textContent = 'Envoi…';
        try {
            const res = await fetch('../api/challenge_send.php', { method: 'POST', body: new FormData(form) });
            const data = await res.json();
            if (data.ok) {
                btn.textContent = '✓ Défi envoyé';
                if (window.Toast) {
                    window.Toast.queue([{ title: 'Revanche envoyée', description: 'En attente d\'acceptation', icon_path: 'assets/svg/weapons/sword.svg' }]);
                }
                setTimeout(() => { window.location.href = 'challenges.php?tab=sent'; }, 800);
            } else {
                btn.disabled = false;
                btn.textContent = original;
                if (window.Toast) {
                    window.Toast.queue([{ title: 'Refusée', description: data.error || 'Erreur', icon_path: 'assets/svg/ui/nav_settings.svg' }]);
                }
            }
        } catch (err) {
            btn.disabled = false;
            btn.textContent = original;
        }
    });
})();
</script>
</body>
</html>
