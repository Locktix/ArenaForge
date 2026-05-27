<?php
// Moteur de sacrifice — échange de ressources contre des résultats aléatoires.
// Toutes les transactions passent par perform_sacrifice() qui garantit l'atomicité
// (BEGIN/COMMIT) et l'enregistrement dans l'historique.

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/notif_helper.php';
require_once __DIR__ . '/title_engine.php';

// ============================================================
// Définitions des sacrifices
// ============================================================
// Chaque sacrifice est défini de façon déclarative : coût, cooldown,
// table d'outcomes pondérée. La logique d'application est dans
// apply_outcome() pour rester centralisée et testable.

const SACRIFICE_DEFS = [
    'blood' => [
        'name'          => 'Pacte de Sang',
        'cost_label'    => '15 PV max permanents',
        'cooldown'      => 'daily',
        'icon'          => '🩸',
        'description'   => 'Verse ton sang sur l\'autel. Ta chair s\'affaiblit, mais peut-être que les dieux te récompenseront.',
        'outcomes'      => [
            ['weight' => 50, 'code' => 'jackpot', 'effect' => ['stats' => 2], 'label' => '+2 stats aléatoires'],
            ['weight' => 40, 'code' => 'good',    'effect' => ['stats' => 1], 'label' => '+1 stat aléatoire'],
            ['weight' => 10, 'code' => 'nothing', 'effect' => [],             'label' => 'Les dieux te tournent le dos'],
        ],
    ],
    'fragments' => [
        'name'          => 'Pacte des Forges',
        'cost_label'    => '50 fragments',
        'cooldown'      => 'daily',
        'icon'          => '⚒',
        'description'   => 'Brûle tes fragments dans la forge sacrée. Une arme légendaire pourrait t\'apparaître.',
        'outcomes'      => [
            ['weight' => 50, 'code' => 'weapon',  'effect' => ['weapon_or_upgrade' => 1], 'label' => '+1 arme rare (ou +1 niveau de forge si arsenal complet)'],
            ['weight' => 50, 'code' => 'nothing', 'effect' => [],                         'label' => 'Les fragments se dissolvent en cendres'],
        ],
    ],
    'arena' => [
        'name'          => 'Pacte de l\'Arène',
        'cost_label'    => '150 MMR',
        'cooldown'      => 'daily',
        'icon'          => '⚔',
        'description'   => 'Renonce à ta gloire dans le Panthéon pour devenir plus fort.',
        'outcomes'      => [
            ['weight' => 60, 'code' => 'jackpot', 'effect' => ['stats' => 3],  'label' => '+3 stats aléatoires'],
            ['weight' => 25, 'code' => 'good',    'effect' => ['stats' => 1],  'label' => '+1 stat aléatoire'],
            ['weight' => 15, 'code' => 'bad',     'effect' => ['stats' => -1], 'label' => 'Tu perds une stat en plus du MMR'],
        ],
    ],
    'combats' => [
        'name'          => 'Pacte du Guerrier',
        'cost_label'    => 'Tous tes combats du jour',
        'cooldown'      => 'daily',
        'icon'          => '🗡',
        'description'   => 'Renonce à combattre aujourd\'hui. L\'énergie économisée pourrait te rendre plus fort... ou t\'épuiser.',
        'outcomes'      => [
            ['weight' => 50, 'code' => 'jackpot', 'effect' => ['stats' => 3],  'label' => '+3 stats aléatoires'],
            ['weight' => 30, 'code' => 'good',    'effect' => ['stats' => 1],  'label' => '+1 stat aléatoire'],
            ['weight' => 20, 'code' => 'bad',     'effect' => ['stats' => -2], 'label' => '-2 stats aléatoires (minimum 1)'],
        ],
    ],
    'grand' => [
        'name'          => 'Grand Sacrifice',
        'cost_label'    => '200 XP + 100 fragments',
        'cooldown'      => 'weekly',
        'icon'          => '☠',
        'description'   => 'Le rituel ultime. Une fois par semaine. Le destin peut te récompenser royalement... ou tout te prendre.',
        'outcomes'      => [
            ['weight' => 40, 'code' => 'jackpot', 'effect' => ['skills' => 2],              'label' => '+2 compétences non possédées'],
            ['weight' => 35, 'code' => 'good',    'effect' => ['skills' => 1, 'stats' => 3], 'label' => '+1 compétence + 3 stats'],
            ['weight' => 25, 'code' => 'nothing', 'effect' => [],                            'label' => 'Tout est consumé, rien n\'est rendu'],
        ],
    ],
];

