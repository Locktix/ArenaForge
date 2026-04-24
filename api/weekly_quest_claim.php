<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/quest_engine.php';

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
$code    = trim((string)($_POST['code'] ?? ''));
if ($bruteId <= 0 || $code === '') {
    echo json_encode(['ok' => false, 'error' => 'Paramètres invalides']);
    exit;
}

$stmt = db()->prepare('SELECT 1 FROM brutes WHERE id = ? AND user_id = ? LIMIT 1');
$stmt->execute([$bruteId, $uid]);
if (!$stmt->fetchColumn()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Ce gladiateur ne vous appartient pas']);
    exit;
}

$res = claim_weekly_quest($bruteId, $code);
if (!$res['ok']) {
    echo json_encode($res);
    exit;
}

echo json_encode([
    'ok'        => true,
    'reward_xp' => $res['reward_xp'],
    'level_up'  => $res['level_up'],
    'redirect'  => 'quests.php?tab=weekly',
]);
