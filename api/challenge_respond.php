<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/challenge_engine.php';

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

$bruteId     = (int)($_POST['brute_id'] ?? 0);
$challengeId = (int)($_POST['challenge_id'] ?? 0);
$action      = (string)($_POST['action'] ?? '');

$stmt = db()->prepare('SELECT id FROM brutes WHERE id = ? AND user_id = ? LIMIT 1');
$stmt->execute([$bruteId, $uid]);
if (!$stmt->fetchColumn()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Ce gladiateur ne vous appartient pas']);
    exit;
}

try {
    if ($action === 'accept') {
        $res = accept_challenge($bruteId, $challengeId);
        if (!empty($res['ok'])) {
            $res['redirect'] = 'fight.php?id=' . (int)$res['fight_id'];
        }
        echo json_encode($res);
    } elseif ($action === 'decline') {
        echo json_encode(decline_challenge($bruteId, $challengeId));
    } elseif ($action === 'cancel') {
        echo json_encode(cancel_challenge($bruteId, $challengeId));
    } else {
        echo json_encode(['ok' => false, 'error' => 'Action inconnue']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erreur : ' . $e->getMessage()]);
}
