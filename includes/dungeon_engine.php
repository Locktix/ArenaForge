<?php
// Système de donjons — séquences de 3-5 boss enchaînés
//
// Trois donjons de difficulté croissante, chacun avec un prérequis de niveau
// et un coût d'entrée en combats bonus. Les PV du joueur persistent entre
// les salles ; un boss plus fort attend à chaque palier.

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/brute_generator.php';
require_once __DIR__ . '/combat_engine.php';

// ============================================================
// Définitions
// ============================================================

const DUNGEON_DEFS = [
    'crypte' => [
        'name'        => 'Crypte des Damnés',
        'icon'        => '💀',
        'desc'        => 'Trois salles de ténèbres. Les morts-vivants attendent dans les couloirs du silence.',
        'difficulty'  => 'facile',
        'min_level'   => 1,
        'entry_cost'  => 0,   // gratuit : aucun combat bonus requis
        'rooms'       => [
            ['name' => 'Couloir des Ossements', 'hp_pct' => 90,  'str_bonus' => 1, 'agi_bonus' => 0, 'armor' => 0, 'skill' => null,            'xp' => 3, 'gold' => 6,  'frags' => 3],
            ['name' => 'Salle des Tortures',    'hp_pct' => 115, 'str_bonus' => 2, 'agi_bonus' => 1, 'armor' => 1, 'skill' => 'dmg_bonus_pct', 'xp' => 4, 'gold' => 10, 'frags' => 5],
            ['name' => 'Trône du Liche',        'hp_pct' => 140, 'str_bonus' => 3, 'agi_bonus' => 2, 'armor' => 2, 'skill' => 'crit_bonus_pct','xp' => 6, 'gold' => 15, 'frags' => 7],
        ],
        'final_bonus' => ['xp' => 8, 'gold' => 18, 'frags' => 8],
    ],
    'forteresse' => [
        'name'        => 'Forteresse Maudite',
        'icon'        => '🏰',
        'desc'        => 'Quatre salles gardées par des guerriers corrompus. Seuls les vétérans en sortent entiers.',
        'difficulty'  => 'intermediaire',
        'min_level'   => 5,
        'entry_cost'  => 50,  // coût en or
        'rooms'       => [
            ['name' => 'Portail de l\'Oubli',    'hp_pct' => 105, 'str_bonus' => 2, 'agi_bonus' => 1, 'armor' => 1, 'skill' => null,            'xp' => 4,  'gold' => 8,  'frags' => 4 ],
            ['name' => 'Forge du Damné',          'hp_pct' => 125, 'str_bonus' => 3, 'agi_bonus' => 2, 'armor' => 2, 'skill' => 'dmg_bonus_pct', 'xp' => 5,  'gold' => 12, 'frags' => 6 ],
            ['name' => 'Salle des Seigneurs',     'hp_pct' => 150, 'str_bonus' => 5, 'agi_bonus' => 2, 'armor' => 2, 'skill' => 'rage_pct',      'xp' => 7,  'gold' => 16, 'frags' => 8 ],
            ['name' => 'Chambre du Warlord',      'hp_pct' => 180, 'str_bonus' => 7, 'agi_bonus' => 3, 'armor' => 3, 'skill' => 'crit_bonus_pct','xp' => 9,  'gold' => 22, 'frags' => 10],
        ],
        'final_bonus' => ['xp' => 12, 'gold' => 30, 'frags' => 12],
    ],
    'abisse' => [
        'name'        => 'Abîsse Éternel',
        'icon'        => '🌑',
        'desc'        => 'Cinq salles aux portes de l\'Enfer. Personne ne les a toutes traversées deux fois.',
        'difficulty'  => 'difficile',
        'min_level'   => 10,
        'entry_cost'  => 100, // coût en or
        'rooms'       => [
            ['name' => 'Vestibule du Néant',     'hp_pct' => 115, 'str_bonus' => 3, 'agi_bonus' => 2, 'armor' => 2, 'skill' => null,            'xp' => 5,  'gold' => 10, 'frags' => 5 ],
            ['name' => 'Crypte des Anciens',     'hp_pct' => 140, 'str_bonus' => 4, 'agi_bonus' => 2, 'armor' => 2, 'skill' => 'dmg_bonus_pct', 'xp' => 7,  'gold' => 14, 'frags' => 7 ],
            ['name' => 'Marais des Âmes',        'hp_pct' => 165, 'str_bonus' => 5, 'agi_bonus' => 3, 'armor' => 3, 'skill' => 'dodge_pct',     'xp' => 9,  'gold' => 18, 'frags' => 9 ],
            ['name' => 'Palais des Démons',      'hp_pct' => 195, 'str_bonus' => 7, 'agi_bonus' => 4, 'armor' => 3, 'skill' => 'lifesteal_pct', 'xp' => 11, 'gold' => 24, 'frags' => 11],
            ['name' => 'Trône de l\'Abîsse',     'hp_pct' => 230, 'str_bonus' => 9, 'agi_bonus' => 5, 'armor' => 4, 'skill' => 'ult_revive_pct','xp' => 14, 'gold' => 32, 'frags' => 14],
        ],
        'final_bonus' => ['xp' => 18, 'gold' => 45, 'frags' => 18],
    ],
    'nexus' => [
        'name'        => 'Nexus des Anciens',
        'icon'        => '⚡',
        'desc'        => 'Six salles aux confins du monde. Les Titans primordiaux s\'y sont rendormis il y a des millénaires — ils ne tolèrent pas d\'être réveillés.',
        'difficulty'  => 'legendaire',
        'min_level'   => 20,
        'entry_cost'  => 250,
        'rooms'       => [
            ['name' => 'Portail des Origines',    'hp_pct' => 170, 'str_bonus' => 15, 'agi_bonus' => 5, 'armor' => 3, 'skill' => null,             'xp' => 8,  'gold' => 22, 'frags' => 10],
            ['name' => 'Arène des Gardiens',      'hp_pct' => 210, 'str_bonus' => 20, 'agi_bonus' => 6, 'armor' => 4, 'skill' => 'armor_flat',      'xp' => 11, 'gold' => 30, 'frags' => 14],
            ['name' => 'Crypte Runique',          'hp_pct' => 255, 'str_bonus' => 26, 'agi_bonus' => 7, 'armor' => 5, 'skill' => 'dmg_bonus_pct',   'xp' => 14, 'gold' => 38, 'frags' => 17],
            ['name' => 'Chambre des Anciens',     'hp_pct' => 305, 'str_bonus' => 32, 'agi_bonus' => 9, 'armor' => 5, 'skill' => 'rage_pct',        'xp' => 18, 'gold' => 48, 'frags' => 22],
            ['name' => 'Salle du Titan Éveillé',  'hp_pct' => 360, 'str_bonus' => 39, 'agi_bonus' => 11,'armor' => 6, 'skill' => 'crit_bonus_pct',  'xp' => 22, 'gold' => 60, 'frags' => 27],
            ['name' => 'Nexus Primordial',        'hp_pct' => 430, 'str_bonus' => 48, 'agi_bonus' => 14,'armor' => 7, 'skill' => 'ult_revive_pct',  'xp' => 28, 'gold' => 75, 'frags' => 34],
        ],
        'final_bonus' => ['xp' => 38, 'gold' => 95, 'frags' => 42],
    ],
];

