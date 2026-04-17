<?php
// API unifiée pour les actions de clan : create, join, leave, kick

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/clan_engine.php';
require_once __DIR__ . '/../includes/achievement_engine.php';

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
$action  = (string)($_POST['action'] ?? '');

$stmt = db()->prepare('SELECT id FROM brutes WHERE id = ? AND user_id = ? LIMIT 1');
$stmt->execute([$bruteId, $uid]);
if (!$stmt->fetchColumn()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Ce gladiateur ne vous appartient pas']);
    exit;
}

$res = null;
switch ($action) {
    case 'create':
        $res = create_clan(
            $bruteId,
            (string)($_POST['name'] ?? ''),
            (string)($_POST['tag'] ?? ''),
            (string)($_POST['description'] ?? '')
        );
        if ($res['ok']) {
            $res['achievements'] = check_achievements_clan($bruteId, true);
            $res['redirect'] = '/ArenaForge/public/clan.php?id=' . $res['clan_id'];
        }
        break;

    case 'join':
        $clanId = (int)($_POST['clan_id'] ?? 0);
        $res = join_clan($bruteId, $clanId);
        if ($res['ok']) {
            $res['redirect'] = '/ArenaForge/public/clan.php?id=' . $clanId;
        }
        break;

    case 'leave':
        $res = leave_clan($bruteId);
        if ($res['ok']) $res['redirect'] = '/ArenaForge/public/clans.php';
        break;

    case 'kick':
        $targetId = (int)($_POST['target_id'] ?? 0);
        $res = kick_member($bruteId, $targetId);
        break;

    default:
        $res = ['ok' => false, 'error' => 'Action inconnue'];
}

echo json_encode($res);
