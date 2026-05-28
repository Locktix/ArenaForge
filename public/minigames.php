<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/brute_generator.php';
require_login();

$brute   = current_brute();
if (!$brute) { header('Location: dashboard.php'); exit; }
$bruteId = (int)$brute['id'];

// Générer un jeton de session anti-triche
$token = bin2hex(random_bytes(16));
$_SESSION['mg_token']      = $token;
$_SESSION['mg_token_time'] = time();

$csrf = csrf_token();

// Cooldown restant
$cooldownSecs = 1800;
$lastClaim    = $brute['minigame_claimed_at'] ?? null;
$canPlay      = !$lastClaim || (time() - strtotime($lastClaim)) >= $cooldownSecs;
$waitMinutes  = $canPlay ? 0 : (int)ceil(($cooldownSecs - (time() - strtotime($lastClaim))) / 60);

// Leaderboard global Snake (top 10 all-time, 1 entrée par brute = son meilleur score)
$leaderboard = [];
$myBest      = null;
try {
    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT ms.brute_id, b.name, MAX(ms.score) AS best_score, MAX(ms.achieved_at) AS last_at
        FROM minigame_scores ms
        JOIN brutes b ON b.id = ms.brute_id
        WHERE ms.game = 'snake'
        GROUP BY ms.brute_id, b.name
        ORDER BY best_score DESC
        LIMIT 10
    ");
    $stmt->execute();
    $leaderboard = $stmt->fetchAll();

    // Meilleur score perso (hors top 10)
    $stmt2 = $pdo->prepare("SELECT MAX(score) FROM minigame_scores WHERE game='snake' AND brute_id=?");
    $stmt2->execute([$bruteId]);
    $myBest = $stmt2->fetchColumn() ?: null;
} catch (\Throwable $e) { /* table pas encore créée */ }

