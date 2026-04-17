<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/brute_generator.php';
require_once __DIR__ . '/../includes/combat_engine.php';

header('Content-Type: application/json; charset=utf-8');

$uid = current_user_id();
if ($uid === null) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Non connecté']);
    exit;
}
if (!csrf_check($_POST['csrf'] ?? null)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Jeton CSRF invalide']);
    exit;
}

$bruteId = (int)($_POST['brute_id'] ?? 0);
if ($bruteId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Gladiateur invalide']);
    exit;
}

$pdo = db();

$stmt = $pdo->prepare('SELECT * FROM brutes WHERE id = ? AND user_id = ? LIMIT 1');
$stmt->execute([$bruteId, $uid]);
$brute = $stmt->fetch();
if (!$brute) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Ce gladiateur ne vous appartient pas']);
    exit;
}

// Reset du compteur journalier si changement de jour
$today = date('Y-m-d');
if ($brute['last_fight_date'] !== $today) {
    $pdo->prepare('UPDATE brutes SET fights_today = 0, last_fight_date = ? WHERE id = ?')
        ->execute([$today, $bruteId]);
    $brute['fights_today'] = 0;
    $brute['last_fight_date'] = $today;
}

if ((int)$brute['fights_today'] >= 6) {
    echo json_encode(['ok' => false, 'error' => 'Limite de 6 combats par jour atteinte']);
    exit;
}

if ((int)$brute['pending_levelup'] === 1) {
    echo json_encode(['ok' => false, 'error' => 'Vous devez choisir votre bonus de niveau']);
    exit;
}

$opp = find_opponent($bruteId, (int)$brute['level']);
if (!$opp) {
    echo json_encode(['ok' => false, 'error' => 'Aucun adversaire disponible']);
    exit;
}

try {
    $result = run_fight($bruteId, (int)$opp['id']);

    $isWinner = ($result['winner_id'] === $bruteId);
    $xpGained = $isWinner ? 3 : 1;

    // Bonus pupille : si la brute a un maître, celui-ci gagne 1 XP passif
    $masterBonus = 0;
    if ($brute['master_id']) {
        $pdo->prepare('UPDATE brutes SET xp = xp + 1 WHERE id = ?')
            ->execute([$brute['master_id']]);
        $masterBonus = 1;
    }

    $pdo->prepare('
        INSERT INTO fights (brute1_id, brute2_id, winner_id, log_json, xp_gained)
        VALUES (?, ?, ?, ?, ?)
    ')->execute([
        $bruteId, (int)$opp['id'], $result['winner_id'],
        json_encode($result['log'], JSON_UNESCAPED_UNICODE),
        $xpGained
    ]);
    $fightId = (int)$pdo->lastInsertId();

    // Mise à jour XP / compteur combats
    $newXp     = (int)$brute['xp'] + $xpGained;
    $newLevel  = (int)$brute['level'];
    $levelUp   = false;
    while ($newXp >= xp_for_level($newLevel + 1)) {
        $newLevel++;
        $levelUp = true;
    }

    $pdo->prepare('
        UPDATE brutes
        SET xp = ?, level = ?, fights_today = fights_today + 1, pending_levelup = ?
        WHERE id = ?
    ')->execute([$newXp, $newLevel, $levelUp ? 1 : (int)$brute['pending_levelup'], $bruteId]);

    echo json_encode([
        'ok'            => true,
        'fight_id'      => $fightId,
        'redirect'      => '/ArenaForge/public/fight.php?id=' . $fightId,
        'winner_id'     => $result['winner_id'],
        'xp_gained'     => $xpGained,
        'level_up'      => $levelUp,
        'master_bonus'  => $masterBonus,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erreur de combat: ' . $e->getMessage()]);
}
