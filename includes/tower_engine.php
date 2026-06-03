<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/brute_generator.php';
require_once __DIR__ . '/combat_engine.php';

const TOWER_ENEMY_NAMES = [
    'easy'  => ['Apprenti Brigand', 'Soldat Rouillé', 'Garde Corrompu', 'Mercenaire Faible', 'Pillard Solitaire'],
    'hard'  => ['Champion des Bas-Fonds', 'Berserker Affamé', 'Gladiateur Déchu', 'Chasseur de Primes', 'Colosse Brisé'],
    'elite' => ['Chevalier de la Mort', 'Démon Mineur', 'Seigneur de Guerre', 'Archonte Damné', 'Titan Fragmenté'],
    'boss'  => ['Ancien Éveillé', 'Dieu de la Guerre', 'Gardien Éternel', 'Fléau Primordial', 'Nightmare Absolu'],
];

// Récompenses aux paliers d'étages
const TOWER_REWARD_SMALL = ['xp' => 6,  'gold' => 12];
const TOWER_REWARD_BIG   = ['xp' => 15, 'gold' => 30];

function tower_ensure_tables(): void
{
    static $done = false;
    if ($done) return;
    $done = true; // Set early so repeated calls don't retry on failure
    try {
        $pdo = db();
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tower_runs (
                id            INT AUTO_INCREMENT PRIMARY KEY,
                brute_id      INT NOT NULL,
                current_floor INT NOT NULL DEFAULT 1,
                status        ENUM('active','defeat') NOT NULL DEFAULT 'active',
                fight_id      INT NULL,
                started_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_brute_status (brute_id, status),
                KEY idx_brute_date   (brute_id, started_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tower_records (
                brute_id    INT NOT NULL PRIMARY KEY,
                best_floor  INT NOT NULL DEFAULT 0,
                achieved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $e) {
        // Tables created via migration_engine.php on first db() call — ignore if already exist
    }
}

function tower_get_active_run(int $bruteId): ?array
{
    tower_ensure_tables();
    $stmt = db()->prepare("SELECT * FROM tower_runs WHERE brute_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1");
    $stmt->execute([$bruteId]);
    return $stmt->fetch() ?: null;
}

function tower_get_today_run(int $bruteId): ?array
{
    tower_ensure_tables();
    $stmt = db()->prepare("SELECT * FROM tower_runs WHERE brute_id = ? AND DATE(started_at) = CURDATE() ORDER BY id DESC LIMIT 1");
    $stmt->execute([$bruteId]);
    return $stmt->fetch() ?: null;
}

function tower_has_entered_today(int $bruteId): bool
{
    tower_ensure_tables();
    $stmt = db()->prepare("SELECT COUNT(*) FROM tower_runs WHERE brute_id = ? AND DATE(started_at) = CURDATE()");
    $stmt->execute([$bruteId]);
    return (int)$stmt->fetchColumn() > 0;
}

function tower_get_record(int $bruteId): ?array
{
    tower_ensure_tables();
    $stmt = db()->prepare("SELECT * FROM tower_records WHERE brute_id = ? LIMIT 1");
    $stmt->execute([$bruteId]);
    return $stmt->fetch() ?: null;
}

function tower_update_record(int $bruteId, int $floor): void
{
    tower_ensure_tables();
    db()->prepare("
        INSERT INTO tower_records (brute_id, best_floor, achieved_at)
        VALUES (?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            best_floor  = IF(VALUES(best_floor) > best_floor, VALUES(best_floor), best_floor),
            achieved_at = IF(VALUES(best_floor) > best_floor, NOW(), achieved_at)
    ")->execute([$bruteId, $floor]);
}

function tower_get_leaderboard(int $limit = 10): array
{
    tower_ensure_tables();
    $n = max(1, (int)$limit);
    return db()->query("
        SELECT tr.best_floor, tr.achieved_at, b.name AS brute_name, b.id AS brute_id, b.level AS brute_level
        FROM tower_records tr
        JOIN brutes b ON b.id = tr.brute_id
        WHERE tr.best_floor > 0
        ORDER BY tr.best_floor DESC
        LIMIT $n
    ")->fetchAll();
}

function tower_get_floor_reward(int $floor): ?array
{
    if ($floor <= 0) return null;
    if ($floor % 10 === 0) return TOWER_REWARD_BIG;
    if ($floor % 5 === 0)  return TOWER_REWARD_SMALL;
    return null;
}

function tower_build_floor_enemy(int $floor, array $brute, int $hpMaxRef, string $side): array
{
    $pdo = db();

    // HP scaling par palier
    if ($floor <= 10) {
        $hpPct = 65 + ($floor - 1) * 4;
    } elseif ($floor <= 25) {
        $hpPct = 101 + ($floor - 10) * 6;
    } elseif ($floor <= 50) {
        $hpPct = 191 + ($floor - 25) * 7;
    } else {
        $hpPct = 366 + ($floor - 50) * 10;
    }
    $hpMax = max(10, (int)round($hpMaxRef * $hpPct / 100));

    // Force
    if ($floor <= 10) {
        $strBonus = $floor;
    } elseif ($floor <= 25) {
        $strBonus = 10 + ($floor - 10) * 2;
    } elseif ($floor <= 50) {
        $strBonus = 40 + ($floor - 25) * 3;
    } else {
        $strBonus = 115 + ($floor - 50) * 5;
    }

    // Agilité
    if ($floor <= 10) {
        $agiBonus = (int)floor($floor / 2);
    } elseif ($floor <= 25) {
        $agiBonus = 5 + ($floor - 10);
    } elseif ($floor <= 50) {
        $agiBonus = 20 + ($floor - 25) * 2;
    } else {
        $agiBonus = 70 + ($floor - 50) * 3;
    }

    // Armure (plafonnée à 10)
    $armor = min(10, (int)floor(($floor - 1) / 5));

    // Nom
    if ($floor <= 10) {
        $pool = TOWER_ENEMY_NAMES['easy'];
    } elseif ($floor <= 25) {
        $pool = TOWER_ENEMY_NAMES['hard'];
    } elseif ($floor <= 50) {
        $pool = TOWER_ENEMY_NAMES['elite'];
    } else {
        $pool = TOWER_ENEMY_NAMES['boss'];
    }
    $name = $pool[$floor % count($pool)] . ' [É' . $floor . ']';

    // Arme selon l'étage
    if ($floor <= 5) {
        $wFilter = "('Dague', 'Epee')";
    } elseif ($floor <= 20) {
        $wFilter = "('Epee', 'Lance')";
    } else {
        $wFilter = "('Hache', 'Lance', 'Masse')";
    }
    $stmt    = $pdo->query("SELECT id FROM weapons WHERE name IN $wFilter ORDER BY RAND() LIMIT 1");
    $wRow    = $stmt->fetch();
    $weapons = [];
    if ($wRow) {
        $stmt2 = $pdo->prepare('SELECT * FROM weapons WHERE id = ? LIMIT 1');
        $stmt2->execute([(int)$wRow['id']]);
        if ($w = $stmt2->fetch()) {
            $w['upgrade_level'] = 0;
            $weapons[] = $w;
        }
    }

    // Compétence selon l'étage
    $skills      = [];
    $skillEffect = null;
    if ($floor >= 40) {
        $skillEffect = ['rage_pct', 'crit_bonus_pct', 'lifesteal_pct', 'armor_flat'][$floor % 4];
    } elseif ($floor >= 20) {
        $skillEffect = ['dmg_bonus_pct', 'armor_flat', 'crit_bonus_pct'][$floor % 3];
    } elseif ($floor >= 5) {
        $skillEffect = ($floor % 2 === 0) ? 'dmg_bonus_pct' : 'armor_flat';
    }
    if ($skillEffect) {
        $stmt = $pdo->prepare("SELECT * FROM skills WHERE effect_type = ? AND is_ultimate = 0 LIMIT 1");
        $stmt->execute([$skillEffect]);
        if ($s = $stmt->fetch()) $skills[] = $s;
    }

    // Tier de monstre par palier (skeleton → blackknight → demon → titan)
    if ($floor <= 10) $tier = 0;
    elseif ($floor <= 25) $tier = 1;
    elseif ($floor <= 50) $tier = 2;
    else $tier = 3;

    return [
        'id'              => -(2000 + $floor),
        'role'            => 'master',
        'side'            => $side,
        'slot'            => $side . '0',
        'name'            => $name,
        'level'           => max(1, (int)ceil($floor * 0.7)),
        'hp'              => $hpMax,
        'hp_max'          => $hpMax,
        'strength'        => (int)$brute['strength'] + $strBonus,
        'agility'         => (int)$brute['agility']  + $agiBonus,
        'endurance'       => (int)$brute['endurance'],
        'appearance'      => json_encode(['monster' => true, 'dungeon_code' => 'tower', 'room_idx' => $tier]),
        'weapons'         => $weapons,
        'skills'          => $skills,
        'armor_reduction' => $armor,
        'statuses'        => [],
        'ult_used'        => [],
    ];
}

function tower_run_floor_fight(int $bruteId, array $brute, int $floor, int $runId): array
{
    $pdo = db();

    // Calcul HP max réel (même logique que dungeon)
    $stmt = $pdo->prepare('
        SELECT COALESCE(SUM(a.hp_bonus), 0) AS bonus_hp
        FROM armors a
        JOIN brute_armors ba ON ba.armor_id = a.id
        WHERE ba.brute_id = ? AND ba.equipped = 1
    ');
    $stmt->execute([$bruteId]);
    $armorRow  = $stmt->fetch();
    $hpMaxRef  = (int)$brute['hp_max'] + ((int)$brute['endurance'] * 2) + (int)($armorRow['bonus_hp'] ?? 0);

    // HP du joueur reset à chaque étage
    $left  = build_team($bruteId, 'L');
    $enemy = tower_build_floor_enemy($floor, $brute, $hpMaxRef, 'R');
    $right = ['R0' => $enemy];

    $result = run_combat_loop(array_merge($left, $right));

    $pdo->prepare("
        INSERT INTO fights (brute1_id, brute2_id, winner_id, log_json, xp_gained, context)
        VALUES (?, ?, ?, ?, 0, 'tower')
    ")->execute([
        $bruteId,
        $bruteId,
        ($result['winner_id'] === $bruteId) ? $bruteId : null,
        json_encode($result['log'], JSON_UNESCAPED_UNICODE),
    ]);
    $fightId = (int)$pdo->lastInsertId();

    $pdo->prepare('UPDATE tower_runs SET fight_id = ?, updated_at = NOW() WHERE id = ?')
        ->execute([$fightId, $runId]);

    return ['ok' => true, 'fight_id' => $fightId];
}

function tower_enter(int $bruteId): array
{
    tower_ensure_tables();
    $pdo = db();

    if (tower_has_entered_today($bruteId)) {
        return ['ok' => false, 'error' => 'Tu as déjà tenté la Tour aujourd\'hui. Reviens demain !'];
    }

    if (tower_get_active_run($bruteId)) {
        return ['ok' => false, 'error' => 'Tu as déjà une ascension en cours.'];
    }

    $stmt = $pdo->prepare('SELECT * FROM brutes WHERE id = ? LIMIT 1');
    $stmt->execute([$bruteId]);
    $brute = $stmt->fetch();
    if (!$brute) return ['ok' => false, 'error' => 'Gladiateur introuvable.'];

    $pdo->prepare("INSERT INTO tower_runs (brute_id, current_floor, status) VALUES (?, 1, 'active')")
        ->execute([$bruteId]);
    $runId = (int)$pdo->lastInsertId();

    $fightResult = tower_run_floor_fight($bruteId, $brute, 1, $runId);
    if (!$fightResult['ok']) {
        $pdo->prepare('DELETE FROM tower_runs WHERE id = ?')->execute([$runId]);
        return ['ok' => false, 'error' => $fightResult['error'] ?? 'Erreur lors du combat.'];
    }

    return [
        'ok'       => true,
        'run_id'   => $runId,
        'fight_id' => $fightResult['fight_id'],
        'redirect' => 'fight.php?id=' . $fightResult['fight_id'] . '&run_id=' . $runId,
    ];
}

function tower_advance(int $bruteId, int $runId): array
{
    tower_ensure_tables();
    $pdo = db();

    $stmt = $pdo->prepare('SELECT * FROM tower_runs WHERE id = ? AND brute_id = ? LIMIT 1');
    $stmt->execute([$runId, $bruteId]);
    $run = $stmt->fetch();
    if (!$run) return ['ok' => false, 'error' => 'Ascension introuvable.'];
    if ((string)$run['status'] !== 'active') return ['ok' => false, 'error' => 'Cette ascension est terminée.'];

    $fightId = (int)$run['fight_id'];
    if ($fightId <= 0) return ['ok' => false, 'error' => 'Aucun combat en cours.'];

    $stmt = $pdo->prepare('SELECT winner_id FROM fights WHERE id = ? LIMIT 1');
    $stmt->execute([$fightId]);
    $fight = $stmt->fetch();
    if (!$fight) return ['ok' => false, 'error' => 'Combat introuvable.'];

    $playerWon    = ((int)$fight['winner_id'] === $bruteId);
    $currentFloor = (int)$run['current_floor'];

    if (!$playerWon) {
        $floorsCleared = $currentFloor - 1;
        tower_update_record($bruteId, $floorsCleared);
        $pdo->prepare("UPDATE tower_runs SET status = 'defeat', updated_at = NOW() WHERE id = ?")
            ->execute([$runId]);
        return ['ok' => true, 'outcome' => 'defeat', 'floors_cleared' => $floorsCleared, 'run_id' => $runId];
    }

    // Récompense palier si applicable
    $reward = tower_get_floor_reward($currentFloor);
    if ($reward) {
        tower_apply_reward($bruteId, $reward['xp'], $reward['gold']);
        $pdo->prepare('UPDATE tower_runs SET loot_xp = loot_xp + ?, loot_gold = loot_gold + ? WHERE id = ?')
            ->execute([$reward['xp'], $reward['gold'], $runId]);
    }

    // Mise à jour du record (étage battu)
    tower_update_record($bruteId, $currentFloor);

    // Passage à l'étage suivant
    $nextFloor = $currentFloor + 1;
    $pdo->prepare('UPDATE tower_runs SET current_floor = ?, updated_at = NOW() WHERE id = ?')
        ->execute([$nextFloor, $runId]);

    $stmt = $pdo->prepare('SELECT * FROM brutes WHERE id = ? LIMIT 1');
    $stmt->execute([$bruteId]);
    $brute = $stmt->fetch();

    $fightResult = tower_run_floor_fight($bruteId, $brute, $nextFloor, $runId);
    if (!$fightResult['ok']) {
        return ['ok' => false, 'error' => $fightResult['error'] ?? 'Erreur lors du combat.'];
    }

    return [
        'ok'            => true,
        'outcome'       => 'continue',
        'floor_cleared' => $currentFloor,
        'next_floor'    => $nextFloor,
        'reward'        => $reward,
        'run_id'        => $runId,
        'fight_id'      => $fightResult['fight_id'],
        'redirect'      => 'fight.php?id=' . $fightResult['fight_id'] . '&run_id=' . $runId,
    ];
}

function tower_apply_reward(int $bruteId, int $xp, int $gold): void
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT xp, level FROM brutes WHERE id = ? LIMIT 1');
    $stmt->execute([$bruteId]);
    $b = $stmt->fetch();
    if (!$b) return;

    $newXp    = (int)$b['xp'] + $xp;
    $newLevel = (int)$b['level'];
    $levelUps = 0;
    while ($newXp >= xp_for_level($newLevel + 1)) { $newLevel++; $levelUps++; }

    $pdo->prepare('UPDATE brutes SET xp = ?, level = ?, gold = gold + ? WHERE id = ?')
        ->execute([$newXp, $newLevel, $gold, $bruteId]);

    if ($levelUps > 0) auto_apply_levelup($pdo, $bruteId, $levelUps);
}
