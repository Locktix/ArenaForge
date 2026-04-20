<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Méthode non autorisée']);
    exit;
}

if (!csrf_check($_POST['csrf'] ?? null)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Jeton CSRF invalide']);
    exit;
}

$email = trim((string)($_POST['email'] ?? ''));
$pass  = (string)($_POST['password'] ?? '');

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['ok' => false, 'error' => 'Adresse e-mail invalide']);
    exit;
}
if (strlen($pass) < 6) {
    echo json_encode(['ok' => false, 'error' => 'Le mot de passe doit faire au moins 6 caractères']);
    exit;
}

try {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        echo json_encode(['ok' => false, 'error' => 'Adresse déjà utilisée']);
        exit;
    }

    $hash = password_hash($pass, PASSWORD_BCRYPT);
    $stmt = $pdo->prepare('INSERT INTO users (email, password_hash) VALUES (?, ?)');
    $stmt->execute([$email, $hash]);
    $uid = (int)$pdo->lastInsertId();

    login_user($uid);

    echo json_encode(['ok' => true, 'redirect' => 'dashboard.php']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erreur serveur']);
}