const SACRIFICE_STAT_POOL = ['strength', 'agility', 'endurance'];

// ============================================================
// Cooldowns
// ============================================================

function sacrifice_cooldown_clause(string $type): string
{
    $def = SACRIFICE_DEFS[$type] ?? null;
    if (!$def) return '';
    return $def['cooldown'] === 'weekly'
        ? 'created_at >= NOW() - INTERVAL 7 DAY'
        : 'DATE(created_at) = CURDATE()';
}

function sacrifice_is_available(int $bruteId, string $type): bool
{
    if (!isset(SACRIFICE_DEFS[$type])) return false;
    $clause = sacrifice_cooldown_clause($type);
    $stmt = db()->prepare("SELECT 1 FROM sacrifices WHERE brute_id = ? AND type = ? AND $clause LIMIT 1");
    $stmt->execute([$bruteId, $type]);
    return !$stmt->fetchColumn();
}

function sacrifice_availability_map(int $bruteId): array
{
    $map = [];
    foreach (SACRIFICE_DEFS as $type => $_) {
        $map[$type] = sacrifice_is_available($bruteId, $type);
    }
    return $map;
}

// ============================================================
// Vérification des prérequis (la brute peut-elle payer le coût ?)
// ============================================================

function sacrifice_can_afford(array $brute, string $type): array
{
    switch ($type) {
        case 'blood':
            if ((int)$brute['hp_max'] < 25) {
                return [false, 'Ta santé est trop fragile pour ce sacrifice (minimum 25 PV max).'];
            }
            return [true, ''];
        case 'fragments':
            if ((int)$brute['fragments'] < 50) {
                return [false, 'Il te faut au moins 50 fragments.'];
            }
            return [true, ''];
        case 'arena':
            if ((int)$brute['mmr'] < 250) {
                return [false, 'Ton classement est trop bas (minimum 250 MMR).'];
            }
            return [true, ''];
        case 'combats':
            // Le coût est de consommer la journée — il faut qu'il reste au moins
            // 1 combat à consommer (sinon le sacrifice n'a aucun coût).
            $today = date('Y-m-d');
            $isToday = ($brute['last_fight_date'] ?? '') === $today;
            $consumedToday = $isToday ? (int)$brute['fights_today'] : 0;
            if ($consumedToday >= 6) {
                return [false, 'Tu as déjà épuisé tes combats du jour, le sacrifice n\'a plus de coût.'];
            }
            return [true, ''];
        case 'grand':
            if ((int)$brute['xp'] < 200) {
                return [false, 'Il te faut au moins 200 XP.'];
            }
            if ((int)$brute['fragments'] < 100) {
                return [false, 'Il te faut au moins 100 fragments.'];
            }
            return [true, ''];
    }
    return [false, 'Sacrifice inconnu'];
}

// ============================================================
// Choix pondéré d'un outcome
// ============================================================

function sacrifice_pick_outcome(string $type): array
{
    $def = SACRIFICE_DEFS[$type];
    $total = 0;
    foreach ($def['outcomes'] as $o) $total += (int)$o['weight'];
    $roll = random_int(1, max(1, $total));
    $acc  = 0;
    foreach ($def['outcomes'] as $o) {
        $acc += (int)$o['weight'];
        if ($roll <= $acc) return $o;
    }
    return end($def['outcomes']);
}

// ============================================================
// Application du coût (avant outcome)
// ============================================================

