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

$bruteId    = (int)$brute['id'];
$evolvedId  = (int)($_POST['pet_id'] ?? 0);

if ($evolvedId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Paramètre invalide.']);
    exit;
}

$pdo = db();

// Charger le pet évolué et vérifier qu'il a bien un evolves_from
$stmt = $pdo->prepare('SELECT * FROM pets WHERE id = ? AND evolves_from IS NOT NULL LIMIT 1');
$stmt->execute([$evolvedId]);
$evolved = $stmt->fetch();
if (!$evolved) {
    echo json_encode(['ok' => false, 'error' => 'Évolution introuvable.']);
    exit;
}

$basePetId = (int)$evolved['evolves_from'];

// Vérifier que le joueur possède le pet de base
$stmt = $pdo->prepare('SELECT 1 FROM brute_pets WHERE brute_id = ? AND pet_id = ? LIMIT 1');
$stmt->execute([$bruteId, $basePetId]);
if (!$stmt->fetch()) {
    echo json_encode(['ok' => false, 'error' => 'Tu ne possèdes pas le compagnon de base requis.']);
    exit;
}

const EVOLVE_COST = 150;
if ((int)$brute['gold'] < EVOLVE_COST) {
    echo json_encode(['ok' => false, 'error' => sprintf('Il te faut %d or pour évoluer ton compagnon (tu en as %d).', EVOLVE_COST, (int)$brute['gold'])]);
    exit;
}

// Charger le nom du pet de base pour le toast
$stmt = $pdo->prepare('SELECT name FROM pets WHERE id = ? LIMIT 1');
$stmt->execute([$basePetId]);
$baseName = (string)($stmt->fetchColumn() ?: '');

// Transaction : swap du pet + débit or
$pdo->beginTransaction();
try {
    $pdo->prepare('DELETE FROM brute_pets WHERE brute_id = ? AND pet_id = ?')
        ->execute([$bruteId, $basePetId]);
    $pdo->prepare('INSERT INTO brute_pets (brute_id, pet_id) VALUES (?, ?)')
        ->execute([$bruteId, $evolvedId]);
    $pdo->prepare('UPDATE brutes SET gold = gold - ? WHERE id = ?')
        ->execute([EVOLVE_COST, $bruteId]);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    echo json_encode(['ok' => false, 'error' => 'Erreur lors de l\'évolution.']);
    exit;
}

echo json_encode([
    'ok'       => true,
    'redirect' => 'brute.php?id=' . $bruteId,
    'toast'    => [
        'title'       => '✨ ' . $baseName . ' a évolué !',
        'description' => $baseName . ' est devenu ' . $evolved['name'] . '.',
        'icon_path'   => $evolved['icon_path'],
    ],
]);