// Rang du joueur s'il n'est pas dans le top 10
$myRank = null;
if ($myBest !== null) {
    $inTop = false;
    foreach ($leaderboard as $row) {
        if ((int)$row['brute_id'] === $bruteId) { $inTop = true; break; }
    }
    if (!$inTop) {
        try {
            $stmt3 = $pdo->prepare("
                SELECT COUNT(DISTINCT brute_id) + 1 AS rank
                FROM minigame_scores
                WHERE game='snake'
                  AND brute_id != ?
                  AND (SELECT MAX(score) FROM minigame_scores WHERE game='snake' AND brute_id=ms2.brute_id) >
                      (SELECT MAX(score) FROM minigame_scores WHERE game='snake' AND brute_id=?)
                FROM minigame_scores ms2
            ");
        } catch (\Throwable $e) { /* silence */ }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Mini-Jeux — ArenaForge</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" href="../assets/svg/logo/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="../assets/css/main.css">
</head>
<body>
<?php include __DIR__ . '/_nav.php'; ?>

<main class="wrap" style="display:grid;grid-template-columns:1fr 320px;gap:20px;align-items:start">

    <!-- Jeu -->
    <section class="card">
        <h1>🎮 Mini-Jeux</h1>
        <p class="muted">Gagne des récompenses en jouant. 1 récompense toutes les 30 minutes.</p>

        <div class="minigame-rewards-legend">
            <div class="mg-reward-tier">
                <span class="mg-tier-score">5 🍎</span>
                <span class="mg-tier-label">+5 XP</span>
            </div>
            <div class="mg-reward-tier">
                <span class="mg-tier-score">10 🍎</span>
                <span class="mg-tier-label">+10 XP + 5 fragments</span>
            </div>
            <div class="mg-reward-tier highlight">
                <span class="mg-tier-score">20 🍎</span>
                <span class="mg-tier-label">+15 XP + 1 combat bonus !</span>
            </div>
        </div>

        <?php if (!$canPlay): ?>
        <div class="mg-cooldown-notice">
            ⏳ Prochaine récompense dans <strong><?= $waitMinutes ?> min</strong>.
            <br><span class="muted small">Tu peux jouer librement, mais la récompense sera bloquée.</span>
        </div>
        <?php endif; ?>

        <!-- Snake -->
        <div class="snake-wrapper">
            <div class="snake-header">
                <span class="snake-title">🐍 Snake</span>
                <span class="snake-score-display">Score : <strong id="snake-score">0</strong></span>
            </div>

            <div class="snake-canvas-wrap">
                <canvas id="snake-canvas" width="400" height="400"></canvas>

                <div id="snake-overlay" class="snake-overlay" style="display:none">
                    <div class="snake-overlay-inner">
                        <p class="snake-over-title">Partie terminée !</p>
                        <p class="snake-over-score">Score : <strong id="snake-overlay-score">0</strong></p>

                        <?php if ($canPlay): ?>
                        <form id="mg-claim-form">
                            <input type="hidden" name="csrf"     value="<?= h($csrf) ?>">
                            <input type="hidden" name="brute_id" value="<?= $bruteId ?>">
                            <input type="hidden" name="token"    value="<?= h($token) ?>">
                            <input type="hidden" name="score"    id="mg-score" value="0">
                            <button id="mg-claim-btn" class="btn btn-primary" type="submit" disabled>
                                Réclamer la récompense
                            </button>
                        </form>
                        <p id="mg-claim-msg" class="form-msg"></p>
                        <?php else: ?>
                        <p class="muted small">Cooldown actif — rejoue dans <?= $waitMinutes ?> min.</p>
                        <?php endif; ?>

                        <button id="snake-start-overlay" class="btn btn-outline" style="margin-top:10px">🔄 Rejouer</button>
                    </div>
                </div>
            </div>

            <div class="snake-controls">
                <button id="snake-start" class="btn btn-primary">▶ Démarrer</button>
                <span class="muted small snake-kb-hint">Flèches / ZQSD</span>
            </div>

            <div class="snake-dpad" aria-label="Contrôles directionnels">
                <div class="dpad-grid">
                    <button id="dpad-up"    class="dpad-btn" aria-label="Haut">▲</button>
                    <button id="dpad-left"  class="dpad-btn" aria-label="Gauche">◀</button>
                    <button id="dpad-right" class="dpad-btn" aria-label="Droite">▶</button>
                    <button id="dpad-down"  class="dpad-btn" aria-label="Bas">▼</button>
                </div>
            </div>
        </div>
    </section>

    <!-- Leaderboard -->
    <section class="card">
        <h2>🏆 Meilleurs scores Snake</h2>
        <p class="muted small" style="margin-top:-10px">Classement all-time — meilleur score par joueur.</p>

        <?php if (empty($leaderboard)): ?>
            <p class="muted">Aucun score encore. Sois le premier !</p>
        <?php else: ?>
        <div class="mg-leaderboard">
            <?php foreach ($leaderboard as $i => $row):
                $isMe = ((int)$row['brute_id'] === $bruteId);
                $medal = match($i) { 0 => '🥇', 1 => '🥈', 2 => '🥉', default => '#' . ($i + 1) };
            ?>
            <div class="mg-lb-row <?= $isMe ? 'mg-lb-me' : '' ?>">
                <span class="mg-lb-rank"><?= $medal ?></span>
                <a href="brute.php?id=<?= (int)$row['brute_id'] ?>" class="mg-lb-name"><?= h((string)$row['name']) ?></a>
                <span class="mg-lb-score"><?= (int)$row['best_score'] ?> 🍎</span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($myBest !== null): ?>
        <div class="mg-my-best">
            Ton meilleur : <strong><?= (int)$myBest ?> 🍎</strong>
        </div>
        <?php endif; ?>
    </section>

</main>

<script src="../assets/js/snake.js"></script>
<script>
function initMgForm() {
    const form = document.getElementById('mg-claim-form');
    if (!form || form._mgInited) return;
    form._mgInited = true;
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const btn = document.getElementById('mg-claim-btn');
        const msg = document.getElementById('mg-claim-msg');
        if (btn) btn.disabled = true;
        if (msg) { msg.className = 'form-msg'; msg.textContent = '…'; }
        try {
            const res  = await fetch('../api/minigame_reward.php', { method: 'POST', body: new FormData(form) });
            const data = await res.json();
            if (data.ok) {
                if (msg) {
                    msg.className = 'form-msg success';
                    msg.textContent = '🎉 ' + data.label + (data.level_up ? ' — Niveau supérieur !' : '');
                }
                setTimeout(() => { window.location.href = data.redirect; }, 1800);
            } else {
                if (msg) { msg.className = 'form-msg error'; msg.textContent = data.error || 'Erreur'; }
                if (btn) btn.disabled = false;
            }
        } catch {
            if (msg) { msg.className = 'form-msg error'; msg.textContent = 'Erreur réseau'; }
            if (btn) btn.disabled = false;
        }
    });
}
initMgForm();
document.addEventListener('htmx:afterSettle', initMgForm);
</script>
</body>
</html>
