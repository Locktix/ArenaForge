<?php
// Boss PvE journalier
//
// Un seul boss par jour, généré déterministiquement à partir de la date.
// Chaque brute a droit à 1 tentative / jour. Le boss n'est PAS stocké dans
// `brutes` (il vit uniquement dans `daily_bosses`). Le combat est résolu
// via `run_combat_loop` après construction d'un combattant synthétique.

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/combat_engine.php';
require_once __DIR__ . '/brute_generator.php';
require_once __DIR__ . '/pet_evolution.php';
require_once __DIR__ . '/codex_engine.php';

const BOSS_NAMES = [
    'Krakos',  'Verminus', 'Asur', 'Brokk',
    'Drakon',  'Fenris',   'Ogrek', 'Tartarus',
    'Lupercus', 'Morthul',
];

// ============================================================
// Génération du boss du jour
// ============================================================

function ensure_today_boss(): array
{
    $pdo = db();
    $today = date('Y-m-d');

    $stmt = $pdo->prepare('SELECT * FROM daily_bosses WHERE boss_date = ? LIMIT 1');
    $stmt->execute([$today]);
    $boss = $stmt->fetch();
    if ($boss) return $boss;

    // Seed : hash de la date
    $seed = (int)(hexdec(substr(md5($today), 0, 8)) & 0x7FFFFFFF);

    $name  = BOSS_NAMES[seeded_int($seed, 0, count(BOSS_NAMES) - 1)];
    $level = 6 + seeded_int($seed, 0, 19); // 6..25

    // Stats agressives, scalent avec le niveau
    $hp        = 90 + $level * 10 + seeded_int($seed, 0, 30);
    $strength  = 8 + (int)floor($level / 2) + seeded_int($seed, 0, 4);
    $agility   = 6 + (int)floor($level / 3) + seeded_int($seed, 0, 3);
    $endurance = 6 + (int)floor($level / 3) + seeded_int($seed, 0, 3);

    // Arme : on pioche parmi les plus tranchantes (ID 3..8)
    $stmt = $pdo->query("SELECT id FROM weapons WHERE name IN ('Hache','Epee','Masse','Lance')");
    $weaponIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $weaponId  = $weaponIds ? (int)$weaponIds[seeded_int($seed, 0, count($weaponIds) - 1)] : null;

    // Skill : un parmi les offensifs (Force brute / Coup critique / Rage)
    $stmt = $pdo->query("SELECT id FROM skills WHERE effect_type IN ('dmg_bonus_pct','crit_bonus_pct','rage_pct') AND is_ultimate = 0");
    $skillIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $skillId  = $skillIds ? (int)$skillIds[seeded_int($seed, 0, count($skillIds) - 1)] : null;

    $appearance = [
        'body'  => 2,
        'hair'  => 0,
        'skin'  => 1,
        'color' => seeded_int($seed, 0, 359),
    ];

    $stmt = $pdo->prepare("
        INSERT INTO daily_bosses
          (boss_date, name, level, hp_max, strength, agility, endurance, appearance_seed, weapon_id, skill_id, icon_path, description)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $description = sprintf("Le %s du jour, niveau %d. Survis-lui ou laisse-y une marque.", $name, $level);
    $stmt->execute([
        $today, $name, $level, $hp, $strength, $agility, $endurance,
        json_encode($appearance, JSON_UNESCAPED_UNICODE),
        $weaponId, $skillId,
        'assets/svg/skills/rage.svg',
        $description,
    ]);

    $stmt = $pdo->prepare('SELECT * FROM daily_bosses WHERE boss_date = ? LIMIT 1');
    $stmt->execute([$today]);
    return $stmt->fetch();
}

function get_today_boss(): ?array
{
    $stmt = db()->prepare('SELECT * FROM daily_bosses WHERE boss_date = CURDATE() LIMIT 1');
    $stmt->execute();
    $r = $stmt->fetch();
    return $r ?: null;
}

function get_boss_attempt(int $bossId, int $bruteId): ?array
{
    $stmt = db()->prepare('SELECT * FROM boss_attempts WHERE boss_id = ? AND brute_id = ? LIMIT 1');
    $stmt->execute([$bossId, $bruteId]);
    $r = $stmt->fetch();
    return $r ?: null;
}

function get_boss_leaderboard(int $bossId, int $limit = 10): array
{
    $stmt = db()->prepare('
        SELECT ba.*, b.name, b.level
        FROM boss_attempts ba
        JOIN brutes b ON b.id = ba.brute_id
        WHERE ba.boss_id = ?
        ORDER BY ba.damage_dealt DESC, ba.attempted_at ASC
        LIMIT ?
    ');
    $stmt->bindValue(1, $bossId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

// ============================================================
// Construction du combattant boss
// ============================================================

function build_boss_combatant(array $boss, string $sidePrefix): array
{
    $pdo = db();

    $weapons = [];
    if (!empty($boss['weapon_id'])) {
        $stmt = $pdo->prepare('SELECT * FROM weapons WHERE id = ? LIMIT 1');
        $stmt->execute([(int)$boss['weapon_id']]);
        if ($w = $stmt->fetch()) {
            $w['upgrade_level'] = 0;
            $weapons[] = $w;
        }
    }

    $skills = [];
    if (!empty($boss['skill_id'])) {
        $stmt = $pdo->prepare('SELECT * FROM skills WHERE id = ? LIMIT 1');
        $stmt->execute([(int)$boss['skill_id']]);
        if ($s = $stmt->fetch()) $skills[] = $s;
    }

    return [
        'id'              => -((int)$boss['id']),  // ID négatif pour ne pas collisionner avec brutes
        'role'            => 'master',
        'side'            => $sidePrefix,
        'slot'            => $sidePrefix . '0',
        'name'            => 'Boss ' . (string)$boss['name'],
        'level'           => (int)$boss['level'],
        'hp'              => (int)$boss['hp_max'],
        'hp_max'          => (int)$boss['hp_max'],
        'strength'        => (int)$boss['strength'],
        'agility'         => (int)$boss['agility'],
        'endurance'       => (int)$boss['endurance'],
        'appearance'      => $boss['appearance_seed'],
        'weapons'         => $weapons,
        'skills'          => $skills,
        'armor_reduction' => 2,    // les boss ont un cuir naturel
        'statuses'        => [],
        'ult_used'        => [],
    ];
}

// ============================================================
// Lancement d'une tentative
// ============================================================

function attempt_daily_boss(int $bruteId): array
{
    $boss = ensure_today_boss();
    if (!$boss) {
        return ['ok' => false, 'error' => 'Aucun boss aujourd\'hui'];
    }

    $existing = get_boss_attempt((int)$boss['id'], $bruteId);
    if ($existing) {
        return ['ok' => false, 'error' => 'Tu as déjà tenté ce boss aujourd\'hui'];
    }

    $pdo = db();
    $stmt = $pdo->prepare('SELECT id, name, pending_levelup FROM brutes WHERE id = ? LIMIT 1');
    $stmt->execute([$bruteId]);
    $brute = $stmt->fetch();
    if (!$brute) return ['ok' => false, 'error' => 'Gladiateur introuvable'];
    if ((int)$brute['pending_levelup'] === 1) {
        return ['ok' => false, 'error' => 'Choisis ton bonus de niveau avant d\'affronter le boss'];
    }

    // Construction des combattants
    $left  = build_team($bruteId, 'L');
    $right = ['R0' => build_boss_combatant($boss, 'R')];
    $combatants = array_merge($left, $right);

    $result = run_combat_loop($combatants);

    // Compter les dégâts infligés au boss par le joueur (master + pet)
    $damageDealt = 0;
    $rounds = 0;
    $bossName = 'Boss ' . (string)$boss['name'];
    foreach ($result['log'] as $ev) {
        if (($ev['event'] ?? '') === 'hit'
            && ($ev['defender'] ?? '') === $bossName) {
            $damageDealt += (int)($ev['damage'] ?? 0);
        }
        if (isset($ev['turn'])) {
            $rounds = max($rounds, (int)$ev['turn']);
        }
    }

    $won = ($result['winner_id'] === $bruteId);

    // Insertion du fight (context = boss). brute2_id = bruteId car le boss
    // n'a pas d'entrée dans `brutes`. winner_id = bruteId si victoire, sinon
    // NULL (la colonne a été rendue nullable par la migration).
    $pdo->prepare("
        INSERT INTO fights (brute1_id, brute2_id, winner_id, log_json, xp_gained, context)
        VALUES (?, ?, ?, ?, ?, 'boss')
    ")->execute([
        $bruteId, $bruteId,
        $won ? $bruteId : null,
        json_encode($result['log'], JSON_UNESCAPED_UNICODE),
        $won ? 15 : 5,
    ]);
    $fightId = (int)$pdo->lastInsertId();

    // Récompenses (toujours quelque chose, plus généreuses si victoire)
    $rewardXp        = $won ? 15 : 5;
    $rewardFragments = $won ? 30 : 5;
    $rewardGold      = $won ? 50 : 10;

    // Bonus dégâts : +1 or par tranche de 50 dégâts infligés (incite à essayer)
    $rewardGold += (int)floor($damageDealt / 50);

    $stmt = $pdo->prepare('SELECT xp, level, pending_levelup FROM brutes WHERE id = ? LIMIT 1');
    $stmt->execute([$bruteId]);
    $b = $stmt->fetch();
    $newXp    = (int)$b['xp'] + $rewardXp;
    $newLevel = (int)$b['level'];
    $levelUp  = (int)$b['pending_levelup'] === 1;
    while ($newXp >= xp_for_level($newLevel + 1)) {
        $newLevel++;
        $levelUp = true;
    }

    $pdo->prepare('
        UPDATE brutes
        SET xp = ?, level = ?, pending_levelup = ?,
            fragments = fragments + ?,
            gold = gold + ?
        WHERE id = ?
    ')->execute([$newXp, $newLevel, $levelUp ? 1 : 0, $rewardFragments, $rewardGold, $bruteId]);

    // Trace de tentative
    $pdo->prepare('
        INSERT INTO boss_attempts (boss_id, brute_id, fight_id, damage_dealt, won, rounds)
        VALUES (?, ?, ?, ?, ?, ?)
    ')->execute([(int)$boss['id'], $bruteId, $fightId, $damageDealt, $won ? 1 : 0, $rounds]);

    // Pet evolution (joueur uniquement, le boss n'a pas de pets)
    track_pet_combat($bruteId);

    // Codex
    track_codex_usage($bruteId, $result['log']);

    return [
        'ok'              => true,
        'fight_id'        => $fightId,
        'won'             => $won,
        'damage_dealt'    => $damageDealt,
        'reward_xp'       => $rewardXp,
        'reward_fragments'=> $rewardFragments,
        'reward_gold'     => $rewardGold,
        'level_up'        => $levelUp,
        'redirect'        => 'fight.php?id=' . $fightId,
    ];
}
