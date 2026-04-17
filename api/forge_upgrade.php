<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/forge_engine.php';
require_once __DIR__ . '/../includes/achievement_engine.php';

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

$bruteId  = (int)($_POST['brute_id'] ?? 0);
$weaponId = (int)($_POST['weapon_id'] ?? 0);

$stmt = db()->prepare('SELECT id FROM brutes WHERE id = ? AND user_id = ? LIMIT 1');
$stmt->execute([$bruteId, $uid]);
if (!$stmt->fetchColumn()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Ce gladiateur ne vous appartient pas']);
    exit;
}

$res = forge_upgrade_weapon($bruteId, $weaponId);
if ($res['ok']) {
    $res['achievements'] = check_achievements_forge($bruteId);
}
echo json_encode($res);
