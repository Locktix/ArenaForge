<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notif_helper.php';
require_once __DIR__ . '/../includes/notification_engine.php';

header('Content-Type: application/json; charset=utf-8');

$brute = current_brute();
if (!$brute) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Non connecté']);
    exit;
}

$bruteId = (int)$brute['id'];
$method  = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// POST : marquer toutes les notifications comme lues
if ($method === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Jeton CSRF invalide']);
        exit;
    }
    mark_notifs_read($bruteId);
    echo json_encode(['ok' => true]);
    exit;
}

// GET : retourner events persistants + notifications live
try {
    $events = get_persistent_notifs($bruteId, 25);
    $live   = get_notifications($brute);

    $unreadCount = 0;
    foreach ($events as $e) {
        if ((int)$e['unread']) $unreadCount++;
    }

    $urgentCount = 0;
    foreach ($live as $n) {
        if (!empty($n['urgent'])) $urgentCount++;
    }

    echo json_encode([
        'ok'           => true,
        'events'       => $events,
        'live'         => $live,
        'unread_count' => $unreadCount,
        'urgent_count' => $urgentCount,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erreur : ' . $e->getMessage()]);
}
