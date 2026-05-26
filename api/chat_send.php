<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_login();
csrf_check($_POST['csrf'] ?? '');
header('Content-Type: application/json');

$brute = current_brute();
if (!$brute) {
    echo json_encode(['ok' => false, 'error' => 'Aucun gladiateur actif']);
    exit;
}

$message = trim($_POST['message'] ?? '');
$len = mb_strlen($message, 'UTF-8');
if ($len < 1 || $len > 200) {
    echo json_encode(['ok' => false, 'error' => 'Message invalide (1 à 200 caractères)']);
    exit;
}

// Rate limit : 1 message toutes les 4 secondes par brute
$stmt = db()->prepare(
    'SELECT created_at FROM chat_messages WHERE brute_id = ? ORDER BY id DESC LIMIT 1'
);
$stmt->execute([(int)$brute['id']]);
$lastAt = $stmt->fetchColumn();
if ($lastAt && (time() - strtotime((string)$lastAt)) < 4) {
    echo json_encode(['ok' => false, 'error' => 'Attends un instant avant de réécrire']);
    exit;
}

// Purge automatique des messages de plus de 7 jours
try {
    db()->exec("DELETE FROM chat_messages WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
} catch (Throwable $e) {}

$stmt = db()->prepare(
    'INSERT INTO chat_messages (brute_id, brute_name, message) VALUES (?, ?, ?)'
);
$stmt->execute([(int)$brute['id'], (string)$brute['name'], $message]);

echo json_encode(['ok' => true, 'id' => (int)db()->lastInsertId()]);
