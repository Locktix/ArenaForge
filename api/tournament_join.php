<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/tournament_engine.php';

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

// Ownership
$stmt = db()->prepare('SELECT id, pending_levelup FROM brutes WHERE id = ? AND user_id = ? LIMIT 1');
$stmt->execute([$bruteId, $uid]);
$brute = $stmt->fetch();
if (!$brute) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Ce gladiateur ne vous appartient pas']);
    exit;
}
if ((int)$brute['pending_levelup'] === 1) {
    echo json_encode(['ok' => false, 'error' => 'Vous devez choisir votre bonus de niveau avant de vous inscrire']);
    exit;
}

$tournamentType = (string)($_POST['tournament_type'] ?? 'daily');

if ($tournamentType === 'weekly') {
    $res = join_weekly_tournament($bruteId);
} else {
    $res = join_tournament($bruteId);
}
if (!$res['ok']) {
    echo json_encode($res);
    exit;
}

echo json_encode([
    'ok'       => true,
    'redirect' => 'tournament.php?tab=' . ($tournamentType === 'weekly' ? 'weekly' : 'daily'),
]);
