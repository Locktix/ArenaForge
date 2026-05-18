<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/elo_engine.php';

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
if ($bruteId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Gladiateur invalide']);
    exit;
}

try {
    $pdo  = db();
    $stmt = $pdo->prepare('SELECT id, level, mmr FROM brutes WHERE id = ? AND user_id = ? LIMIT 1');
    $stmt->execute([$bruteId, $uid]);
    $brute = $stmt->fetch();
    if (!$brute) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Ce gladiateur ne vous appartient pas']);
        exit;
    }

    $opps = find_opponents_ranked($bruteId, (int)$brute['level'], (int)$brute['mmr'], 2);
    if (empty($opps)) {
        echo json_encode(['ok' => false, 'error' => 'Aucun adversaire disponible']);
        exit;
    }

    $out = [];
    foreach ($opps as $o) {
        $out[] = [
            'id'              => (int)$o['id'],
            'name'            => (string)$o['name'],
            'level'           => (int)$o['level'],
            'mmr'             => (int)$o['mmr'],
            'appearance_seed' => $o['appearance_seed'],
        ];
    }

    echo json_encode(['ok' => true, 'opponents' => $out]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erreur serveur: ' . $e->getMessage()]);
}
