<?php
// Gestion des quêtes journalières et hebdomadaires

declare(strict_types=1);

require_once __DIR__ . '/db.php';

const DAILY_QUEST_COUNT  = 3;
const WEEKLY_QUEST_COUNT = 3;

function current_week_monday(): string
{
    return date('Y-m-d', strtotime('monday this week'));
}

// ============================================================
// Attribution quotidienne (3 quêtes tirées au hasard par brute/jour)
// ============================================================

function ensure_daily_quests(int $bruteId): array
{
    $pdo = db();

    $stmt = $pdo->prepare('
        SELECT quest_code FROM brute_quests
        WHERE brute_id = ? AND quest_date = CURDATE()
    ');
    $stmt->execute([$bruteId]);
    $existing = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (count($existing) >= DAILY_QUEST_COUNT) {
        return $existing;
    }

    $stmt = $pdo->query("SELECT code FROM quest_definitions WHERE scope = 'daily' ORDER BY RAND()");
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
// Attribution hebdomadaire (3 quêtes tirées au hasard par brute/semaine)
// ============================================================

function ensure_weekly_quests(int $bruteId): array
{
    $pdo    = db();
    $monday = current_week_monday();

    $stmt = $pdo->prepare('
        SELECT quest_code FROM brute_weekly_quests
        WHERE brute_id = ? AND quest_week = ?
    ');
    $stmt->execute([$bruteId, $monday]);
    $existing = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (count($existing) >= WEEKLY_QUEST_COUNT) {
        return $existing;
    }

    $stmt = $pdo->query("SELECT code FROM quest_definitions WHERE scope = 'weekly' ORDER BY RAND()");
    $allCodes = $stmt->fetchAll(PDO::FETCH_COLUMN);
    shuffle($allCodes);

    $picked = array_slice(array_diff($allCodes, $existing), 0, WEEKLY_QUEST_COUNT - count($existing));

    $insert = $pdo->prepare('
        INSERT IGNORE INTO brute_weekly_quests (brute_id, quest_code, quest_week, progress, claimed)
        VALUES (?, ?, ?, 0, 0)
    ');
    foreach ($picked as $code) {
        $insert->execute([$bruteId, $code, $monday]);
    }

    return array_merge($existing, $picked);
}

function get_weekly_quests(int $bruteId): array
{
    ensure_weekly_quests($bruteId);
    $monday = current_week_monday();

    $stmt = db()->prepare('
        SELECT bwq.*, qd.label, qd.description, qd.target, qd.reward_xp, qd.reward_bonus_fights, qd.icon_path
        FROM brute_weekly_quests bwq
        JOIN quest_definitions qd ON qd.code = bwq.quest_code
        WHERE bwq.brute_id = ? AND bwq.quest_week = ?
        ORDER BY qd.code ASC
    ');
    $stmt->execute([$bruteId, $monday]);
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

// Retourne les deltas communs (crits, dodges, damage, damageTaken, won) pour réutilisation hebdo
function _analyse_fight_log(int $bruteId, array $log): array
{
    $myCrits = 0; $myDodges = 0; $myDamage = 0; $damageTakenByMe = 0;
    $s = db()->prepare('SELECT name FROM brutes WHERE id = ? LIMIT 1');
    $s->execute([$bruteId]);
    $myName = (string)$s->fetchColumn();

    foreach ($log as $ev) {
        $type = $ev['event'] ?? '';
        if ($type === 'hit') {
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
    return compact('myCrits', 'myDodges', 'myDamage', 'damageTakenByMe', 'myName');
}

function update_quests_after_fight(int $bruteId, int $opponentLevel, bool $won, array $log, int $fightsToday): array
{
    $pdo = db();
    ensure_daily_quests($bruteId);

    $stmt = $pdo->prepare('SELECT quest_code, progress, claimed FROM brute_quests WHERE brute_id = ? AND quest_date = CURDATE()');
    $stmt->execute([$bruteId]);
    $quests = $stmt->fetchAll();

    $stmt = $pdo->query("SELECT code, target FROM quest_definitions WHERE scope = 'daily'");
    $defs = [];
    foreach ($stmt->fetchAll() as $d) {
        $defs[$d['code']] = (int)$d['target'];
    }

    ['myCrits' => $myCrits, 'myDodges' => $myDodges, 'myDamage' => $myDamage, 'damageTakenByMe' => $dtm] = _analyse_fight_log($bruteId, $log);

    $changes = [];

    foreach ($quests as $q) {
        if ((int)$q['claimed'] === 1) {
            continue;
        }
        $code     = $q['quest_code'];
        $target   = $defs[$code] ?? 1;
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
                if ($won && $dtm === 0) $delta = 1;
                break;
            case 'upset_1':
                if ($won && $opponentLevel > 0) {
                    $s = $pdo->prepare('SELECT level FROM brutes WHERE id = ? LIMIT 1');
                    $s->execute([$bruteId]);
                    if ($opponentLevel > (int)$s->fetchColumn()) $delta = 1;
                }
                break;
            case 'daily_6':
                $delta = max(0, $fightsToday - $progress);
                break;
        }

        if ($delta > 0) {
            $newProgress = min($target, $progress + $delta);
            $pdo->prepare('UPDATE brute_quests SET progress = ? WHERE brute_id = ? AND quest_code = ? AND quest_date = CURDATE()')
                ->execute([$newProgress, $bruteId, $code]);
            $changes[] = ['code' => $code, 'progress' => $newProgress, 'target' => $target, 'done' => $newProgress >= $target];
        }
    }

    return $changes;
}

// ============================================================
// Mise à jour des quêtes hebdomadaires après combat
// ============================================================

function update_weekly_quests_after_fight(int $bruteId, int $opponentLevel, bool $won, array $log): array
{
    $pdo    = db();
    $monday = current_week_monday();
    ensure_weekly_quests($bruteId);

    $stmt = $pdo->prepare('SELECT quest_code, progress, claimed FROM brute_weekly_quests WHERE brute_id = ? AND quest_week = ?');
    $stmt->execute([$bruteId, $monday]);
    $quests = $stmt->fetchAll();

    $stmt = $pdo->query("SELECT code, target FROM quest_definitions WHERE scope = 'weekly'");
    $defs = [];
    foreach ($stmt->fetchAll() as $d) {
        $defs[$d['code']] = (int)$d['target'];
    }

    ['myCrits' => $myCrits, 'myDodges' => $myDodges, 'myDamage' => $myDamage, 'damageTakenByMe' => $dtm] = _analyse_fight_log($bruteId, $log);

    $changes = [];

    foreach ($quests as $q) {
        if ((int)$q['claimed'] === 1) {
            continue;
        }
        $code     = $q['quest_code'];
        $target   = $defs[$code] ?? 1;
        $progress = (int)$q['progress'];
        if ($progress >= $target) {
            continue;
        }

        $delta = 0;
        switch ($code) {
            case 'w_win_20':
                if ($won) $delta = 1;
                break;
            case 'w_crit_20':
                $delta = $myCrits;
                break;
            case 'w_dodge_30':
                $delta = $myDodges;
                break;
            case 'w_damage_1000':
                $delta = $myDamage;
                break;
            case 'w_flawless_3':
                if ($won && $dtm === 0) $delta = 1;
                break;
            case 'w_upset_3':
                if ($won && $opponentLevel > 0) {
                    $s = $pdo->prepare('SELECT level FROM brutes WHERE id = ? LIMIT 1');
                    $s->execute([$bruteId]);
                    if ($opponentLevel > (int)$s->fetchColumn()) $delta = 1;
                }
                break;
        }

        if ($delta > 0) {
            $newProgress = min($target, $progress + $delta);
            $pdo->prepare('UPDATE brute_weekly_quests SET progress = ? WHERE brute_id = ? AND quest_code = ? AND quest_week = ?')
                ->execute([$newProgress, $bruteId, $code, $monday]);
            $changes[] = ['code' => $code, 'progress' => $newProgress, 'target' => $target, 'done' => $newProgress >= $target];
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

// ============================================================
// Réclamation d'une quête hebdomadaire
// ============================================================

function claim_weekly_quest(int $bruteId, string $code): array
{
    $pdo    = db();
    $monday = current_week_monday();

    $stmt = $pdo->prepare('
        SELECT bwq.*, qd.target, qd.reward_xp, qd.reward_bonus_fights
        FROM brute_weekly_quests bwq
        JOIN quest_definitions qd ON qd.code = bwq.quest_code
        WHERE bwq.brute_id = ? AND bwq.quest_code = ? AND bwq.quest_week = ?
        LIMIT 1
    ');
    $stmt->execute([$bruteId, $code, $monday]);
    $q = $stmt->fetch();
    if (!$q) {
        return ['ok' => false, 'error' => 'Quête hebdomadaire introuvable'];
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
        $pdo->prepare('UPDATE brute_weekly_quests SET claimed = 1 WHERE brute_id = ? AND quest_code = ? AND quest_week = ?')
            ->execute([$bruteId, $code, $monday]);

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
