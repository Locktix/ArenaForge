<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');

$since = max(0, (int)($_GET['since'] ?? 0));

try {
    db()->exec('CREATE TABLE IF NOT EXISTS chat_messages (
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        brute_id   INT          NOT NULL,
        brute_name VARCHAR(20)  NOT NULL,
        message    VARCHAR(200) NOT NULL,
        created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_created (created_at),
        INDEX idx_brute   (brute_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
} catch (Throwable $e) {}

try {
    if ($since > 0) {
        // Polling incrémental : uniquement les nouveaux messages
        $stmt = db()->prepare(
            'SELECT id, brute_id, brute_name, message, created_at
             FROM chat_messages WHERE id > ? ORDER BY id ASC LIMIT 50'
        );
        $stmt->execute([$since]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Chargement initial : 40 derniers messages en ordre chronologique
        $stmt = db()->query(
            'SELECT id, brute_id, brute_name, message, created_at
             FROM chat_messages ORDER BY id DESC LIMIT 40'
        );
        $rows = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    echo json_encode(['ok' => true, 'messages' => $rows]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'messages' => []]);
}