const DUNGEON_BOSS_NAMES = [
    'crypte'     => ['Skeletor', 'Mort-Vivant', 'Spectre Osseux', 'Draugr Maudit', 'Liche Séculaire'],
    'forteresse' => ['Garde Corrompu', 'Chevalier Noir', 'Forgeron Damné', 'Seigneur de Guerre', 'Warlord Ténébreux'],
    'abisse'     => ['Démon Primaire', 'Archidémon', 'Seigneur des Abîsses', 'Être du Néant', 'Titan Infernal'],
    'nexus'      => ['Ancien Éveillé', 'Gardien Primordial', 'Colosse Runique', 'Titan Ancestral', 'Père des Titans', 'Nexus Vivant'],
];

// ============================================================
// Table auto-créée au premier appel
// ============================================================

function dungeon_ensure_table(): void
{
    static $done = false;
    if ($done) return;
    db()->exec("
        CREATE TABLE IF NOT EXISTS dungeon_runs (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            brute_id      INT NOT NULL,
            dungeon_code  VARCHAR(32) NOT NULL,
            current_room  INT NOT NULL DEFAULT 1,
            total_rooms   INT NOT NULL,
            status        ENUM('active','victory','defeat','abandoned') NOT NULL DEFAULT 'active',
            current_hp    INT NOT NULL,
            hp_max        INT NOT NULL,
            loot_xp       INT NOT NULL DEFAULT 0,
            loot_gold     INT NOT NULL DEFAULT 0,
            loot_frags    INT NOT NULL DEFAULT 0,
            fight_id      INT NULL,
            started_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_brute_status (brute_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $done = true;
}

// ============================================================
// Getters
// ============================================================

function dungeon_get_active_run(int $bruteId): ?array
{
    dungeon_ensure_table();
    $stmt = db()->prepare("SELECT * FROM dungeon_runs WHERE brute_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1");
    $stmt->execute([$bruteId]);
    return $stmt->fetch() ?: null;
}

function dungeon_get_run(int $runId): ?array
{
    dungeon_ensure_table();
    $stmt = db()->prepare('SELECT * FROM dungeon_runs WHERE id = ? LIMIT 1');
    $stmt->execute([$runId]);
    return $stmt->fetch() ?: null;
}

// ============================================================
// Construction du boss de salle
// ============================================================

/**
 * Génère un combattant boss pour la salle donnée.
 * Les stats sont calculées en pourcentage des stats du brute joueur
 * pour que la difficulté scale naturellement.
 */
function dungeon_build_room_boss(array $roomDef, array $brute, string $code, int $roomIdx, string $sidePrefix, int $hpMaxRef = 0): array
{
    $pdo = db();

    // $hpMaxRef est le hp_max réel du joueur (incluant endurance + armures).
    // Fallback sur brutes.hp_max si non fourni.
    $ref   = $hpMaxRef > 0 ? $hpMaxRef : (int)$brute['hp_max'];
    $hpMax = max(10, (int)round($ref * (int)$roomDef['hp_pct'] / 100));
    $strength  = (int)$brute['strength']  + (int)$roomDef['str_bonus'];
    $agility   = (int)$brute['agility']   + (int)$roomDef['agi_bonus'];
    $endurance = (int)$brute['endurance'];

    // Pioche déterministe du nom de boss selon room index
    $namePool = DUNGEON_BOSS_NAMES[$code] ?? DUNGEON_BOSS_NAMES['crypte'];
    $name     = $namePool[$roomIdx % count($namePool)];

    // Arme : nexus utilise des armes lourdes dès la salle 1
    if ($code === 'nexus') {
        $wFilter = $roomIdx === 0 ? "('Lance', 'Hache')" : "('Hache', 'Masse')";
    } elseif ($roomIdx === 0) {
        $wFilter = "('Dague', 'Epee')";
    } elseif ($roomIdx >= count(DUNGEON_DEFS[$code]['rooms']) - 1) {
        $wFilter = "('Hache', 'Lance', 'Masse')";
    } else {
        $wFilter = "('Epee', 'Lance', 'Hache')";
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

    // Skill optionnel selon la salle
    $skills = [];
    if (!empty($roomDef['skill'])) {
        $stmt = $pdo->prepare("SELECT * FROM skills WHERE effect_type = ? AND is_ultimate = 0 LIMIT 1");
        $stmt->execute([$roomDef['skill']]);
        if ($s = $stmt->fetch()) $skills[] = $s;
    }

    // 'monster' flag + dungeon_code : fight.php affiche un sprite monstre
    // au lieu du gladiateur générique.
    $appearance = json_encode([
        'monster'      => true,
        'dungeon_code' => $code,
        'room_idx'     => $roomIdx,
    ]);

    return [
        'id'             => -(1000 + $roomIdx),
        'role'           => 'master',
        'side'           => $sidePrefix,
        'slot'           => $sidePrefix . '0',
        'name'           => $name,
        'level'          => (int)$brute['level'] + $roomIdx,
        'hp'             => $hpMax,
        'hp_max'         => $hpMax,
        'strength'       => $strength,
        'agility'        => $agility,
        'endurance'      => $endurance,
        'appearance'     => $appearance,
        'weapons'        => $weapons,
        'skills'         => $skills,
        'armor_reduction'=> (int)$roomDef['armor'],
        'statuses'       => [],
        'ult_used'       => [],
    ];
}

// ============================================================
// Démarrage d'une run
// ============================================================

function dungeon_start(int $bruteId, string $code): array
{
    dungeon_ensure_table();
    $pdo = db();

    $def = DUNGEON_DEFS[$code] ?? null;
    if (!$def) return ['ok' => false, 'error' => 'Donjon inconnu.'];

    // Chargement de la brute
    $stmt = $pdo->prepare('SELECT * FROM brutes WHERE id = ? LIMIT 1');
    $stmt->execute([$bruteId]);
    $brute = $stmt->fetch();
    if (!$brute) return ['ok' => false, 'error' => 'Gladiateur introuvable.'];

    if ((int)$brute['level'] < (int)$def['min_level']) {
        return ['ok' => false, 'error' => sprintf('Niveau %d requis pour ce donjon.', $def['min_level'])];
    }

    // Pas de run active
    $existing = dungeon_get_active_run($bruteId);
    if ($existing) {
        return ['ok' => false, 'error' => 'Tu as déjà une expédition en cours.'];
    }

    // 1 run par donjon par jour (abandon et défaite consomment le slot)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM dungeon_runs
        WHERE brute_id = ? AND dungeon_code = ? AND DATE(started_at) = CURDATE()
    ");
    $stmt->execute([$bruteId, $code]);
    if ((int)$stmt->fetchColumn() > 0) {
        return ['ok' => false, 'error' => 'Tu as déjà tenté ce donjon aujourd\'hui. Reviens demain.'];
    }

    // Coût d'entrée (en or)
    $cost = (int)$def['entry_cost'];
    if ($cost > 0) {
        if ((int)$brute['gold'] < $cost) {
            return ['ok' => false, 'error' => sprintf('Il te faut %d or pour entrer ici (tu en as %d).', $cost, (int)$brute['gold'])];
        }
        $pdo->prepare('UPDATE brutes SET gold = gold - ? WHERE id = ?')
            ->execute([$cost, $bruteId]);
    }

    // Calcul des PV max du joueur (idem load_fighter)
    $stmt = $pdo->prepare('
        SELECT COALESCE(SUM(a.hp_bonus), 0) AS bonus_hp,
               COALESCE(SUM(a.damage_reduction), 0) AS reduction
        FROM armors a
        JOIN brute_armors ba ON ba.armor_id = a.id
        WHERE ba.brute_id = ? AND ba.equipped = 1
    ');
    $stmt->execute([$bruteId]);
    $armorRow = $stmt->fetch();
    $hpMax = (int)$brute['hp_max'] + ((int)$brute['endurance'] * 2) + (int)($armorRow['bonus_hp'] ?? 0);

    // Création de la run
    $totalRooms = count($def['rooms']);
    $pdo->prepare("
        INSERT INTO dungeon_runs
          (brute_id, dungeon_code, current_room, total_rooms, status, current_hp, hp_max)
        VALUES (?, ?, 1, ?, 'active', ?, ?)
    ")->execute([$bruteId, $code, $totalRooms, $hpMax, $hpMax]);
    $runId = (int)$pdo->lastInsertId();

    // Premier combat
    $fightResult = dungeon_run_room_fight($bruteId, $brute, $hpMax, $code, 0, $runId);
    if (!$fightResult['ok']) {
        // Rollback de la run si le combat échoue
        $pdo->prepare('DELETE FROM dungeon_runs WHERE id = ?')->execute([$runId]);
        if ($cost > 0) {
            $pdo->prepare('UPDATE brutes SET gold = gold + ? WHERE id = ?')
                ->execute([$cost, $bruteId]);
        }
        return ['ok' => false, 'error' => $fightResult['error']];
    }

    return [
        'ok'       => true,
        'run_id'   => $runId,
        'fight_id' => $fightResult['fight_id'],
        'redirect' => 'fight.php?id=' . $fightResult['fight_id'],
    ];
}

// ============================================================
// Avancement dans la run (appelé après chaque salle gagnée)
// ============================================================

function dungeon_advance(int $bruteId, int $runId): array
{
    dungeon_ensure_table();
    $pdo = db();

    $run = dungeon_get_run($runId);
    if (!$run || (int)$run['brute_id'] !== $bruteId) {
        return ['ok' => false, 'error' => 'Expédition introuvable.'];
    }
    if ((string)$run['status'] !== 'active') {
        return ['ok' => false, 'error' => 'Cette expédition est terminée.'];
    }

    $code    = (string)$run['dungeon_code'];
    $def     = DUNGEON_DEFS[$code] ?? null;
    if (!$def) return ['ok' => false, 'error' => 'Donjon inconnu.'];

    $roomIdx = (int)$run['current_room'] - 1; // 0-based

    // Lire le résultat du dernier combat
    $fightId = (int)$run['fight_id'];
    if ($fightId <= 0) return ['ok' => false, 'error' => 'Aucun combat en cours.'];

    $stmt = $pdo->prepare('SELECT winner_id, log_json FROM fights WHERE id = ? LIMIT 1');
    $stmt->execute([$fightId]);
    $fight = $stmt->fetch();
    if (!$fight) return ['ok' => false, 'error' => 'Combat introuvable.'];

    $playerWon = ((int)$fight['winner_id'] === $bruteId);

    if (!$playerWon) {
        // Défaite — clôture la run
        $pdo->prepare("UPDATE dungeon_runs SET status = 'defeat', updated_at = NOW() WHERE id = ?")
            ->execute([$runId]);
        return ['ok' => true, 'outcome' => 'defeat', 'run_id' => $runId];
    }

    // Extraire les PV restants du vainqueur depuis l'event 'end'
    $log       = json_decode((string)$fight['log_json'], true) ?: [];
    $winnerHp  = (int)$run['current_hp']; // fallback
    foreach (array_reverse($log) as $ev) {
        if (($ev['event'] ?? '') === 'end' && isset($ev['winner_hp'])) {
            $winnerHp = max(1, (int)$ev['winner_hp']);
            break;
        }
    }

    // Appliquer le loot de la salle complétée
    $roomDef  = $def['rooms'][$roomIdx];
    $xp       = (int)$roomDef['xp'];
    $gold     = (int)$roomDef['gold'];
    $frags    = (int)$roomDef['frags'];

    dungeon_apply_loot($bruteId, $xp, $gold, $frags);

    // Mettre à jour le run (loot cumulé, HP persistés)
    $pdo->prepare("
        UPDATE dungeon_runs
        SET loot_xp    = loot_xp + ?,
            loot_gold  = loot_gold + ?,
            loot_frags = loot_frags + ?,
            current_hp = ?,
            updated_at = NOW()
        WHERE id = ?
    ")->execute([$xp, $gold, $frags, $winnerHp, $runId]);

    $nextRoomIdx = $roomIdx + 1;
    $totalRooms  = (int)$run['total_rooms'];

    // Victoire totale si c'était la dernière salle
    if ($nextRoomIdx >= $totalRooms) {
        $finalBonus = $def['final_bonus'];
        dungeon_apply_loot($bruteId, (int)$finalBonus['xp'], (int)$finalBonus['gold'], (int)$finalBonus['frags']);
        $pdo->prepare("
            UPDATE dungeon_runs
            SET status     = 'victory',
                loot_xp    = loot_xp + ?,
                loot_gold  = loot_gold + ?,
                loot_frags = loot_frags + ?,
                updated_at = NOW()
            WHERE id = ?
        ")->execute([(int)$finalBonus['xp'], (int)$finalBonus['gold'], (int)$finalBonus['frags'], $runId]);

        return ['ok' => true, 'outcome' => 'victory', 'run_id' => $runId];
    }

    // Salle suivante
    $pdo->prepare("UPDATE dungeon_runs SET current_room = ? WHERE id = ?")
        ->execute([$nextRoomIdx + 1, $runId]);

    $stmt = $pdo->prepare('SELECT * FROM brutes WHERE id = ? LIMIT 1');
    $stmt->execute([$bruteId]);
    $brute = $stmt->fetch();

    $fightResult = dungeon_run_room_fight($bruteId, $brute, $winnerHp, $code, $nextRoomIdx, $runId);
    if (!$fightResult['ok']) {
        return ['ok' => false, 'error' => $fightResult['error']];
    }

    return [
        'ok'       => true,
        'outcome'  => 'continue',
        'run_id'   => $runId,
        'fight_id' => $fightResult['fight_id'],
        'redirect' => 'fight.php?id=' . $fightResult['fight_id'],
    ];
}

// ============================================================
// Abandon
// ============================================================

function dungeon_abandon(int $bruteId, int $runId): array
{
    dungeon_ensure_table();
    $pdo = db();

    $run = dungeon_get_run($runId);
    if (!$run || (int)$run['brute_id'] !== $bruteId) {
        return ['ok' => false, 'error' => 'Expédition introuvable.'];
    }
    if ((string)$run['status'] !== 'active') {
        return ['ok' => false, 'error' => 'Cette expédition est déjà terminée.'];
    }

    // Si le dernier combat est une défaite du joueur, classer comme 'defeat' et non 'abandoned'
    $finalStatus = 'abandoned';
    if ((int)$run['fight_id'] > 0) {
        $stmt = $pdo->prepare('SELECT winner_id FROM fights WHERE id = ? LIMIT 1');
        $stmt->execute([(int)$run['fight_id']]);
        $fight = $stmt->fetch();
        if ($fight && (int)$fight['winner_id'] !== $bruteId) {
            $finalStatus = 'defeat';
        }
    }

    $pdo->prepare("UPDATE dungeon_runs SET status = ?, updated_at = NOW() WHERE id = ?")
        ->execute([$finalStatus, $runId]);

    return ['ok' => true];
}

// ============================================================
// Internes
// ============================================================

function dungeon_run_room_fight(int $bruteId, array $brute, int $currentHp, string $code, int $roomIdx, int $runId): array
{
    $pdo = db();
    $def  = DUNGEON_DEFS[$code];
    $room = $def['rooms'][$roomIdx];

    // Récupère le hp_max réel (stocké lors de la création de la run) pour scaler le boss
    $stmt = $pdo->prepare('SELECT hp_max FROM dungeon_runs WHERE id = ? LIMIT 1');
    $stmt->execute([$runId]);
    $runHpMax = (int)($stmt->fetchColumn() ?: $brute['hp_max']);

    // Équipe joueur avec PV persistés
    $left  = build_team_with_hp($bruteId, 'L', $currentHp);
    $boss  = dungeon_build_room_boss($room, $brute, $code, $roomIdx, 'R', $runHpMax);
    $right = ['R0' => $boss];

    $result = run_combat_loop(array_merge($left, $right));

    $pdo->prepare("
        INSERT INTO fights (brute1_id, brute2_id, winner_id, log_json, xp_gained, context)
        VALUES (?, ?, ?, ?, 0, 'dungeon')
    ")->execute([
        $bruteId,
        $bruteId,
        ($result['winner_id'] === $bruteId) ? $bruteId : null,
        json_encode($result['log'], JSON_UNESCAPED_UNICODE),
    ]);
    $fightId = (int)$pdo->lastInsertId();

    $pdo->prepare('UPDATE dungeon_runs SET fight_id = ?, updated_at = NOW() WHERE id = ?')
        ->execute([$fightId, $runId]);

    return ['ok' => true, 'fight_id' => $fightId];
}

function dungeon_apply_loot(int $bruteId, int $xp, int $gold, int $frags): void
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

    $pdo->prepare('
        UPDATE brutes
        SET xp = ?, level = ?,
            gold      = gold + ?,
            fragments = fragments + ?
        WHERE id = ?
    ')->execute([$newXp, $newLevel, $gold, $frags, $bruteId]);
    if ($levelUps > 0) auto_apply_levelup($pdo, $bruteId, $levelUps);
}
