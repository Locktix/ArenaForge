<?php
// Système ELO (MMR) + paliers + gestion des saisons

declare(strict_types=1);

require_once __DIR__ . '/db.php';

const ELO_K_FACTOR = 25;
const ELO_BASE     = 1000;

// Paliers (tiers) — bornes inférieures en MMR
const ELO_TIERS = [
    ['code' => 'bronze',   'label' => 'Bronze',     'min' => 0,    'color' => '#c77a2a'],
    ['code' => 'silver',   'label' => 'Argent',     'min' => 900,  'color' => '#b0b0b0'],
    ['code' => 'gold',     'label' => 'Or',         'min' => 1100, 'color' => '#d4a355'],
    ['code' => 'platinum', 'label' => 'Platine',    'min' => 1300, 'color' => '#8cd4e5'],
    ['code' => 'diamond',  'label' => 'Diamant',    'min' => 1500, 'color' => '#b48cf0'],
    ['code' => 'master',   'label' => 'Maître',     'min' => 1700, 'color' => '#f0c170'],
    ['code' => 'legend',   'label' => 'Légende',    'min' => 1900, 'color' => '#ff9a88'],
];

function elo_tier_for(int $mmr): array
{
    $tier = ELO_TIERS[0];
    foreach (ELO_TIERS as $t) {
        if ($mmr >= $t['min']) $tier = $t;
    }
    return $tier;
}

// ============================================================
// Divisions internes (IV → I) à l'intérieur d'un palier
// ============================================================
//
// Chaque palier (sauf Légende) est découpé en 4 divisions de taille
// identique. Le code retourne le label romain et le delta vers la
// prochaine division — pratique pour afficher une jauge.
//
// Légende n'a pas de division : c'est le toit du système.

function elo_tier_max(int $tierIndex): int
{
    if ($tierIndex >= count(ELO_TIERS) - 1) {
        return 9999; // Légende : pas de toit
    }
    return ELO_TIERS[$tierIndex + 1]['min'];
}

function elo_division_for(int $mmr): array
{
    $idx = 0;
    foreach (ELO_TIERS as $i => $t) {
        if ($mmr >= $t['min']) $idx = $i;
    }
    $tier = ELO_TIERS[$idx];

    // Légende : pas de division
    if ($idx >= count(ELO_TIERS) - 1) {
        return [
            'tier'           => $tier,
            'division'       => '',
            'division_label' => $tier['label'],
            'progress_pct'   => 100,
            'next_threshold' => null,
        ];
    }

    $tierMin = (int)$tier['min'];
    $tierMax = elo_tier_max($idx);
    $span    = max(1, $tierMax - $tierMin);
    $bucket  = max(1, (int)floor($span / 4));

    // Division : IV (bas) → I (haut)
    $within = max(0, min($span - 1, $mmr - $tierMin));
    $div    = (int)floor($within / $bucket); // 0..3
    $div    = max(0, min(3, $div));
    $romans = ['IV', 'III', 'II', 'I'];
    $label  = $romans[$div];

    $divMin = $tierMin + $div * $bucket;
    $divMax = ($div === 3) ? $tierMax : ($tierMin + ($div + 1) * $bucket);
    $progress = max(0, min(100, (int)round(($mmr - $divMin) * 100 / max(1, $divMax - $divMin))));

    return [
        'tier'           => $tier,
        'division'       => $label,
        'division_label' => $tier['label'] . ' ' . $label,
        'progress_pct'   => $progress,
        'next_threshold' => $divMax,
    ];
}

// ============================================================
// Récompenses de fin de saison
// ============================================================

const SEASON_REWARDS = [
    'bronze'   => ['gold' => 0,   'title_template' => ''],
    'silver'   => ['gold' => 50,  'title_template' => 'Recrue de la %s'],
    'gold'     => ['gold' => 100, 'title_template' => 'Sergent de la %s'],
    'platinum' => ['gold' => 200, 'title_template' => 'Veteran de la %s'],
    'diamond'  => ['gold' => 350, 'title_template' => 'Champion de la %s'],
    'master'   => ['gold' => 500, 'title_template' => "Maitre de la %s"],
    'legend'   => ['gold' => 750, 'title_template' => 'Legende de la %s'],
];

/**
 * Calcule la récompense actuellement gagnée selon le MMR.
 * Renvoie ['gold' => N, 'title' => ...] (titre vide si pas de récompense).
 */
