<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notification_engine.php';

header('Content-Type: application/json; charset=utf-8');

$brute = current_brute();
if (!$brute) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Non connecté']);
    exit;
}

try {
    $notifs      = get_notifications($brute);
    $urgentCount = 0;
    $actionCount = 0;
    foreach ($notifs as $n) {
        if (!empty($n['urgent']))                                     $urgentCount++;
        if (in_array($n['category'] ?? '', ['urgent', 'action'], true)) $actionCount++;
    }
    echo json_encode([
        'ok'           => true,
        'notifications'=> $notifs,
        'urgent_count' => $urgentCount,
        'action_count' => $actionCount,
        'total'        => count($notifs),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erreur : ' . $e->getMessage()]);
}
