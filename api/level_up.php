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

$bruteId = (int)($_POST['brute_id'] ?? 0);
$choice  = (string)($_POST['choice'] ?? '');

$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM brutes WHERE id = ? AND user_id = ? LIMIT 1');
$stmt->execute([$bruteId, $uid]);
$brute = $stmt->fetch();
if (!$brute || (int)$brute['pending_levelup'] !== 1) {
    echo json_encode(['ok' => false, 'error' => 'Aucun bonus en attente']);
    exit;
}

// Décoder le choix au format "type:payload"
$parts = explode(':', $choice, 2);
$type  = $parts[0] ?? '';
$key   = $parts[1] ?? '';

try {
    $pdo->beginTransaction();

    if ($type === 'stat') {
        $allowed = ['hp_max' => 5, 'strength' => 1, 'agility' => 1, 'endurance' => 1];
        if (!isset($allowed[$key])) {
            throw new RuntimeException('Stat invalide');
        }
        $stmt = $pdo->prepare("UPDATE brutes SET `$key` = `$key` + ? WHERE id = ?");
        $stmt->execute([$allowed[$key], $bruteId]);
    } elseif ($type === 'weapon') {
        $wid = (int)$key;
        // Vérifier que l'arme existe
        $chk = $pdo->prepare('SELECT 1 FROM weapons WHERE id = ? LIMIT 1');
        $chk->execute([$wid]);
        if (!$chk->fetchColumn()) {
            throw new RuntimeException('Arme invalide');
        }
        $pdo->prepare('INSERT IGNORE INTO brute_weapons (brute_id, weapon_id) VALUES (?, ?)')
            ->execute([$bruteId, $wid]);
    } elseif ($type === 'skill') {
        $sid = (int)$key;
        $chk = $pdo->prepare('SELECT 1 FROM skills WHERE id = ? LIMIT 1');
        $chk->execute([$sid]);
        if (!$chk->fetchColumn()) {
            throw new RuntimeException('Compétence invalide');
        }
        $pdo->prepare('INSERT IGNORE INTO brute_skills (brute_id, skill_id) VALUES (?, ?)')
            ->execute([$bruteId, $sid]);
    } elseif ($type === 'pet') {
        $pid = (int)$key;
        $chk = $pdo->prepare('SELECT 1 FROM pets WHERE id = ? LIMIT 1');
        $chk->execute([$pid]);
        if (!$chk->fetchColumn()) {
            throw new RuntimeException('Animal invalide');
        }
        // Un seul pet par brute : vérifier qu'elle n'en a pas déjà
        $chk = $pdo->prepare('SELECT COUNT(*) FROM brute_pets WHERE brute_id = ?');
        $chk->execute([$bruteId]);
        if ((int)$chk->fetchColumn() > 0) {
            throw new RuntimeException('Ce gladiateur possède déjà un animal');
        }
        $pdo->prepare('INSERT IGNORE INTO brute_pets (brute_id, pet_id) VALUES (?, ?)')
            ->execute([$bruteId, $pid]);
    } else {
        throw new RuntimeException('Type de bonus inconnu');
    }

    $pdo->prepare('UPDATE brutes SET pending_levelup = 0 WHERE id = ?')->execute([$bruteId]);
    $pdo->commit();

    echo json_encode(['ok' => true, 'redirect' => 'brute.php?id=' . $bruteId]);
} catch (Throwable $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