function season_pending_reward(int $mmr, string $seasonLabel): array
{
    $tier = elo_tier_for($mmr);
    $code = $tier['code'];
    $def  = SEASON_REWARDS[$code] ?? ['gold' => 0, 'title_template' => ''];
    $title = $def['title_template'] === '' ? '' : sprintf($def['title_template'], $seasonLabel);
    return ['gold' => (int)$def['gold'], 'title' => $title, 'tier' => $tier];
}

/**
 * Snapshote la saison courante : pour chaque brute, on archive son MMR,
 * son palier, sa division, et on lui crédite la récompense.
 *
 * Usage : appelé manuellement (script CLI / admin) lors de la clôture.
 * Idempotent grâce à UNIQUE KEY (season_id, brute_id).
 */
function award_season_rewards(int $seasonId): int
{
    $pdo = db();

    $stmt = $pdo->prepare('SELECT * FROM seasons WHERE id = ? LIMIT 1');
    $stmt->execute([$seasonId]);
    $season = $stmt->fetch();
    if (!$season) return 0;

    $rows = $pdo->query('SELECT id, mmr, peak_mmr FROM brutes')->fetchAll();
    $count = 0;

    $insert = $pdo->prepare("
        INSERT IGNORE INTO season_rewards
          (season_id, brute_id, final_mmr, peak_mmr, tier_code, tier_division, title_awarded, gold_awarded)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $bumpGold = $pdo->prepare('UPDATE brutes SET gold = gold + ? WHERE id = ?');

    foreach ($rows as $r) {
        $mmr  = (int)$r['mmr'];
        $div  = elo_division_for($mmr);
        $rew  = season_pending_reward($mmr, (string)$season['label']);
        $code = $div['tier']['code'];
        $insert->execute([
            (int)$season['id'], (int)$r['id'], $mmr, (int)$r['peak_mmr'],
            $code, $div['division'] ?: 'I', $rew['title'], $rew['gold'],
        ]);
        if ($insert->rowCount() > 0) {
            if ($rew['gold'] > 0) {
                $bumpGold->execute([(int)$rew['gold'], (int)$r['id']]);
            }
            $count++;
        }
    }
    return $count;
}

/**
 * Récupère les récompenses passées d'une brute (toutes saisons).
 */
function brute_season_rewards(int $bruteId): array
{
    $stmt = db()->prepare('
        SELECT sr.*, s.label, s.started_at, s.ended_at
        FROM season_rewards sr
        JOIN seasons s ON s.id = sr.season_id
        WHERE sr.brute_id = ?
        ORDER BY s.started_at DESC
    ');
    $stmt->execute([$bruteId]);
    return $stmt->fetchAll();
}

/**
 * Calcule le delta MMR pour l'attaquant après un combat.
 * Retourne [delta_mmr_attacker, delta_mmr_opponent] (symétriques, somme = 0).
 */
function elo_compute_delta(int $myMmr, int $oppMmr, bool $iWon): array
{
    $expectedMine = 1 / (1 + pow(10, ($oppMmr - $myMmr) / 400));
    $scoreMine    = $iWon ? 1 : 0;
    $deltaMine    = (int)round(ELO_K_FACTOR * ($scoreMine - $expectedMine));
    return [$deltaMine, -$deltaMine];
}

/**
 * Applique le delta MMR aux deux brutes, met à jour peak_mmr et retourne
 * les nouveaux MMR.
 */
function elo_apply_fight(int $winnerId, int $loserId): array
{
    $pdo = db();

    $stmt = $pdo->prepare('SELECT id, mmr, peak_mmr FROM brutes WHERE id IN (?, ?)');
    $stmt->execute([$winnerId, $loserId]);
    $rows = [];
    foreach ($stmt->fetchAll() as $r) $rows[(int)$r['id']] = $r;

    if (!isset($rows[$winnerId], $rows[$loserId])) {
        return ['winner' => null, 'loser' => null];
    }

    $wMmr = (int)$rows[$winnerId]['mmr'];
    $lMmr = (int)$rows[$loserId]['mmr'];

    [$wDelta, $lDelta] = elo_compute_delta($wMmr, $lMmr, true);

    $newWinnerMmr = max(0, $wMmr + $wDelta);
    $newLoserMmr  = max(0, $lMmr + $lDelta);

    $newWinnerPeak = max((int)$rows[$winnerId]['peak_mmr'], $newWinnerMmr);
    $newLoserPeak  = max((int)$rows[$loserId]['peak_mmr'], $newLoserMmr);

    $pdo->prepare('UPDATE brutes SET mmr = ?, peak_mmr = ? WHERE id = ?')
        ->execute([$newWinnerMmr, $newWinnerPeak, $winnerId]);
    $pdo->prepare('UPDATE brutes SET mmr = ?, peak_mmr = ? WHERE id = ?')
        ->execute([$newLoserMmr, $newLoserPeak, $loserId]);

    return [
        'winner' => ['delta' => $wDelta, 'new_mmr' => $newWinnerMmr, 'tier' => elo_tier_for($newWinnerMmr)],
        'loser'  => ['delta' => $lDelta, 'new_mmr' => $newLoserMmr,  'tier' => elo_tier_for($newLoserMmr)],
    ];
}

/**
 * Recherche d'adversaire privilégiant la proximité MMR, avec garde-fou niveau.
 */
function find_opponent_ranked(int $bruteId, int $level, int $mmr): ?array
{
    $pdo = db();

    // Palier 1 : MMR ±150 et niveau ±3
    $stmt = $pdo->prepare('
        SELECT b.* FROM brutes b
        WHERE b.id != ?
          AND b.mmr BETWEEN ? AND ?
          AND b.level BETWEEN ? AND ?
          AND b.user_id != (SELECT user_id FROM brutes WHERE id = ?)
        ORDER BY RAND()
        LIMIT 1
    ');
    $stmt->execute([$bruteId, $mmr - 150, $mmr + 150, max(1, $level - 3), $level + 3, $bruteId]);
    $opp = $stmt->fetch();
    if ($opp) return $opp;

    // Palier 2 : MMR ±300 et niveau ±5
    $stmt = $pdo->prepare('
        SELECT b.* FROM brutes b
        WHERE b.id != ?
          AND b.mmr BETWEEN ? AND ?
          AND b.level BETWEEN ? AND ?
          AND b.user_id != (SELECT user_id FROM brutes WHERE id = ?)
        ORDER BY RAND()
        LIMIT 1
    ');
    $stmt->execute([$bruteId, $mmr - 300, $mmr + 300, max(1, $level - 5), $level + 5, $bruteId]);
    $opp = $stmt->fetch();
    if ($opp) return $opp;

    // Palier 3 : niveau ±5, MMR le plus proche possible
    $stmt = $pdo->prepare('
        SELECT b.* FROM brutes b
        WHERE b.id != ?
          AND b.level BETWEEN ? AND ?
          AND b.user_id != (SELECT user_id FROM brutes WHERE id = ?)
        ORDER BY ABS(b.mmr - ?) ASC, RAND()
        LIMIT 1
    ');
    $stmt->execute([$bruteId, max(1, $level - 5), $level + 5, $bruteId, $mmr]);
    $opp = $stmt->fetch();
    if ($opp) return $opp;

    // Ultime fallback : n'importe qui, MMR+niveau le plus proche
    $stmt = $pdo->prepare('
        SELECT b.* FROM brutes b
        WHERE b.id != ?
          AND b.user_id != (SELECT user_id FROM brutes WHERE id = ?)
        ORDER BY (ABS(b.mmr - ?) + ABS(b.level - ?) * 40) ASC, RAND()
        LIMIT 1
    ');
    $stmt->execute([$bruteId, $bruteId, $mmr, $level]);
    return $stmt->fetch() ?: null;
}

// ============================================================
// Classement
// ============================================================

function get_leaderboard(int $limit = 100): array
{
    $stmt = db()->prepare('
        SELECT id, name, level, mmr, peak_mmr, appearance_seed
        FROM brutes
        ORDER BY mmr DESC, level DESC, id ASC
        LIMIT ?
    ');
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function brute_rank(int $bruteId): int
{
    $stmt = db()->prepare('
        SELECT 1 + COUNT(*)
        FROM brutes b2, brutes me
        WHERE me.id = ?
          AND (b2.mmr > me.mmr
               OR (b2.mmr = me.mmr AND b2.level > me.level)
               OR (b2.mmr = me.mmr AND b2.level = me.level AND b2.id < me.id))
    ');
    $stmt->execute([$bruteId]);
    return (int)$stmt->fetchColumn();
}

// ============================================================
// Saisons (remise à zéro MMR tous les 30 jours)
// ============================================================

function current_season(): ?array
{
    $stmt = db()->query('SELECT * FROM seasons WHERE active = 1 ORDER BY started_at DESC LIMIT 1');
    return $stmt->fetch() ?: null;
}
