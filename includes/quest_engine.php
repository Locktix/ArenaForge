<?php
// Gestion des quêtes journalières : attribution, tracking, réclamation

declare(strict_types=1);

require_once __DIR__ . '/db.php';

const DAILY_QUEST_COUNT = 3;

// ============================================================
// Attribution quotidienne (3 quêtes tirées au hasard par brute/jour)
// ============================================================

function ensure_daily_quests(int $bruteId): array
{
    $pdo = db();

    // Si des quêtes existent déjà pour aujourd'hui, rien à faire
    $stmt = $pdo->prepare('
        SELECT quest_code FROM brute_quests
        WHERE brute_id = ? AND quest_date = CURDATE()
    ');
    $stmt->execute([$bruteId]);
    $existing = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (count($existing) >= DAILY_QUEST_COUNT) {
        return $existing;
    }

    // Piocher DAILY_QUEST_COUNT codes parmi les définitions
    $stmt = $pdo->query('SELECT code FROM quest_definitions ORDER BY RAND()');
    $allCodes = $stmt->fetchAll(PDO::FETCH_COLUMN);
    shuffle($allCodes);

    $picked = array_slice(array_diff($allCodes, $existing), 0, DAILY_QUEST_COUNT - count($existing));

    $insert = $pdo->prepare('
        INSERT IGNORE INTO brute_quests (brute_id, quest_code, quest_date, progress, claimed)
        VALUES (?, ?, CURDATE(), 0, 0)
    ');
    foreach ($picked as $code) {
        $insert->execute([$bruteId, $code]);
    }

    return array_merge($existing, $picked);
}

// ============================================================
// Lecture + progression
// ============================================================

function get_daily_quests(int $bruteId): array
{
    ensure_daily_quests($bruteId);

    $stmt = db()->prepare('
        SELECT bq.*, qd.label, qd.description, qd.target, qd.reward_xp, qd.reward_bonus_fights, qd.icon_path
        FROM brute_quests bq
        JOIN quest_definitions qd ON qd.code = bq.quest_code
        WHERE bq.brute_id = ? AND bq.quest_date = CURDATE()
        ORDER BY qd.code ASC
    ');
    $stmt->execute([$bruteId]);
    return $stmt->fetchAll();
}

// ============================================================
// Mise à jour après combat
//   $bruteId          = la brute du joueur
//   $opponentLevel    = niveau adversaire
//   $won              = victoire ?
//   $log              = log du combat (pour compter crits, esquives, etc.)
//   $fightsToday      = compteur de combats du jour APRÈS ce combat
// ============================================================

function update_quests_after_fight(int $bruteId, int $opponentLevel, bool $won, array $log, int $fightsToday): array
{
    $pdo = db();

    ensure_daily_quests($bruteId);

    $stmt = $pdo->prepare('
        SELECT quest_code, progress, claimed FROM brute_quests
        WHERE brute_id = ? AND quest_date = CURDATE()
    ');
    $stmt->execute([$bruteId]);
    $quests = $stmt->fetchAll();

    $stmt = $pdo->query('SELECT code, target FROM quest_definitions');
    $defs = [];
    foreach ($stmt->fetchAll() as $d) {
        $defs[$d['code']] = (int)$d['target'];
    }

    // Analyse du log
    $myCrits = 0;
    $myDodges = 0;
    $myDamage = 0;
    $damageTakenByMe = 0;
    $myName = null;

    // Retrouver le nom de la brute (nom stable dans le log, nécessaire pour filtrer)
    $s = $pdo->prepare('SELECT name FROM brutes WHERE id = ? LIMIT 1');
    $s->execute([$bruteId]);
    $myName = (string)$s->fetchColumn();

    foreach ($log as $ev) {
        $type = $ev['event'] ?? '';
        if ($type === 'hit') {
            // L'attaquant doit être le maître joueur (slot L0 ou R0 selon son côté)
            if (($ev['attacker'] ?? null) === $myName && ($ev['attacker_slot'] ?? '') !== 'L1' && ($ev['attacker_slot'] ?? '') !== 'R1') {
                $myDamage += (int)($ev['damage'] ?? 0);
                if (!empty($ev['crit'])) {
                    $myCrits++;
                }
            }
            if (($ev['defender'] ?? null) === $myName) {
                $damageTakenByMe += (int)($ev['damage'] ?? 0);
            }
        } elseif ($type === 'dodge') {
            if (($ev['defender'] ?? null) === $myName) {
                $myDodges++;
            }
        }
    }

    $changes = [];

    foreach ($quests as $q) {
        if ((int)$q['claimed'] === 1) {
            continue;
        }
        $code = $q['quest_code'];
        $target = $defs[$code] ?? 1;
        $progress = (int)$q['progress'];
        if ($progress >= $target) {
            continue;
        }

        $delta = 0;
        switch ($code) {
            case 'win_3':
            case 'win_5':
                if ($won) $delta = 1;
                break;
            case 'crit_3':
                $delta = $myCrits;
                break;
            case 'dodge_5':
                $delta = $myDodges;
                break;
            case 'damage_100':
                $delta = $myDamage;
                break;
            case 'flawless_1':
                if ($won && $damageTakenByMe === 0) $delta = 1;
                break;
            case 'upset_1':
                if ($won && $opponentLevel > 0) {
                    $s = $pdo->prepare('SELECT level FROM brutes WHERE id = ? LIMIT 1');
                    $s->execute([$bruteId]);
                    $myLevel = (int)$s->fetchColumn();
                    if ($opponentLevel > $myLevel) $delta = 1;
                }
                break;
            case 'daily_6':
                $delta = max(0, $fightsToday - $progress);
                break;
        }

        if ($delta > 0) {
            $newProgress = min($target, $progress + $delta);
            $pdo->prepare('
                UPDATE brute_quests SET progress = ?
                WHERE brute_id = ? AND quest_code = ? AND quest_date = CURDATE()
            ')->execute([$newProgress, $bruteId, $code]);
            $changes[] = [
                'code'     => $code,
                'progress' => $newProgress,
                'target'   => $target,
                'done'     => $newProgress >= $target,
            ];
        }
    }

    return $changes;
}

// ============================================================
// Réclamation de récompense
// ============================================================

function claim_quest(int $bruteId, string $code): array
{
    $pdo = db();

    $stmt = $pdo->prepare('
        SELECT bq.*, qd.target, qd.reward_xp, qd.reward_bonus_fights
        FROM brute_quests bq
        JOIN quest_definitions qd ON qd.code = bq.quest_code
        WHERE bq.brute_id = ? AND bq.quest_code = ? AND bq.quest_date = CURDATE()
        LIMIT 1
    ');
    $stmt->execute([$bruteId, $code]);
    $q = $stmt->fetch();
    if (!$q) {
        return ['ok' => false, 'error' => 'Quête introuvable'];
    }
    if ((int)$q['claimed'] === 1) {
        return ['ok' => false, 'error' => 'Déjà réclamée'];
    }
    if ((int)$q['progress'] < (int)$q['target']) {
        return ['ok' => false, 'error' => 'Quête non terminée'];
    }

    $reward      = (int)$q['reward_xp'];
    $bonusFights = (int)($q['reward_bonus_fights'] ?? 0);

    require_once __DIR__ . '/brute_generator.php';

    $pdo->beginTransaction();
    try {
        $pdo->prepare('
            UPDATE brute_quests SET claimed = 1
            WHERE brute_id = ? AND quest_code = ? AND quest_date = CURDATE()
        ')->execute([$bruteId, $code]);

        // Gain XP + level-up éventuel + combats bonus
        $stmt = $pdo->prepare('SELECT xp, level, pending_levelup FROM brutes WHERE id = ? LIMIT 1');
        $stmt->execute([$bruteId]);
        $b = $stmt->fetch();

        $newXp    = (int)$b['xp'] + $reward;
        $newLevel = (int)$b['level'];
        $levelUp  = (int)$b['pending_levelup'] === 1;
        while ($newXp >= xp_for_level($newLevel + 1)) {
            $newLevel++;
            $levelUp = true;
        }

        $pdo->prepare('
            UPDATE brutes
            SET xp = ?, level = ?, pending_levelup = ?,
                bonus_fights_available = bonus_fights_available + ?
            WHERE id = ?
        ')->execute([$newXp, $newLevel, $levelUp ? 1 : 0, $bonusFights, $bruteId]);

        $pdo->commit();
        return [
            'ok'                  => true,
            'reward_xp'           => $reward,
            'reward_bonus_fights' => $bonusFights,
            'level_up'            => $levelUp,
        ];
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['ok' => false, 'error' => 'Erreur : ' . $e->getMessage()];
    }
}
