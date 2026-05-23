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

    require_once __DIR__ . '/title_engine.php';

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
            // Titres de saison : Seigneur de Rome, Éternel Champion, Fils de Mars
            check_and_award_titles((int)$r['id']);
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
 * Recherche d'adversaire par rang réel :
 * Constitue un pool des 3 joueurs les mieux classés (MMR) au-dessus de moi
 * et des 3 joueurs juste en-dessous, puis tire aléatoirement parmi eux.
 * Garantit la variété (pas toujours le même adversaire) tout en restant équitable.
 * Fallback progressif si le pool est trop petit.
 */
function find_opponent_ranked(int $bruteId, int $level, int $mmr): ?array
{
    $pdo = db();

    $excludeSelf = 'AND b.user_id != (SELECT user_id FROM brutes WHERE id = ?)';

    // 3 plus proches AU-DESSUS (MMR ≥ moi, triés croissant = les plus proches d'abord)
    $stmtAbove = $pdo->prepare("
        SELECT b.* FROM brutes b
        WHERE b.id != ? AND b.mmr >= ? $excludeSelf
        ORDER BY b.mmr ASC
        LIMIT 3
    ");
    $stmtAbove->execute([$bruteId, $mmr, $bruteId]);
    $above = $stmtAbove->fetchAll();

    // 3 plus proches EN-DESSOUS (MMR < moi, triés décroissant = les plus proches d'abord)
    $stmtBelow = $pdo->prepare("
        SELECT b.* FROM brutes b
        WHERE b.id != ? AND b.mmr < ? $excludeSelf
        ORDER BY b.mmr DESC
        LIMIT 3
    ");
    $stmtBelow->execute([$bruteId, $mmr, $bruteId]);
    $below = $stmtBelow->fetchAll();

    $pool = array_merge($above, $below);

    if (!empty($pool)) {
        return $pool[array_rand($pool)];
    }

    // Fallback : n'importe qui, le plus proche en score composé
    $stmt = $pdo->prepare("
        SELECT b.* FROM brutes b
        WHERE b.id != ? $excludeSelf
        ORDER BY (ABS(b.mmr - ?) + ABS(b.level - ?) * 40) ASC, RAND()
        LIMIT 1
    ");
    $stmt->execute([$bruteId, $bruteId, $mmr, $level]);
    return $stmt->fetch() ?: null;
}

// Conservé pour compatibilité avec tout appel restant
function find_opponents_ranked(int $bruteId, int $level, int $mmr, int $count = 2): array
{
    $pdo     = db();
    $results = [];
    $usedIds = [$bruteId];

    while (count($results) < $count) {
        $ph   = implode(',', array_fill(0, count($usedIds), '?'));
        $base = array_merge($usedIds, [$bruteId]);
        $opp  = null;

        $tiers = [
            ["AND b.mmr BETWEEN ? AND ? AND b.level BETWEEN ? AND ? ORDER BY ABS(b.mmr - ?) ASC, RAND()",
             [$mmr - 100, $mmr + 100, max(1, $level - 1), $level + 1, $mmr]],
            ["AND b.mmr BETWEEN ? AND ? AND b.level BETWEEN ? AND ? ORDER BY ABS(b.mmr - ?) ASC, RAND()",
             [$mmr - 350, $mmr + 350, max(1, $level - 3), $level + 3, $mmr]],
            ["AND b.level BETWEEN ? AND ? ORDER BY ABS(b.mmr - ?) ASC, RAND()",
             [max(1, $level - 5), $level + 5, $mmr]],
            ["ORDER BY (ABS(b.mmr - ?) + ABS(b.level - ?) * 40) ASC, RAND()",
             [$mmr, $level]],
        ];
        foreach ($tiers as $tier) {
            $extra  = $tier[0];
            $params = $tier[1];
            $stmt = $pdo->prepare("
                SELECT b.id, b.name, b.level, b.mmr, b.appearance_seed
                FROM brutes b
                WHERE b.id NOT IN ($ph)
                  AND b.user_id != (SELECT user_id FROM brutes WHERE id = ?)
                  $extra
                LIMIT 1
            ");
            $stmt->execute(array_merge($base, $params));
            $opp = $stmt->fetch() ?: null;
            if ($opp) break;
        }

        if (!$opp) break;
        $results[] = $opp;
        $usedIds[] = (int)$opp['id'];
    }

    return $results;
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

/**
 * Vérifie si on doit passer à une nouvelle saison (chaque 1er du mois).
 * Automatise la clôture, la distribution des récompenses et le reset MMR.
 */
function check_season_transition(): void
{
    $pdo = db();
    $now = new DateTime();
    $current = current_season();

    // Initialisation si aucune saison n'existe
    if (!$current) {
        $pdo->prepare("INSERT INTO seasons (label, active, started_at) VALUES ('Saison 1', 1, NOW())")
            ->execute();
        return;
    }

    // Vérification du changement de mois
    $started = new DateTime($current['started_at']);
    if ($started->format('Y-m') !== $now->format('Y-m')) {
        // 1) Clôture et récompenses
        award_season_rewards((int)$current['id']);
        
        // 2) Désactivation de l'ancienne saison
        $pdo->prepare('UPDATE seasons SET active = 0, ended_at = NOW() WHERE id = ?')
            ->execute([$current['id']]);

        // 3) Reset MMR global (soft reset : retour à 1000)
        $pdo->query('UPDATE brutes SET mmr = 1000, peak_mmr = 1000');

        // 4) Création de la nouvelle saison avec incrément du label
        $count = (int)$pdo->query('SELECT COUNT(*) FROM seasons')->fetchColumn() + 1;
        $label = "Saison $count";
        $pdo->prepare('INSERT INTO seasons (label, active, started_at) VALUES (?, 1, NOW())')
            ->execute([$label]);
    }
}
