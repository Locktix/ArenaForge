<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/brute_generator.php';
require_once __DIR__ . '/../includes/achievement_engine.php';

header('Content-Type: application/json; charset=utf-8');

$uid = current_user_id();
if (!$uid) {
    echo json_encode(['ok' => false, 'error' => 'Non connecté']);
    exit;
}
if (!csrf_check($_POST['csrf'] ?? null)) {
    echo json_encode(['ok' => false, 'error' => 'CSRF invalide']);
    exit;
}

$bruteId = (int)($_POST['brute_id'] ?? 0);
$score   = (int)($_POST['score']    ?? -1);
$token   = (string)($_POST['token'] ?? '');

// Validation du jeton de session (génère sur la page des mini-jeux)
$sessionToken = (string)($_SESSION['mg_token']      ?? '');
$sessionTime  = (int)  ($_SESSION['mg_token_time']  ?? 0);

if (empty($sessionToken) || $token !== $sessionToken) {
    echo json_encode(['ok' => false, 'error' => 'Session de jeu invalide — recharge la page']);
    exit;
}
if (time() - $sessionTime > 600) {
    unset($_SESSION['mg_token'], $_SESSION['mg_token_time']);
    echo json_encode(['ok' => false, 'error' => 'Session expirée (10 min) — rejoue une partie']);
    exit;
}
// Score plafond raisonnable (50 pommes en 10 min = ~5 sec/pomme)
if ($score < 0 || $score > 50) {
    echo json_encode(['ok' => false, 'error' => 'Score invalide']);
    exit;
}

$pdo  = db();
$stmt = $pdo->prepare('SELECT id, xp, level, pending_levelup, minigame_claimed_at
                        FROM brutes WHERE id = ? AND user_id = ? LIMIT 1');
$stmt->execute([$bruteId, $uid]);
$brute = $stmt->fetch();
if (!$brute) {
    echo json_encode(['ok' => false, 'error' => 'Gladiateur invalide']);
    exit;
}

// Cooldown de 30 min entre deux récompenses
if (!empty($brute['minigame_claimed_at'])) {
    $elapsed = time() - strtotime($brute['minigame_claimed_at']);
    if ($elapsed < 1800) {
        $wait = (int)ceil((1800 - $elapsed) / 60);
        echo json_encode(['ok' => false, 'error' => "Attends encore {$wait} min avant de rejouer"]);
        exit;
    }
}

// Consommer le jeton (anti double-claim)
unset($_SESSION['mg_token'], $_SESSION['mg_token_time']);

// Paliers de récompense
if ($score >= 20) {
    $xpGain    = 15;
    $bonusFight = 1;
    $fragments  = 0;
    $label      = '+15 XP + 1 combat bonus';
} elseif ($score >= 10) {
    $xpGain    = 10;
    $bonusFight = 0;
    $fragments  = 5;
    $label      = '+10 XP + 5 fragments';
} elseif ($score >= 5) {
    $xpGain    = 5;
    $bonusFight = 0;
    $fragments  = 0;
    $label      = '+5 XP';
} else {
    echo json_encode(['ok' => false, 'error' => 'Score insuffisant (5 pommes minimum)']);
    exit;
}

// Calcul XP / level-up
$newXp    = (int)$brute['xp'] + $xpGain;
$newLevel = (int)$brute['level'];
$levelUps = 0;
while ($newXp >= xp_for_level($newLevel + 1)) { $newLevel++; $levelUps++; }
$levelUp = $levelUps > 0;

$pdo->prepare('
    UPDATE brutes
    SET xp = ?, level = ?,
        bonus_fights_available = bonus_fights_available + ?,
        fragments = fragments + ?,
        minigame_claimed_at = NOW()
    WHERE id = ?
')->execute([$newXp, $newLevel, $bonusFight, $fragments, $bruteId]);
if ($levelUps > 0) auto_apply_levelup($pdo, $bruteId, $levelUps);

// Enregistrer le score dans le leaderboard global (toujours, même si hors cooldown)
$pdo->prepare('INSERT INTO minigame_scores (brute_id, game, score) VALUES (?, "snake", ?)')
    ->execute([$bruteId, $score]);

// Trophées mini-jeux
check_achievements_minigame($bruteId);

echo json_encode([
    'ok'          => true,
    'xp_gained'   => $xpGain,
    'bonus_fight' => $bonusFight > 0,
    'fragments'   => $fragments,
    'level_up'    => $levelUp,
    'label'       => $label,
    'redirect'    => 'brute.php?id=' . $bruteId,
]);