function apply_sacrifice_cost(PDO $pdo, int $bruteId, string $type, array $brute): void
{
    switch ($type) {
        case 'blood':
            $newHp = max(10, (int)$brute['hp_max'] - 15);
            $pdo->prepare('UPDATE brutes SET hp_max = ? WHERE id = ?')->execute([$newHp, $bruteId]);
            break;
        case 'fragments':
            $pdo->prepare('UPDATE brutes SET fragments = fragments - 50 WHERE id = ?')->execute([$bruteId]);
            break;
        case 'arena':
            $pdo->prepare('UPDATE brutes SET mmr = GREATEST(100, mmr - 150) WHERE id = ?')->execute([$bruteId]);
            break;
        case 'combats':
            // On force fights_today à 6 et on reset le bonus_fights de la journée.
            // last_fight_date est mis à aujourd'hui pour que le reset auto kick demain.
            $pdo->prepare('
                UPDATE brutes
                SET fights_today = 6, last_fight_date = CURDATE()
                WHERE id = ?
            ')->execute([$bruteId]);
            break;
        case 'grand':
            $pdo->prepare('UPDATE brutes SET xp = xp - 200, fragments = fragments - 100 WHERE id = ?')->execute([$bruteId]);
            break;
    }
}

// ============================================================
// Application d'un outcome (effets bénéfiques ou maléfiques)
// ============================================================

function apply_outcome(PDO $pdo, int $bruteId, array $effect): array
{
    $details = [];

    if (isset($effect['stats'])) {
        $n = (int)$effect['stats'];
        $changes = [];
        for ($i = 0; $i < abs($n); $i++) {
            $stat = SACRIFICE_STAT_POOL[array_rand(SACRIFICE_STAT_POOL)];
            $delta = $n > 0 ? 1 : -1;
            // Plancher à 1 pour éviter qu'une stat tombe à 0 ou en négatif.
            $pdo->prepare("UPDATE brutes SET `$stat` = GREATEST(1, `$stat` + ?) WHERE id = ?")
                ->execute([$delta, $bruteId]);
            $changes[] = ($delta > 0 ? '+' : '') . $delta . ' ' . $stat;
        }
        $details[] = implode(', ', $changes);
    }

    if (isset($effect['weapon_or_upgrade'])) {
        $detail = grant_weapon_or_upgrade($pdo, $bruteId);
        if ($detail) $details[] = $detail;
    }

    if (isset($effect['skills'])) {
        $n = (int)$effect['skills'];
        for ($i = 0; $i < $n; $i++) {
            $detail = grant_random_skill($pdo, $bruteId);
            if ($detail) {
                $details[] = $detail;
            } else {
                // Toutes les compétences déjà apprises → consolation : +1 stat
                $stat = SACRIFICE_STAT_POOL[array_rand(SACRIFICE_STAT_POOL)];
                $pdo->prepare("UPDATE brutes SET `$stat` = `$stat` + 1 WHERE id = ?")->execute([$bruteId]);
                $details[] = 'maîtrise totale — +1 ' . $stat . ' en consolation';
            }
        }
    }

    return $details;
}

function grant_weapon_or_upgrade(PDO $pdo, int $bruteId): ?string
{
    // Cherche une arme non possédée
    $stmt = $pdo->prepare('
        SELECT id, name FROM weapons
        WHERE id NOT IN (SELECT weapon_id FROM brute_weapons WHERE brute_id = ?)
        ORDER BY RAND() LIMIT 1
    ');
    $stmt->execute([$bruteId]);
    $w = $stmt->fetch();
    if ($w) {
        $pdo->prepare('INSERT IGNORE INTO brute_weapons (brute_id, weapon_id) VALUES (?, ?)')
            ->execute([$bruteId, (int)$w['id']]);
        return 'arme acquise : ' . $w['name'];
    }
    // Arsenal complet → +1 niveau de forge sur une arme possédée non maxée (max 5)
    $stmt = $pdo->prepare('
        SELECT w.id, w.name, COALESCE(bwu.upgrade_level, 0) AS lvl
        FROM brute_weapons bw
        JOIN weapons w ON w.id = bw.weapon_id
        LEFT JOIN brute_weapon_upgrades bwu
          ON bwu.weapon_id = w.id AND bwu.brute_id = bw.brute_id
        WHERE bw.brute_id = ?
          AND COALESCE(bwu.upgrade_level, 0) < 5
        ORDER BY RAND() LIMIT 1
    ');
    $stmt->execute([$bruteId]);
    $row = $stmt->fetch();
    if (!$row) {
        // Tout est maxé → consolation en fragments (le sacrifice n'est jamais nul)
        $pdo->prepare('UPDATE brutes SET fragments = fragments + 30 WHERE id = ?')->execute([$bruteId]);
        return 'arsenal & forge complets — 30 fragments rendus';
    }
    $newLevel = (int)$row['lvl'] + 1;
    $pdo->prepare('
        INSERT INTO brute_weapon_upgrades (brute_id, weapon_id, upgrade_level)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE upgrade_level = VALUES(upgrade_level)
    ')->execute([$bruteId, (int)$row['id'], $newLevel]);
    return 'forge : ' . $row['name'] . ' → niveau ' . $newLevel;
}

function grant_random_skill(PDO $pdo, int $bruteId): ?string
{
    $stmt = $pdo->prepare('
        SELECT id, name FROM skills
        WHERE id NOT IN (SELECT skill_id FROM brute_skills WHERE brute_id = ?)
        ORDER BY RAND() LIMIT 1
    ');
    $stmt->execute([$bruteId]);
    $s = $stmt->fetch();
    if (!$s) return null;
    $pdo->prepare('INSERT IGNORE INTO brute_skills (brute_id, skill_id) VALUES (?, ?)')
        ->execute([$bruteId, (int)$s['id']]);
    return 'compétence apprise : ' . $s['name'];
}

// ============================================================
// Point d'entrée principal
// ============================================================

function perform_sacrifice(int $bruteId, int $userId, string $type): array
{
    if (!isset(SACRIFICE_DEFS[$type])) {
        return ['ok' => false, 'error' => 'Sacrifice inconnu'];
    }

    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM brutes WHERE id = ? AND user_id = ? LIMIT 1');
    $stmt->execute([$bruteId, $userId]);
    $brute = $stmt->fetch();
    if (!$brute) {
        return ['ok' => false, 'error' => 'Gladiateur invalide'];
    }

    if ((int)$brute['level'] < 10) {
        return ['ok' => false, 'error' => 'Ton gladiateur doit atteindre le niveau 10 pour accéder aux rituels de l\'autel.'];
    }

    if (!sacrifice_is_available($bruteId, $type)) {
        $cd = SACRIFICE_DEFS[$type]['cooldown'] === 'weekly' ? 'cette semaine' : 'aujourd\'hui';
        return ['ok' => false, 'error' => "Tu as déjà accompli ce sacrifice $cd."];
    }

    [$ok, $err] = sacrifice_can_afford($brute, $type);
    if (!$ok) {
        return ['ok' => false, 'error' => $err];
    }

    try {
        $pdo->beginTransaction();

        apply_sacrifice_cost($pdo, $bruteId, $type, $brute);

        $outcome = sacrifice_pick_outcome($type);
        $details = apply_outcome($pdo, $bruteId, $outcome['effect']);

        $detailsText = !empty($details) ? implode(' · ', $details) : '';
        $finalLabel = $outcome['label'] . ($detailsText ? ' (' . $detailsText . ')' : '');

        $pdo->prepare('
            INSERT INTO sacrifices (brute_id, type, outcome_code, outcome_label)
            VALUES (?, ?, ?, ?)
        ')->execute([$bruteId, $type, $outcome['code'], $finalLabel]);

        $pdo->commit();
    } catch (Throwable $t) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'error' => 'Erreur durant le rituel : ' . $t->getMessage()];
    }

    $sacDef  = SACRIFICE_DEFS[$type];
    $sacIcon = match($outcome['code']) {
        'jackpot', 'weapon' => 'assets/svg/ui/trophy.svg',
        'bad', 'nothing'    => 'assets/svg/weapons/sword.svg',
        default             => 'assets/svg/skills/rage.svg',
    };
    push_notif(
        $bruteId, 'sacrifice',
        '☠ Rituel : ' . $sacDef['name'],
        $finalLabel,
        'sacrifice.php',
        $sacIcon
    );

    // Vérification des titres (en particulier Maudit des Dieux)
    check_and_award_titles($bruteId);

    return [
        'ok'           => true,
        'type'         => $type,
        'outcome_code' => $outcome['code'],
        'outcome_label'=> $finalLabel,
        'details'      => $details,
    ];
}

// ============================================================
// Historique
// ============================================================

function get_sacrifice_history(int $bruteId, int $limit = 10): array
{
    $limit = max(1, min(50, $limit));
    $stmt = db()->prepare("
        SELECT type, outcome_code, outcome_label, created_at
        FROM sacrifices
        WHERE brute_id = ?
        ORDER BY created_at DESC
        LIMIT $limit
    ");
    $stmt->execute([$bruteId]);
    return $stmt->fetchAll();
}

// ============================================================
// Leaderboard global — "Score d'Audace"
// Quotidien = 1 pt, Grand Sacrifice (hebdo) = 5 pts.
// ============================================================

function get_sacrifice_leaderboard(int $limit = 10): array
{
    $limit = max(1, min(50, $limit));
    $stmt = db()->prepare("
        SELECT
            b.id,
            b.name,
            b.level,
            COUNT(*) AS total_sacrifices,
            SUM(CASE WHEN s.type = 'grand' THEN 5 ELSE 1 END) AS audacity_score,
            SUM(CASE WHEN s.type = 'grand'   THEN 1 ELSE 0 END) AS grand_count,
            SUM(CASE WHEN s.outcome_code IN ('jackpot','weapon') THEN 1 ELSE 0 END) AS jackpot_count,
            SUM(CASE WHEN s.outcome_code = 'nothing' THEN 1 ELSE 0 END) AS nothing_count,
            SUM(CASE WHEN s.outcome_code = 'bad'     THEN 1 ELSE 0 END) AS bad_count
        FROM sacrifices s
        JOIN brutes b ON b.id = s.brute_id
        GROUP BY b.id, b.name, b.level
        ORDER BY audacity_score DESC, total_sacrifices DESC
        LIMIT $limit
    ");
    $stmt->execute();
    return $stmt->fetchAll();
}

function get_sacrifice_rank(int $bruteId): ?array
{
    // Rang d'une brute donnée dans le leaderboard global.
    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total_sacrifices,
            SUM(CASE WHEN type = 'grand' THEN 5 ELSE 1 END) AS audacity_score,
            SUM(CASE WHEN type = 'grand' THEN 1 ELSE 0 END) AS grand_count
        FROM sacrifices
        WHERE brute_id = ?
    ");
    $stmt->execute([$bruteId]);
    $me = $stmt->fetch();
    if (!$me || (int)$me['total_sacrifices'] === 0) return null;

    $stmt = $pdo->prepare("
        SELECT COUNT(*) + 1 AS rank
        FROM (
            SELECT SUM(CASE WHEN type = 'grand' THEN 5 ELSE 1 END) AS score
            FROM sacrifices
            GROUP BY brute_id
            HAVING score > ?
        ) sub
    ");
    $stmt->execute([(int)$me['audacity_score']]);
    $rank = (int)$stmt->fetchColumn();

    return [
        'rank'             => $rank,
        'audacity_score'   => (int)$me['audacity_score'],
        'total_sacrifices' => (int)$me['total_sacrifices'],
        'grand_count'      => (int)$me['grand_count'],
    ];
}
