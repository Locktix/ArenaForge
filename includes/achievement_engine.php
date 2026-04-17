<?php
// Moteur des achievements (trophées permanents)
// - déclenché après combats, level-ups, changements de collection, tournoi, forge
// - les counters cumulés (crits, dodges, flawless, upsets) sont stockés dans brutes

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/brute_generator.php';

// ============================================================
// Utilitaires bas niveau
// ============================================================

function get_unlocked_codes(int $bruteId): array
{
    $stmt = db()->prepare('SELECT achievement_code FROM brute_achievements WHERE brute_id = ?');
    $stmt->execute([$bruteId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function get_achievement_def(string $code): ?array
{
    static $cache = null;
    if ($cache === null) {
        $stmt = db()->query('SELECT * FROM achievements');
        $cache = [];
        foreach ($stmt->fetchAll() as $row) {
            $cache[$row['code']] = $row;
        }
    }
    return $cache[$code] ?? null;
}

/**
 * Débloque un achievement (si pas déjà obtenu) et applique la récompense XP
 * avec level-ups en cascade. Retourne les infos du trophée, ou null si déjà pris.
 */
function award_achievement(int $bruteId, string $code): ?array
{
    $pdo = db();
    $def = get_achievement_def($code);
    if (!$def) return null;

    // Insertion atomique : si le code existe déjà, INSERT IGNORE renvoie 0 ligne
    $stmt = $pdo->prepare('
        INSERT IGNORE INTO brute_achievements (brute_id, achievement_code)
        VALUES (?, ?)
    ');
    $stmt->execute([$bruteId, $code]);
    if ($stmt->rowCount() === 0) return null;

    $rewardXp = (int)$def['reward_xp'];
    if ($rewardXp > 0) {
        $stmt = $pdo->prepare('SELECT xp, level, pending_levelup FROM brutes WHERE id = ? LIMIT 1');
        $stmt->execute([$bruteId]);
        $b = $stmt->fetch();
        if ($b) {
            $newXp    = (int)$b['xp'] + $rewardXp;
            $newLevel = (int)$b['level'];
            $levelUp  = (int)$b['pending_levelup'] === 1;
            while ($newXp >= xp_for_level($newLevel + 1)) {
                $newLevel++;
                $levelUp = true;
            }
            $pdo->prepare('
                UPDATE brutes
                SET xp = ?, level = ?, pending_levelup = ?
                WHERE id = ?
            ')->execute([$newXp, $newLevel, $levelUp ? 1 : 0, $bruteId]);
        }
    }

    return [
        'code'        => $def['code'],
        'title'       => $def['title'],
        'description' => $def['description'],
        'reward_xp'   => $rewardXp,
        'icon_path'   => $def['icon_path'],
        'category'    => $def['category'],
    ];
}

// ============================================================
// Analyse du log de combat — counters locaux (combat courant)
// ============================================================

/**
 * Retourne : crits_in_fight, dodges_in_fight, min_hp_at_end (pour brute), opp_ko_in_one_hit
 */
function analyze_fight_log(array $log, string $bruteName): array
{
    $crits = 0;
    $dodges = 0;
    $damageTaken = 0;
    $myHpFinal = null;
    $oneShotKo = false;
    $oppHpTrack = null;

    foreach ($log as $ev) {
        $type = $ev['event'] ?? '';

        if ($type === 'hit') {
            $attacker = $ev['attacker'] ?? null;
            $defender = $ev['defender'] ?? null;
            $attSlot  = $ev['attacker_slot'] ?? '';

            // Coup porté par le maître joueur (slot L0/R0, pas L1/R1)
            $isMasterAttack = ($attacker === $bruteName) && in_array($attSlot, ['L0', 'R0'], true);

            if ($isMasterAttack) {
                if (!empty($ev['crit'])) $crits++;
                // Premier coup reçu par l'adversaire (pour one-shot)
                if ($oppHpTrack === null) {
                    // Le premier hit permet de voir si l'adversaire tombe à 0 d'un coup
                    if (($ev['def_hp'] ?? 1) <= 0) $oneShotKo = true;
                }
                $oppHpTrack = (int)($ev['def_hp'] ?? 0);
            }

            if ($defender === $bruteName) {
                $damageTaken += (int)($ev['damage'] ?? 0);
                $myHpFinal = (int)($ev['def_hp'] ?? 0);
            } elseif ($attacker === $bruteName && in_array($attSlot, ['L0', 'R0'], true)) {
                // Quand le joueur attaque, son att_hp est aussi renseigné
                if (isset($ev['att_hp'])) $myHpFinal = (int)$ev['att_hp'];
            }
        } elseif ($type === 'dodge') {
            if (($ev['defender'] ?? null) === $bruteName) $dodges++;
        }
    }

    return [
        'crits'         => $crits,
        'dodges'        => $dodges,
        'damage_taken'  => $damageTaken,
        'my_hp_final'   => $myHpFinal,
        'one_shot_ko'   => $oneShotKo,
    ];
}

// ============================================================
// Hooks par trigger
// ============================================================

/**
 * Appelé depuis start_fight.php APRÈS l'update XP/level/fights_today.
 * Incrémente les counters cumulés et vérifie toutes les achievements combat.
 *
 * @return array liste de trophées nouvellement débloqués
 */
function check_achievements_after_fight(
    int $bruteId,
    bool $won,
    array $log,
    int $opponentLevel,
    int $myLevelBefore,
    int $newLevel,
    bool $leveledUp
): array {
    $pdo = db();

    $stmt = $pdo->prepare('SELECT name, total_crits, total_dodges, total_flawless, total_upsets FROM brutes WHERE id = ? LIMIT 1');
    $stmt->execute([$bruteId]);
    $b = $stmt->fetch();
    if (!$b) return [];

    $analysis = analyze_fight_log($log, (string)$b['name']);

    $critsDelta    = $analysis['crits'];
    $dodgesDelta   = $analysis['dodges'];
    $flawlessDelta = ($won && $analysis['damage_taken'] === 0) ? 1 : 0;
    $upsetDelta    = ($won && $opponentLevel > $myLevelBefore) ? 1 : 0;

    $totalCrits    = (int)$b['total_crits']    + $critsDelta;
    $totalDodges   = (int)$b['total_dodges']   + $dodgesDelta;
    $totalFlawless = (int)$b['total_flawless'] + $flawlessDelta;
    $totalUpsets   = (int)$b['total_upsets']   + $upsetDelta;

    $pdo->prepare('
        UPDATE brutes
        SET total_crits = ?, total_dodges = ?, total_flawless = ?, total_upsets = ?
        WHERE id = ?
    ')->execute([$totalCrits, $totalDodges, $totalFlawless, $totalUpsets, $bruteId]);

    // Compte total de victoires (incluant ce combat)
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM fights WHERE winner_id = ?');
    $stmt->execute([$bruteId]);
    $totalWins = (int)$stmt->fetchColumn();

    $already = array_flip(get_unlocked_codes($bruteId));
    $unlocked = [];
    $try = function (string $code, bool $cond) use (&$already, &$unlocked, $bruteId) {
        if ($cond && !isset($already[$code])) {
            $res = award_achievement($bruteId, $code);
            if ($res) $unlocked[] = $res;
            $already[$code] = true;
        }
    };

    // Victoires cumulées
    $try('first_win',  $totalWins >= 1);
    $try('wins_10',    $totalWins >= 10);
    $try('wins_50',    $totalWins >= 50);
    $try('wins_100',   $totalWins >= 100);
    $try('wins_500',   $totalWins >= 500);

    // Stats cumulées
    $try('crits_50',   $totalCrits >= 50);
    $try('crits_200',  $totalCrits >= 200);
    $try('dodges_100', $totalDodges >= 100);
    $try('flawless_10',$totalFlawless >= 10);
    $try('upset_10',   $totalUpsets >= 10);

    // Combat courant
    $try('combo_crit_3',         $analysis['crits'] >= 3);
    $try('survive_overwhelming', $won && $analysis['my_hp_final'] !== null && $analysis['my_hp_final'] > 0 && $analysis['my_hp_final'] < 5);

    // Progression (niveau atteint)
    if ($leveledUp) {
        $try('level_5',  $newLevel >= 5);
        $try('level_10', $newLevel >= 10);
        $try('level_25', $newLevel >= 25);
        $try('level_50', $newLevel >= 50);
    }

    return $unlocked;
}

/**
 * Vérifie les trophées liés à l'acquisition de contenu (après level-up typiquement).
 */
function check_achievements_collection(int $bruteId): array
{
    $pdo = db();

    $counts = [];
    foreach (['weapons' => 'brute_weapons', 'skills' => 'brute_skills', 'pets' => 'brute_pets'] as $key => $table) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE brute_id = ?");
        $stmt->execute([$bruteId]);
        $counts[$key] = (int)$stmt->fetchColumn();
    }

    $already = array_flip(get_unlocked_codes($bruteId));
    $unlocked = [];
    $try = function (string $code, bool $cond) use (&$already, &$unlocked, $bruteId) {
        if ($cond && !isset($already[$code])) {
            $res = award_achievement($bruteId, $code);
            if ($res) $unlocked[] = $res;
            $already[$code] = true;
        }
    };

    $try('weapons_3', $counts['weapons'] >= 3);
    $try('weapons_6', $counts['weapons'] >= 6);
    $try('skills_3',  $counts['skills']  >= 3);
    $try('skills_6',  $counts['skills']  >= 6);
    $try('first_pet', $counts['pets']    >= 1);

    return $unlocked;
}

/**
 * Vérifie les trophées sociaux (pupilles).
 */
function check_achievements_pupils(int $bruteId): array
{
    $pdo = db();

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM pupils WHERE master_id = ?');
    $stmt->execute([$bruteId]);
    $pupilsCount = (int)$stmt->fetchColumn();

    $already = array_flip(get_unlocked_codes($bruteId));
    $unlocked = [];
    $try = function (string $code, bool $cond) use (&$already, &$unlocked, $bruteId) {
        if ($cond && !isset($already[$code])) {
            $res = award_achievement($bruteId, $code);
            if ($res) $unlocked[] = $res;
            $already[$code] = true;
        }
    };

    $try('pupils_1', $pupilsCount >= 1);
    $try('pupils_5', $pupilsCount >= 5);

    return $unlocked;
}

/**
 * Vérifie les trophées de tournoi (participation + victoires).
 */
function check_achievements_tournament(int $bruteId, int $placement): array
{
    $pdo = db();

    // Compte de tournois auxquels il a participé (humains uniquement)
    $stmt = $pdo->prepare('
        SELECT COUNT(*) FROM tournament_entries
        WHERE brute_id = ? AND is_ai = 0
    ');
    $stmt->execute([$bruteId]);
    $totalEntries = (int)$stmt->fetchColumn();

    // Compte de tournois gagnés
    $stmt = $pdo->prepare('
        SELECT COUNT(*) FROM tournament_entries
        WHERE brute_id = ? AND placement = 1
    ');
    $stmt->execute([$bruteId]);
    $totalTournamentWins = (int)$stmt->fetchColumn();

    $already = array_flip(get_unlocked_codes($bruteId));
    $unlocked = [];
    $try = function (string $code, bool $cond) use (&$already, &$unlocked, $bruteId) {
        if ($cond && !isset($already[$code])) {
            $res = award_achievement($bruteId, $code);
            if ($res) $unlocked[] = $res;
            $already[$code] = true;
        }
    };

    $try('tournament_1',      $totalEntries >= 1);
    $try('tournament_win',    $totalTournamentWins >= 1);
    $try('tournament_wins_5', $totalTournamentWins >= 5);

    return $unlocked;
}

/**
 * Vérifie les trophées liés à la forge.
 */
function check_achievements_forge(int $bruteId): array
{
    $pdo = db();

    $stmt = $pdo->prepare('SELECT MAX(upgrade_level) FROM brute_weapon_upgrades WHERE brute_id = ?');
    $stmt->execute([$bruteId]);
    $maxUpgrade = (int)($stmt->fetchColumn() ?: 0);

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM brute_armors WHERE brute_id = ?');
    $stmt->execute([$bruteId]);
    $armorCount = (int)$stmt->fetchColumn();

    $already = array_flip(get_unlocked_codes($bruteId));
    $unlocked = [];
    $try = function (string $code, bool $cond) use (&$already, &$unlocked, $bruteId) {
        if ($cond && !isset($already[$code])) {
            $res = award_achievement($bruteId, $code);
            if ($res) $unlocked[] = $res;
            $already[$code] = true;
        }
    };

    $try('forge_first',  $maxUpgrade >= 1);
    $try('forge_master', $maxUpgrade >= 5);
    $try('armor_first',  $armorCount >= 1);

    return $unlocked;
}

/**
 * Vérifie les trophées liés à la création de clan.
 */
function check_achievements_clan(int $bruteId, bool $isFounder): array
{
    $already = array_flip(get_unlocked_codes($bruteId));
    $unlocked = [];

    if ($isFounder && !isset($already['clan_founder'])) {
        $res = award_achievement($bruteId, 'clan_founder');
        if ($res) $unlocked[] = $res;
    }

    return $unlocked;
}

// ============================================================
// Lecture pour la page
// ============================================================

/**
 * Retourne tous les achievements (débloqués ou non) pour une brute,
 * groupés par catégorie, triés par sort_order.
 */
function get_all_achievements_for_brute(int $bruteId): array
{
    $pdo = db();

    $stmt = $pdo->prepare('
        SELECT a.*, ba.unlocked_at
        FROM achievements a
        LEFT JOIN brute_achievements ba
          ON ba.achievement_code = a.code AND ba.brute_id = ?
        ORDER BY a.sort_order ASC
    ');
    $stmt->execute([$bruteId]);
    $rows = $stmt->fetchAll();

    $grouped = [];
    foreach ($rows as $row) {
        $cat = $row['category'];
        if (!isset($grouped[$cat])) $grouped[$cat] = [];
        $grouped[$cat][] = $row;
    }
    return $grouped;
}

function achievement_stats(int $bruteId): array
{
    $pdo = db();
    $total   = (int)$pdo->query('SELECT COUNT(*) FROM achievements')->fetchColumn();
    $stmt    = $pdo->prepare('SELECT COUNT(*) FROM brute_achievements WHERE brute_id = ?');
    $stmt->execute([$bruteId]);
    $earned  = (int)$stmt->fetchColumn();
    return ['total' => $total, 'earned' => $earned];
}
