<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/dungeon_engine.php';

header('Content-Type: application/json; charset=utf-8');

if (current_user_id() === null) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Non connecté']);
    exit;
}
if (!csrf_check($_POST['csrf'] ?? null)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Jeton CSRF invalide']);
    exit;
}

$brute = current_brute();
if (!$brute) {
    echo json_encode(['ok' => false, 'error' => 'Pas de gladiateur actif.']);
    exit;
}

$bruteId = (int)$brute['id'];
$code    = trim((string)($_POST['code'] ?? ''));

if (!array_key_exists($code, DUNGEON_DEFS)) {
    echo json_encode(['ok' => false, 'error' => 'Donjon inconnu.']);
    exit;
}

$result = dungeon_start($bruteId, $code);
echo json_encode($result);
