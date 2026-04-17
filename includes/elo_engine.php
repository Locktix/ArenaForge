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
