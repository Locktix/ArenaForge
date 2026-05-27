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

$bruteId  = (int)$brute['id'];
$weaponId = (int)($_POST['weapon_id'] ?? 0);

if ($weaponId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Arme invalide.']);
    exit;
}

$pdo = db();

// Récupérer l'arme + son prix
$stmt = $pdo->prepare('SELECT * FROM weapons WHERE id = ? LIMIT 1');
$stmt->execute([$weaponId]);
$weapon = $stmt->fetch();
if (!$weapon) {
    echo json_encode(['ok' => false, 'error' => 'Arme introuvable.']);
    exit;
}

// Vérifier niveau minimum
if ((int)$brute['level'] < (int)($weapon['min_level'] ?? 0)) {
    echo json_encode(['ok' => false, 'error' => sprintf('Niveau %d requis pour cette arme.', (int)$weapon['min_level'])]);
    exit;
}

// Prix selon rareté
const SHOP_PRICES = ['commun' => 20, 'rare' => 90, 'epique' => 275];
$rarity = (string)($weapon['rarity'] ?? 'commun');
// Surcharges par arme
const SHOP_OVERRIDES = [
    'Dague'            => 20,
    'Lance'            => 80,
    'Epee'             => 100,
    'Bouclier'         => 90,
    'Masse'            => 110,
    'Hache'            => 250,
    'Bouclier en acier'=> 300,
];
$price = SHOP_OVERRIDES[$weapon['name']] ?? SHOP_PRICES[$rarity] ?? 20;

// Arme "Poings nus" = pas achetable
if ((string)$weapon['name'] === 'Poings nus' || $price === 0) {
    echo json_encode(['ok' => false, 'error' => 'Cette arme n\'est pas en vente.']);
    exit;
}

// Déjà possédée ?
$stmt = $pdo->prepare('SELECT 1 FROM brute_weapons WHERE brute_id = ? AND weapon_id = ? LIMIT 1');
$stmt->execute([$bruteId, $weaponId]);
if ($stmt->fetch()) {
    echo json_encode(['ok' => false, 'error' => 'Tu possèdes déjà cette arme.']);
    exit;
}

// Or suffisant ?
if ((int)$brute['gold'] < $price) {
    echo json_encode(['ok' => false, 'error' => sprintf('Il te faut %d or (tu en as %d).', $price, (int)$brute['gold'])]);
    exit;
}

// Transaction
$pdo->beginTransaction();
try {
    $pdo->prepare('UPDATE brutes SET gold = gold - ? WHERE id = ?')->execute([$price, $bruteId]);
    $pdo->prepare('INSERT INTO brute_weapons (brute_id, weapon_id) VALUES (?, ?)')->execute([$bruteId, $weaponId]);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    echo json_encode(['ok' => false, 'error' => 'Erreur lors de l\'achat.']);
    exit;
}

echo json_encode([
    'ok'          => true,
    'weapon_name' => $weapon['name'],
    'price'       => $price,
    'redirect'    => 'shop.php',
]);
