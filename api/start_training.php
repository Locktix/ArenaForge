<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/combat_engine.php';

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

$pdo = db();

$stmt = $pdo->prepare('SELECT * FROM brutes WHERE id = ? AND user_id = ? LIMIT 1');
$stmt->execute([$bruteId, $uid]);
$brute = $stmt->fetch();
if (!$brute) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Ce gladiateur ne vous appartient pas']);
    exit;
}

// Trouver le Mannequin
$stmt = $pdo->prepare('SELECT id FROM brutes WHERE name = "Mannequin" LIMIT 1');
$stmt->execute();
$dummyId = (int)$stmt->fetchColumn();

if (!$dummyId) {
    echo json_encode(['ok' => false, 'error' => 'Mannequin introuvable.']);
    exit;
}

try {
    // Le Mannequin ne consomme rien, ne rapporte rien.
    $result = run_fight($bruteId, $dummyId);

    // Insertion du combat pour replay (context = training)
    $pdo->prepare('
        INSERT INTO fights (brute1_id, brute2_id, winner_id, log_json, xp_gained, context)
        VALUES (?, ?, ?, ?, 0, "training")
    ')->execute([
        $bruteId, $dummyId, $result['winner_id'],
        json_encode($result['log'], JSON_UNESCAPED_UNICODE)
    ]);
    $fightId = (int)$pdo->lastInsertId();

    echo json_encode([
        'ok'       => true,
        'fight_id' => $fightId,
        'redirect' => 'fight.php?id=' . $fightId,
        'training' => true
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erreur de combat: ' . $e->getMessage()]);
}
