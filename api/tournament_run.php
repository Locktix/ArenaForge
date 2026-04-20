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

$t = today_tournament();
if (!$t) {
    echo json_encode(['ok' => false, 'error' => 'Aucun tournoi du jour']);
    exit;
}
if ($t['status'] === 'finished') {
    echo json_encode(['ok' => false, 'error' => 'Le tournoi est déjà terminé']);
    exit;
}

// Seul un participant humain peut lancer (ownership check)
$stmt = db()->prepare('
    SELECT 1 FROM tournament_entries te
    JOIN brutes b ON b.id = te.brute_id
    WHERE te.tournament_id = ? AND b.user_id = ? AND te.is_ai = 0
    LIMIT 1
');
$stmt->execute([(int)$t['id'], $uid]);
if (!$stmt->fetchColumn()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Vous devez être inscrit pour lancer le tournoi']);
    exit;
}

$res = run_tournament((int)$t['id']);
if (!$res['ok']) {
    echo json_encode($res);
    exit;
}

echo json_encode([
    'ok'        => true,
    'winner_id' => $res['winner_id'],
    'redirect'  => 'tournament.php',
]);
