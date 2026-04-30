<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/clan_engine.php';

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
$body    = trim((string)($_POST['body'] ?? ''));

if ($body === '') {
    echo json_encode(['ok' => false, 'error' => 'Message vide']);
    exit;
}
if (mb_strlen($body) > 255) {
    echo json_encode(['ok' => false, 'error' => 'Message trop long (255 max)']);
    exit;
}

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
    echo json_encode(['ok' => false, 'error' => 'Tu n\'es dans aucun clan']);
    exit;
}

// Anti-spam : 1 message / 5 secondes par brute
$stmt = $pdo->prepare('
    SELECT TIMESTAMPDIFF(SECOND, MAX(created_at), NOW())
    FROM clan_messages WHERE brute_id = ?
');
$stmt->execute([$bruteId]);
$secs = $stmt->fetchColumn();
if ($secs !== null && (int)$secs < 5) {
    echo json_encode(['ok' => false, 'error' => 'Patiente quelques secondes avant de renvoyer']);
    exit;
}

$pdo->prepare('INSERT INTO clan_messages (clan_id, brute_id, body) VALUES (?, ?, ?)')
    ->execute([$clanId, $bruteId, $body]);

echo json_encode(['ok' => true, 'message_id' => (int)$pdo->lastInsertId()]);
