<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';

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
$petId   = (int)($_POST['pet_id'] ?? 0);

if ($petId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Compagnon invalide.']);
    exit;
}

$pdo = db();

// Vérifier que le pet est un pet de base (pas une évolution)
$stmt = $pdo->prepare("SELECT id, name, icon_path FROM pets WHERE id = ? AND evolves_from IS NULL AND rarity = 'commun' LIMIT 1");
$stmt->execute([$petId]);
$pet = $stmt->fetch();
if (!$pet) {
    echo json_encode(['ok' => false, 'error' => 'Ce compagnon n\'est pas disponible.']);
    exit;
}

// Vérifier que la brute n'a pas déjà un pet
$stmt = $pdo->prepare('SELECT 1 FROM brute_pets WHERE brute_id = ? LIMIT 1');
$stmt->execute([$bruteId]);
if ($stmt->fetch()) {
    echo json_encode(['ok' => false, 'error' => 'Tu as déjà un compagnon.']);
    exit;
}

$pdo->prepare('INSERT INTO brute_pets (brute_id, pet_id) VALUES (?, ?)')->execute([$bruteId, $petId]);

echo json_encode([
    'ok'       => true,
    'pet_name' => $pet['name'],
    'redirect' => 'brute.php?id=' . $bruteId,
]);
