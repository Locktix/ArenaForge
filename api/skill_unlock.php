<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/skill_tree.php';

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

$me = current_brute();
if (!$me) {
    echo json_encode(['ok' => false, 'error' => 'Aucun gladiateur actif']);
    exit;
}

$bruteId = (int)$me['id'];
$nodeId  = (string)($_POST['node_id'] ?? '');

$node = skill_tree_get_node($nodeId);
if (!$node) {
    echo json_encode(['ok' => false, 'error' => 'Nœud inconnu']);
    exit;
}

skill_tree_ensure_schema();
$pdo = db();

$stmt = $pdo->prepare('SELECT skill_points FROM brutes WHERE id = ? LIMIT 1');
$stmt->execute([$bruteId]);
$brute = $stmt->fetch();
if (!$brute) {
    echo json_encode(['ok' => false, 'error' => 'Gladiateur introuvable']);
    exit;
}

$unlocked = skill_tree_nodes_for_brute($bruteId);

if (in_array($nodeId, $unlocked, true)) {
    echo json_encode(['ok' => false, 'error' => 'Nœud déjà débloqué']);
    exit;
}

if (!skill_tree_can_unlock($bruteId, $nodeId, $unlocked)) {
    echo json_encode(['ok' => false, 'error' => 'Prérequis non remplis']);
    exit;
}

$cost = (int)$node['cost'];
if ((int)$brute['skill_points'] < $cost) {
    echo json_encode(['ok' => false, 'error' => 'Points insuffisants (' . $cost . ' requis)']);
    exit;
}

$pdo->beginTransaction();
try {
    $pdo->prepare('INSERT IGNORE INTO brute_skill_nodes (brute_id, node_id) VALUES (?, ?)')
        ->execute([$bruteId, $nodeId]);
    $pdo->prepare('UPDATE brutes SET skill_points = skill_points - ? WHERE id = ?')
        ->execute([$cost, $bruteId]);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erreur interne']);
    exit;
}

echo json_encode(['ok' => true, 'node_id' => $nodeId, 'cost' => $cost]);
