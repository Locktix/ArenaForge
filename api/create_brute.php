<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/brute_generator.php';

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

// Un seul gladiateur par utilisateur pour le MVP
if (current_brute()) {
    echo json_encode(['ok' => false, 'error' => 'Vous possédez déjà un gladiateur']);
    exit;
}

$name = trim((string)($_POST['name'] ?? ''));
if (!preg_match('/^[A-Za-z0-9_\-]{3,20}$/', $name)) {
    echo json_encode(['ok' => false, 'error' => 'Nom invalide (3-20 caractères alphanumériques)']);
    exit;
}

$masterId = null;
if (!empty($_POST['master_name'])) {
    $stmt = db()->prepare('SELECT id FROM brutes WHERE name = ? LIMIT 1');
    $stmt->execute([trim((string)$_POST['master_name'])]);
    if ($m = $stmt->fetch()) {
        $masterId = (int)$m['id'];
    }
}

$petId = (int)($_POST['pet_id'] ?? 0);
if ($petId > 0) {
    $stmt = db()->prepare("SELECT id FROM pets WHERE id = ? AND evolves_from IS NULL AND rarity = 'commun' LIMIT 1");
    $stmt->execute([$petId]);
    if (!$stmt->fetch()) {
        echo json_encode(['ok' => false, 'error' => 'Compagnon invalide.']);
        exit;
    }
}

try {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT id FROM brutes WHERE name = ? LIMIT 1');
    $stmt->execute([$name]);
    if ($stmt->fetch()) {
        echo json_encode(['ok' => false, 'error' => 'Ce nom est déjà pris']);
        exit;
    }
    $bruteId = create_brute($uid, $name, $masterId);
    if ($petId > 0) {
        $pdo->prepare('INSERT INTO brute_pets (brute_id, pet_id) VALUES (?, ?)')->execute([$bruteId, $petId]);
    }
    echo json_encode(['ok' => true, 'brute_id' => $bruteId, 'redirect' => 'brute.php?id=' . $bruteId]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erreur lors de la création']);
}
