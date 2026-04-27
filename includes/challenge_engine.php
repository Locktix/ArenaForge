<?php
// Moteur de défis directs entre joueurs (PvP nominatif)
//
// Règles métier :
//   - Challenger et cible : brutes appartenant à des comptes différents
//   - Pas de défi auto-doublon : un seul "pending" entre challenger -> cible
//   - Expiration automatique après 24h (lazy lors des lectures)
//   - L'acceptation lance immédiatement run_fight(), stocke fight_id
//   - Le combat ne consomme PAS les 6 combats journaliers et n'apporte PAS
//     de combat bonus, mais accorde XP et MMR comme un combat d'arène

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/combat_engine.php';
require_once __DIR__ . '/elo_engine.php';
require_once __DIR__ . '/brute_generator.php';

const CHALLENGE_EXPIRY_HOURS = 24;
const CHALLENGE_MAX_PENDING_OUT = 5; // anti-spam

// ============================================================
// Expiration paresseuse
// ============================================================

function expire_old_challenges(): void
{
    db()->prepare("
        UPDATE challenges
        SET status = 'expired', resolved_at = NOW()
        WHERE status = 'pending'
          AND created_at < (NOW() - INTERVAL ? HOUR)
    ")->execute([CHALLENGE_EXPIRY_HOURS]);
}

// ============================================================
// Lecture
// ============================================================

function get_inbox(int $bruteId): array
{
    expire_old_challenges();
    $stmt = db()->prepare("
        SELECT c.*, b.name AS challenger_name, b.level AS challenger_level, b.mmr AS challenger_mmr
        FROM challenges c
        JOIN brutes b ON b.id = c.challenger_id
        WHERE c.target_id = ?
        ORDER BY c.created_at DESC
        LIMIT 30
    ");
    $stmt->execute([$bruteId]);
    return $stmt->fetchAll();
}

function get_outbox(int $bruteId): array
{
    expire_old_challenges();
    $stmt = db()->prepare("
        SELECT c.*, b.name AS target_name, b.level AS target_level, b.mmr AS target_mmr
        FROM challenges c
        JOIN brutes b ON b.id = c.target_id
        WHERE c.challenger_id = ?
        ORDER BY c.created_at DESC
        LIMIT 30
    ");
    $stmt->execute([$bruteId]);
    return $stmt->fetchAll();
}

function pending_inbox_count(int $bruteId): int
{
    expire_old_challenges();
    $stmt = db()->prepare("
        SELECT COUNT(*) FROM challenges
        WHERE target_id = ? AND status = 'pending'
    ");
    $stmt->execute([$bruteId]);
    return (int)$stmt->fetchColumn();
}

// ============================================================
// Création
// ============================================================

function send_challenge(int $challengerId, string $targetName, string $message): array
{
    $message = trim($message);
    if (mb_strlen($message) > 140) {
        return ['ok' => false, 'error' => 'Message trop long (140 max)'];
    }

    $pdo = db();

    $stmt = $pdo->prepare('SELECT id, user_id, name FROM brutes WHERE id = ? LIMIT 1');
    $stmt->execute([$challengerId]);
    $challenger = $stmt->fetch();
    if (!$challenger) return ['ok' => false, 'error' => 'Challenger introuvable'];

    $stmt = $pdo->prepare('SELECT id, user_id FROM brutes WHERE name = ? LIMIT 1');
    $stmt->execute([$targetName]);
    $target = $stmt->fetch();
    if (!$target) return ['ok' => false, 'error' => 'Cible introuvable'];

    if ((int)$target['id'] === (int)$challenger['id']) {
        return ['ok' => false, 'error' => 'Tu ne peux pas te défier toi-même'];
    }
    if ((int)$target['user_id'] === (int)$challenger['user_id']) {
        return ['ok' => false, 'error' => 'Tu ne peux pas défier tes propres gladiateurs'];
    }

    expire_old_challenges();

    // Doublon : un challenge en attente du même challenger vers la même cible
    $stmt = $pdo->prepare("
        SELECT id FROM challenges
        WHERE challenger_id = ? AND target_id = ? AND status = 'pending'
        LIMIT 1
    ");
    $stmt->execute([$challengerId, (int)$target['id']]);
    if ($stmt->fetchColumn()) {
        return ['ok' => false, 'error' => 'Un défi est déjà en attente pour cette cible'];
    }

    // Anti-spam : pas plus de N défis pending sortants
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM challenges WHERE challenger_id = ? AND status = 'pending'");
    $stmt->execute([$challengerId]);
    if ((int)$stmt->fetchColumn() >= CHALLENGE_MAX_PENDING_OUT) {
        return ['ok' => false, 'error' => 'Trop de défis en attente (max ' . CHALLENGE_MAX_PENDING_OUT . ')'];
    }

    $stmt = $pdo->prepare("
        INSERT INTO challenges (challenger_id, target_id, message, status)
        VALUES (?, ?, ?, 'pending')
    ");
    $stmt->execute([$challengerId, (int)$target['id'], $message]);

    return ['ok' => true, 'challenge_id' => (int)$pdo->lastInsertId()];
}

// ============================================================
// Réponse (accept / decline)
// ============================================================

function decline_challenge(int $bruteId, int $challengeId): array
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM challenges WHERE id = ? LIMIT 1');
    $stmt->execute([$challengeId]);
    $c = $stmt->fetch();
    if (!$c) return ['ok' => false, 'error' => 'Défi introuvable'];
    if ((int)$c['target_id'] !== $bruteId) return ['ok' => false, 'error' => 'Défi non destiné à ce gladiateur'];
    if ($c['status'] !== 'pending') return ['ok' => false, 'error' => 'Défi déjà résolu'];

    $pdo->prepare("UPDATE challenges SET status = 'declined', resolved_at = NOW() WHERE id = ?")
        ->execute([$challengeId]);
    return ['ok' => true];
}

function cancel_challenge(int $bruteId, int $challengeId): array
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM challenges WHERE id = ? LIMIT 1');
    $stmt->execute([$challengeId]);
    $c = $stmt->fetch();
    if (!$c) return ['ok' => false, 'error' => 'Défi introuvable'];
    if ((int)$c['challenger_id'] !== $bruteId) return ['ok' => false, 'error' => 'Tu n\'es pas le challenger'];
    if ($c['status'] !== 'pending') return ['ok' => false, 'error' => 'Défi déjà résolu'];

    $pdo->prepare("UPDATE challenges SET status = 'declined', resolved_at = NOW() WHERE id = ?")
        ->execute([$challengeId]);
    return ['ok' => true];
}

function accept_challenge(int $bruteId, int $challengeId): array
{
    $pdo = db();

    $stmt = $pdo->prepare('SELECT * FROM challenges WHERE id = ? LIMIT 1');
    $stmt->execute([$challengeId]);
    $c = $stmt->fetch();
    if (!$c) return ['ok' => false, 'error' => 'Défi introuvable'];
    if ((int)$c['target_id'] !== $bruteId) return ['ok' => false, 'error' => 'Défi non destiné à ce gladiateur'];
    if ($c['status'] !== 'pending') return ['ok' => false, 'error' => 'Défi déjà résolu'];

    // Vérifier que les deux brutes existent toujours et n'ont pas de pending levelup
    $stmt = $pdo->prepare('SELECT id, name, xp, level, pending_levelup, mmr FROM brutes WHERE id IN (?, ?)');
    $stmt->execute([(int)$c['challenger_id'], (int)$c['target_id']]);
    $bs = [];
    foreach ($stmt->fetchAll() as $r) $bs[(int)$r['id']] = $r;

    $challenger = $bs[(int)$c['challenger_id']] ?? null;
    $target     = $bs[(int)$c['target_id']]     ?? null;
    if (!$challenger || !$target) {
        return ['ok' => false, 'error' => 'Un gladiateur n\'existe plus'];
    }
    if ((int)$challenger['pending_levelup'] === 1 || (int)$target['pending_levelup'] === 1) {
        return ['ok' => false, 'error' => 'Un combattant doit choisir son bonus de niveau avant le combat'];
    }

    // Lancer le combat
    $result = run_fight((int)$challenger['id'], (int)$target['id']);

    // Insertion fight (context = challenge)
    $stmt = $pdo->prepare("
        INSERT INTO fights (brute1_id, brute2_id, winner_id, log_json, xp_gained, context)
        VALUES (?, ?, ?, ?, ?, 'challenge')
    ");
    $isChalWinner = $result['winner_id'] === (int)$challenger['id'];
    $challengerXp = $isChalWinner ? 3 : 1;
    $targetXp     = $isChalWinner ? 1 : 3;
    $stmt->execute([
        (int)$challenger['id'], (int)$target['id'], $result['winner_id'],
        json_encode($result['log'], JSON_UNESCAPED_UNICODE),
        $challengerXp,
    ]);
    $fightId = (int)$pdo->lastInsertId();

    // Appliquer XP + level up potentiel pour les deux
    foreach ([
        ['id' => (int)$challenger['id'], 'xp' => (int)$challenger['xp'], 'level' => (int)$challenger['level'], 'gain' => $challengerXp],
        ['id' => (int)$target['id'],     'xp' => (int)$target['xp'],     'level' => (int)$target['level'],     'gain' => $targetXp],
    ] as $b) {
        $newXp = $b['xp'] + $b['gain'];
        $newLevel = $b['level'];
        $levelUp = false;
        while ($newXp >= xp_for_level($newLevel + 1)) {
            $newLevel++;
            $levelUp = true;
        }
        $pdo->prepare('
            UPDATE brutes
            SET xp = ?, level = ?, pending_levelup = ?
            WHERE id = ?
        ')->execute([$newXp, $newLevel, $levelUp ? 1 : 0, $b['id']]);
    }

    // Récompense fragments + or (plus généreuse que l'arène pour un défi)
    $pdo->prepare('UPDATE brutes SET fragments = fragments + 2 WHERE id IN (?, ?)')
        ->execute([(int)$challenger['id'], (int)$target['id']]);
    $winnerId = $result['winner_id'];
    $loserId  = $winnerId === (int)$challenger['id'] ? (int)$target['id'] : (int)$challenger['id'];
    $pdo->prepare('UPDATE brutes SET gold = gold + 5 WHERE id = ?')->execute([$winnerId]);
    $pdo->prepare('UPDATE brutes SET gold = gold + 2 WHERE id = ?')->execute([$loserId]);

    // ELO
    $winnerForElo = $result['winner_id'];
    $loserForElo  = $winnerForElo === (int)$challenger['id'] ? (int)$target['id'] : (int)$challenger['id'];
    elo_apply_fight($winnerForElo, $loserForElo);

    // Marquer le défi comme accepté
    $pdo->prepare("UPDATE challenges SET status = 'accepted', fight_id = ?, resolved_at = NOW() WHERE id = ?")
        ->execute([$fightId, $challengeId]);

    return ['ok' => true, 'fight_id' => $fightId, 'winner_id' => $result['winner_id']];
}
