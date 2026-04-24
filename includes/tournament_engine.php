<?php
// Moteur de tournoi : quotidien (8 participants) et hebdomadaire (16 participants)

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/combat_engine.php';

// ============================================================
// Constantes — tournoi quotidien
// ============================================================

const TOURNAMENT_SIZE = 8;
const TOURNAMENT_XP = [
    1 => 20, // Champion
    2 => 12, // Finaliste
    3 => 6,  // Demi-finaliste
    5 => 2,  // Éliminé en quart
];
const TOURNAMENT_BONUS_FIGHTS = [
    1 => 3, // Champion : 3 combats bonus
    2 => 1, // Finaliste : 1 combat bonus
];

// ============================================================
// Constantes — tournoi hebdomadaire
// ============================================================

const WEEKLY_TOURNAMENT_SIZE = 16;
const WEEKLY_TOURNAMENT_XP = [
    1 => 50,  // Champion
    2 => 30,  // Finaliste
    3 => 15,  // Demi-finaliste
    5 => 6,   // Quart
    9 => 2,   // Tour 1
];
const WEEKLY_TOURNAMENT_BONUS_FIGHTS = [
    1 => 5, // Champion
    2 => 2, // Finaliste
];

// ============================================================
// Helpers : date
// ============================================================

function week_monday(): string
{
    // Lundi de la semaine ISO courante, format YYYY-MM-DD
    return date('Y-m-d', strtotime('monday this week'));
}

// ============================================================
// État courant — quotidien
// ============================================================

function today_tournament(): ?array
{
    $stmt = db()->prepare("SELECT * FROM tournaments WHERE tour_date = CURDATE() AND type = 'daily' LIMIT 1");
    $stmt->execute();
    return $stmt->fetch() ?: null;
}

function ensure_today_tournament(): array
{
    $t = today_tournament();
    if ($t) {
        return $t;
    }
    db()->prepare("INSERT INTO tournaments (tour_date, type, status, size) VALUES (CURDATE(), 'daily', 'open', ?)")
        ->execute([TOURNAMENT_SIZE]);
    return today_tournament();
}

// ============================================================
// État courant — hebdomadaire
// ============================================================

function this_week_tournament(): ?array
{
    $monday = week_monday();
    $stmt = db()->prepare("SELECT * FROM tournaments WHERE tour_date = ? AND type = 'weekly' LIMIT 1");
    $stmt->execute([$monday]);
    return $stmt->fetch() ?: null;
}

function ensure_this_week_tournament(): array
{
    $t = this_week_tournament();
    if ($t) {
        return $t;
    }
    $monday = week_monday();
    db()->prepare("INSERT INTO tournaments (tour_date, type, status, size) VALUES (?, 'weekly', 'open', ?)")
        ->execute([$monday, WEEKLY_TOURNAMENT_SIZE]);
    return this_week_tournament();
}

// ============================================================
// Entrées / matchs (commun)
// ============================================================

