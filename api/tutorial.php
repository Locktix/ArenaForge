<?php
// API unifiée tutoriel : advance | skip | restart

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/tutorial.php';

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

$action = (string)($_POST['action'] ?? '');

switch ($action) {
    case 'advance':
        $next = tutorial_advance($uid);
        echo json_encode(['ok' => true, 'step' => $next, 'total' => tutorial_step_count()]);
        break;

    case 'skip':
        tutorial_skip($uid);
        echo json_encode(['ok' => true]);
        break;

    case 'restart':
        tutorial_restart($uid);
        echo json_encode(['ok' => true, 'redirect' => 'dashboard.php']);
        break;

    default:
        echo json_encode(['ok' => false, 'error' => 'Action inconnue']);
}
