<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/title_engine.php';

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
if ($bruteId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Gladiateur invalide']);
    exit;
}

$stmt = db()->prepare('SELECT id FROM brutes WHERE id = ? AND user_id = ? LIMIT 1');
$stmt->execute([$bruteId, $uid]);
if (!$stmt->fetchColumn()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Ce gladiateur ne vous appartient pas']);
    exit;
}

// Code vide = retirer le titre actif
if ($code === '' || $code === 'none') {
    set_active_title($bruteId, null);
    echo json_encode(['ok' => true, 'active' => null]);
    exit;
}

if (!set_active_title($bruteId, $code)) {
    echo json_encode(['ok' => false, 'error' => 'Titre non débloqué']);
    exit;
}

$def = title_get($code);
echo json_encode([
    'ok'     => true,
    'active' => $code,
    'label'  => $def['label'] ?? $code,
    'bonus'  => title_bonus_summary($def['bonus'] ?? []),
]);