function tournament_entries(int $tournamentId): array
{
    $stmt = db()->prepare('
        SELECT te.*, b.name AS brute_name, b.level AS brute_level, b.appearance_seed
        FROM tournament_entries te
        JOIN brutes b ON b.id = te.brute_id
        WHERE te.tournament_id = ?
        ORDER BY te.slot ASC
    ');
    $stmt->execute([$tournamentId]);
    return $stmt->fetchAll();
}

function tournament_matches(int $tournamentId): array
{
    $stmt = db()->prepare('
        SELECT tf.*, b1.name AS n1, b2.name AS n2
        FROM tournament_fights tf
        JOIN brutes b1 ON b1.id = tf.b1_id
        JOIN brutes b2 ON b2.id = tf.b2_id
        WHERE tf.tournament_id = ?
        ORDER BY tf.round ASC, tf.match_idx ASC
    ');
    $stmt->execute([$tournamentId]);
    return $stmt->fetchAll();
}

function is_brute_entered(int $tournamentId, int $bruteId): bool
{
    $stmt = db()->prepare('SELECT 1 FROM tournament_entries WHERE tournament_id = ? AND brute_id = ? LIMIT 1');
    $stmt->execute([$tournamentId, $bruteId]);
    return (bool)$stmt->fetchColumn();
}

// ============================================================
// Inscription — quotidien
// ============================================================

function join_tournament(int $bruteId): array
{
    $pdo = db();

    $stmt = $pdo->prepare('SELECT * FROM brutes WHERE id = ? LIMIT 1');
    $stmt->execute([$bruteId]);
    $brute = $stmt->fetch();
    if (!$brute) {
        return ['ok' => false, 'error' => 'Gladiateur introuvable'];
    }

    $t = ensure_today_tournament();
    if ($t['status'] !== 'open') {
        return ['ok' => false, 'error' => 'Le tournoi du jour est déjà lancé'];
    }
    if (is_brute_entered((int)$t['id'], $bruteId)) {
        return ['ok' => false, 'error' => 'Déjà inscrit au tournoi du jour'];
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM tournament_entries WHERE tournament_id = ?');
    $stmt->execute([(int)$t['id']]);
    $count = (int)$stmt->fetchColumn();
    if ($count >= (int)$t['size']) {
        return ['ok' => false, 'error' => 'Le tournoi est complet'];
    }

    $pdo->prepare('
        INSERT INTO tournament_entries (tournament_id, brute_id, slot, is_ai)
        VALUES (?, ?, ?, 0)
    ')->execute([(int)$t['id'], $bruteId, $count]);

    return ['ok' => true, 'tournament_id' => (int)$t['id']];
}

// ============================================================
// Inscription — hebdomadaire
// ============================================================

function join_weekly_tournament(int $bruteId): array
{
    $pdo = db();

    $stmt = $pdo->prepare('SELECT * FROM brutes WHERE id = ? LIMIT 1');
    $stmt->execute([$bruteId]);
    $brute = $stmt->fetch();
    if (!$brute) {
        return ['ok' => false, 'error' => 'Gladiateur introuvable'];
    }

    $t = ensure_this_week_tournament();
    if ($t['status'] !== 'open') {
        return ['ok' => false, 'error' => 'Le tournoi de la semaine est déjà lancé'];
    }
    if (is_brute_entered((int)$t['id'], $bruteId)) {
        return ['ok' => false, 'error' => 'Déjà inscrit au tournoi de la semaine'];
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM tournament_entries WHERE tournament_id = ?');
    $stmt->execute([(int)$t['id']]);
    $count = (int)$stmt->fetchColumn();
    if ($count >= (int)$t['size']) {
        return ['ok' => false, 'error' => 'Le tournoi de la semaine est complet'];
    }

    $pdo->prepare('
        INSERT INTO tournament_entries (tournament_id, brute_id, slot, is_ai)
        VALUES (?, ?, ?, 0)
    ')->execute([(int)$t['id'], $bruteId, $count]);

    return ['ok' => true, 'tournament_id' => (int)$t['id']];
}

// ============================================================
// Remplissage IA (commun, s'adapte à la taille)
// ============================================================

function fill_with_ai(int $tournamentId): void
{
    $pdo = db();

    $stmt = $pdo->prepare('SELECT * FROM tournaments WHERE id = ? LIMIT 1');
    $stmt->execute([$tournamentId]);
    $t = $stmt->fetch();
    if (!$t || $t['status'] !== 'open') {
        return;
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM tournament_entries WHERE tournament_id = ?');
    $stmt->execute([$tournamentId]);
    $count = (int)$stmt->fetchColumn();

    if ($count >= (int)$t['size']) {
        return;
    }

    // Niveau moyen des inscrits humains
    $stmt = $pdo->prepare('
        SELECT AVG(b.level) FROM tournament_entries te
        JOIN brutes b ON b.id = te.brute_id
        WHERE te.tournament_id = ? AND te.is_ai = 0
    ');
    $stmt->execute([$tournamentId]);
    $avgLevel = (float)$stmt->fetchColumn();
    if ($avgLevel <= 0) {
        $avgLevel = 1.0;
    }

    $needed = (int)$t['size'] - $count;

    $stmt = $pdo->prepare('
        SELECT b.* FROM brutes b
        JOIN users u ON u.id = b.user_id
        WHERE u.email LIKE "bot%@arenaforge.local"
          AND b.id NOT IN (SELECT brute_id FROM tournament_entries WHERE tournament_id = ?)
        ORDER BY ABS(b.level - ?) ASC, RAND()
        LIMIT ?
    ');
    $stmt->bindValue(1, $tournamentId, PDO::PARAM_INT);
    $stmt->bindValue(2, $avgLevel);
    $stmt->bindValue(3, $needed, PDO::PARAM_INT);
    $stmt->execute();
    $bots = $stmt->fetchAll();

    foreach ($bots as $bot) {
        $pdo->prepare('
            INSERT INTO tournament_entries (tournament_id, brute_id, slot, is_ai)
            VALUES (?, ?, ?, 1)
        ')->execute([$tournamentId, (int)$bot['id'], $count++]);
    }
}

// ============================================================
// Résolution du bracket (commun — quotidien et hebdomadaire)
// ============================================================

function run_tournament(int $tournamentId): array
{
    $pdo = db();

    $stmt = $pdo->prepare('SELECT * FROM tournaments WHERE id = ? LIMIT 1');
    $stmt->execute([$tournamentId]);
    $t = $stmt->fetch();
    if (!$t) {
        return ['ok' => false, 'error' => 'Tournoi introuvable'];
    }
    if ($t['status'] === 'finished') {
        return ['ok' => false, 'error' => 'Tournoi déjà terminé'];
    }

    fill_with_ai($tournamentId);

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM tournament_entries WHERE tournament_id = ?');
    $stmt->execute([$tournamentId]);
    if ((int)$stmt->fetchColumn() < (int)$t['size']) {
        return ['ok' => false, 'error' => "Impossible de compléter le tournoi (pas assez d'IA)"];
    }

    $pdo->prepare('UPDATE tournaments SET status = "running" WHERE id = ?')->execute([$tournamentId]);

    // Récupération des participants par slot, puis mélange aléatoire pour les appariements
    $stmt = $pdo->prepare('SELECT brute_id FROM tournament_entries WHERE tournament_id = ? ORDER BY slot ASC');
    $stmt->execute([$tournamentId]);
    $current = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Mélange : les humains inscrits en premiers ne se retrouvent plus automatiquement face à face
    shuffle($current);

    $isWeekly  = ($t['type'] === 'weekly');
    $xpTable   = $isWeekly ? WEEKLY_TOURNAMENT_XP   : TOURNAMENT_XP;
    $bonusTable = $isWeekly ? WEEKLY_TOURNAMENT_BONUS_FIGHTS : TOURNAMENT_BONUS_FIGHTS;
    $context   = $isWeekly ? 'tournament_weekly' : 'tournament';

    $rounds = (int)log(count($current), 2);

    for ($round = 0; $round < $rounds; $round++) {
        $next = [];
        for ($i = 0, $matchIdx = 0; $i < count($current); $i += 2, $matchIdx++) {
            $b1 = (int)$current[$i];
            $b2 = (int)$current[$i + 1];

            $result = run_fight($b1, $b2);

            $stmt = $pdo->prepare('
                INSERT INTO fights (brute1_id, brute2_id, winner_id, log_json, xp_gained, context)
                VALUES (?, ?, ?, ?, 0, ?)
            ');
            $stmt->execute([
                $b1, $b2, $result['winner_id'],
                json_encode($result['log'], JSON_UNESCAPED_UNICODE),
                $context,
            ]);
            $fightId = (int)$pdo->lastInsertId();

            $pdo->prepare('
                INSERT INTO tournament_fights (tournament_id, round, match_idx, fight_id, b1_id, b2_id, winner_id)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ')->execute([$tournamentId, $round, $matchIdx, $fightId, $b1, $b2, $result['winner_id']]);

            $loserId = $result['winner_id'] === $b1 ? $b2 : $b1;
            $placement = tournament_placement_for_round($round, $rounds);
            assign_tournament_placement($pdo, $tournamentId, $loserId, $placement, $xpTable, $bonusTable);

            $next[] = $result['winner_id'];
        }
        $current = $next;
    }

    $champion = (int)$current[0];
    assign_tournament_placement($pdo, $tournamentId, $champion, 1, $xpTable, $bonusTable);

    $pdo->prepare('UPDATE tournaments SET status = "finished", winner_id = ?, finished_at = NOW() WHERE id = ?')
        ->execute([$champion, $tournamentId]);

    return ['ok' => true, 'winner_id' => $champion];
}

function tournament_placement_for_round(int $round, int $totalRounds): int
{
    $roundsLeftAfter = $totalRounds - $round - 1;
    if ($roundsLeftAfter >= 3) return 9;  // Tour 1 (16→8) dans hebdo
    if ($roundsLeftAfter === 2) return 5;
    if ($roundsLeftAfter === 1) return 3;
    return 2;
}

function assign_tournament_placement(
    PDO $pdo, int $tournamentId, int $bruteId, int $placement,
    array $xpTable, array $bonusTable
): void {
    $xp          = $xpTable[$placement]    ?? 1;
    $bonusFights = $bonusTable[$placement] ?? 0;

    $pdo->prepare('UPDATE tournament_entries SET placement = ?, xp_earned = ? WHERE tournament_id = ? AND brute_id = ?')
        ->execute([$placement, $xp, $tournamentId, $bruteId]);

    $stmt = $pdo->prepare('SELECT is_ai FROM tournament_entries WHERE tournament_id = ? AND brute_id = ? LIMIT 1');
    $stmt->execute([$tournamentId, $bruteId]);
    if ((int)$stmt->fetchColumn() === 1) {
        return;
    }

    require_once __DIR__ . '/brute_generator.php';

    $stmt = $pdo->prepare('SELECT xp, level, pending_levelup FROM brutes WHERE id = ? LIMIT 1');
    $stmt->execute([$bruteId]);
    $b = $stmt->fetch();
    if (!$b) {
        return;
    }

    $newXp    = (int)$b['xp'] + $xp;
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

    require_once __DIR__ . '/achievement_engine.php';
    check_achievements_tournament($bruteId, $placement);
}

// ============================================================
// Vue bracket (commun)
// ============================================================

function tournament_bracket(int $tournamentId): array
{
    $entries = tournament_entries($tournamentId);
    $matches = tournament_matches($tournamentId);

    $rounds = [];
    foreach ($matches as $m) {
        $rounds[(int)$m['round']][(int)$m['match_idx']] = [
            'fight_id'  => (int)$m['fight_id'],
            'b1_id'     => (int)$m['b1_id'],
            'b2_id'     => (int)$m['b2_id'],
            'n1'        => $m['n1'],
            'n2'        => $m['n2'],
            'winner_id' => (int)$m['winner_id'],
        ];
    }
    ksort($rounds);
    foreach ($rounds as &$r) {
        ksort($r);
    }

    return [
        'entries' => $entries,
        'rounds'  => $rounds,
    ];
}
