<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

$uid = current_user_id();
if ($uid === null) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Non connecté']);
    exit;
}

$bruteId = (int)($_GET['brute_id'] ?? 0);
$sinceId = (int)($_GET['since_id'] ?? 0);

$pdo = db();
$stmt = $pdo->prepare('SELECT clan_id FROM brutes WHERE id = ? AND user_id = ? LIMIT 1');
$stmt->execute([$bruteId, $uid]);
$row = $stmt->fetch();
if (!$row) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Ce gladiateur ne vous appartient pas']);
    exit;
}
$clanId = (int)($row['clan_id'] ?? 0);
if ($clanId <= 0) {
    echo json_encode(['ok' => true, 'messages' => []]);
    exit;
}

$stmt = $pdo->prepare('
    SELECT cm.id, cm.body, cm.created_at, b.id AS brute_id, b.name
    FROM clan_messages cm
    JOIN brutes b ON b.id = cm.brute_id
    WHERE cm.clan_id = ? AND cm.id > ?
    ORDER BY cm.id DESC
    LIMIT 80
');
$stmt->execute([$clanId, $sinceId]);
$rows = array_reverse($stmt->fetchAll());

echo json_encode(['ok' => true, 'messages' => $rows]);
