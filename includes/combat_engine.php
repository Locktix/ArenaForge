<?php
// Moteur de combat automatisé (maîtres + compagnons animaux)

declare(strict_types=1);

require_once __DIR__ . '/db.php';

// ============================================================
// Chargement des combattants
// ============================================================

function load_fighter(int $bruteId): array
{
    $pdo = db();

    $stmt = $pdo->prepare('SELECT * FROM brutes WHERE id = ? LIMIT 1');
    $stmt->execute([$bruteId]);
    $brute = $stmt->fetch();
    if (!$brute) {
        throw new RuntimeException("Gladiateur $bruteId introuvable.");
    }

    $stmt = $pdo->prepare('
        SELECT w.* FROM weapons w
        JOIN brute_weapons bw ON bw.weapon_id = w.id
        WHERE bw.brute_id = ?
    ');
    $stmt->execute([$bruteId]);
    $weapons = $stmt->fetchAll();

    $stmt = $pdo->prepare('
        SELECT s.* FROM skills s
        JOIN brute_skills bs ON bs.skill_id = s.id
        WHERE bs.brute_id = ?
    ');
    $stmt->execute([$bruteId]);
    $skills = $stmt->fetchAll();

    return [
        'id'         => (int)$brute['id'],
        'role'       => 'master',
        'name'       => $brute['name'],
        'level'      => (int)$brute['level'],
        'hp_max'     => (int)$brute['hp_max'],
        'hp'         => (int)$brute['hp_max'],
        'strength'   => (int)$brute['strength'],
        'agility'    => (int)$brute['agility'],
        'endurance'  => (int)$brute['endurance'],
        'appearance' => $brute['appearance_seed'],
        'weapons'    => $weapons,
        'skills'     => $skills,
    ];
}

function load_pets_for_brute(int $bruteId): array
{
    $stmt = db()->prepare('
        SELECT p.* FROM pets p
        JOIN brute_pets bp ON bp.pet_id = p.id
        WHERE bp.brute_id = ?
        ORDER BY bp.acquired_at ASC
        LIMIT 2
    ');
    $stmt->execute([$bruteId]);
    return $stmt->fetchAll();
}

function build_team(int $bruteId, string $sidePrefix): array
{
    $master = load_fighter($bruteId);
    $master['side'] = $sidePrefix;
    $master['slot'] = $sidePrefix . '0';

    $combatants = [$master['slot'] => $master];

    $pets = load_pets_for_brute($bruteId);
    foreach ($pets as $i => $p) {
        $slot = $sidePrefix . ($i + 1);
        $combatants[$slot] = [
            'id'         => (int)$p['id'],
            'role'       => 'pet',
            'side'       => $sidePrefix,
            'slot'       => $slot,
            'name'       => $p['name'],
            'species'    => $p['species'],
            'hp_max'     => (int)$p['hp_max'],
            'hp'         => (int)$p['hp_max'],
            'strength'   => 0,
            'agility'    => (int)$p['agility'],
            'damage_min' => (int)$p['damage_min'],
            'damage_max' => (int)$p['damage_max'],
            'icon_path'  => $p['icon_path'],
            'skills'     => [],
            'weapons'    => [],
        ];
    }

    return $combatants;
}

// ============================================================
// Helpers
// ============================================================

function pick_weapon(array $fighter): array
{
    if (empty($fighter['weapons'])) {
        return [
            'name'        => 'Poings nus',
            'damage_min'  => 2,
            'damage_max'  => 4,
            'speed'       => 3,
            'crit_chance' => 5,
        ];
    }
    // Choix pondéré : on préfère la meilleure arme (hors bouclier)
    $combat = array_values(array_filter($fighter['weapons'], fn($w) => strtolower($w['name']) !== 'bouclier'));
    if (empty($combat)) {
        return $fighter['weapons'][0];
    }
    usort($combat, fn($a, $b) => ($b['damage_max'] + $b['damage_min']) <=> ($a['damage_max'] + $a['damage_min']));
    return $combat[0];
}

function has_skill(array $fighter, string $effectType): ?array
{
    if (empty($fighter['skills'])) {
        return null;
    }
    foreach ($fighter['skills'] as $s) {
        if ($s['effect_type'] === $effectType) {
            return $s;
        }
    }
    return null;
}

function roll(int $min, int $max): int
{
    return random_int($min, $max);
}

function fighter_public(array $f): array
{
    if (($f['role'] ?? 'master') === 'master') {
        return [
            'slot'       => $f['slot'] ?? null,
            'role'       => 'master',
            'id'         => $f['id'] ?? null,
            'name'       => $f['name'],
            'level'      => $f['level'] ?? 1,
            'hp'         => $f['hp'],
            'hp_max'     => $f['hp_max'],
            'appearance' => $f['appearance'] ?? null,
            'weapon'     => pick_weapon($f)['name'] ?? 'Poings nus',
            'skills'     => array_map(fn($s) => $s['name'], $f['skills'] ?? []),
        ];
    }
    return [
        'slot'      => $f['slot'] ?? null,
        'role'      => 'pet',
        'name'      => $f['name'],
        'species'   => $f['species'] ?? '',
        'hp'        => $f['hp'],
        'hp_max'    => $f['hp_max'],
        'icon_path' => $f['icon_path'] ?? '',
    ];
}

function team_public(array $combatants, string $side): array
{
    $team = ['master' => null, 'pets' => []];
    foreach ($combatants as $c) {
        if ($c['side'] !== $side) {
            continue;
        }
        if ($c['role'] === 'master') {
            $team['master'] = fighter_public($c);
        } else {
            $team['pets'][] = fighter_public($c);
        }
    }
    return $team;
}

// ============================================================
// Boucle principale
// ============================================================

function run_fight(int $b1Id, int $b2Id): array
{
    $combatants = array_merge(
        build_team($b1Id, 'L'),
        build_team($b2Id, 'R')
    );

    $log = [];

    $log[] = [
        'event'    => 'start',
        'teams'    => [
            'L' => team_public($combatants, 'L'),
            'R' => team_public($combatants, 'R'),
        ],
        // Backward-compat : champ utilisé par les anciens replays (non requis par fight.js)
        'fighters' => [
            fighter_public($combatants['L0']),
            fighter_public($combatants['R0']),
        ],
    ];

    $round = 1;
    $maxRounds = 60;

    while ($round <= $maxRounds && $combatants['L0']['hp'] > 0 && $combatants['R0']['hp'] > 0) {
        // Initiative recalculée chaque round
        $order = [];
        foreach ($combatants as $slot => $c) {
            if ($c['hp'] <= 0) {
                continue;
            }
            $order[$slot] = $c['agility'] + roll(1, 6);
        }
        arsort($order);

        foreach (array_keys($order) as $slot) {
            if ($combatants['L0']['hp'] <= 0 || $combatants['R0']['hp'] <= 0) {
                break;
            }
            if ($combatants[$slot]['hp'] <= 0) {
                continue;
            }

            // Cible : ennemi vivant aléatoire, priorité 60% au maître adverse
            $attSide = $combatants[$slot]['side'];
            $enemyMasterSlot = $attSide === 'L' ? 'R0' : 'L0';

            $enemies = [];
            foreach ($combatants as $s2 => $c2) {
                if ($c2['side'] !== $attSide && $c2['hp'] > 0) {
                    $enemies[] = $s2;
                }
            }
            if (empty($enemies)) {
                break;
            }

            if (count($enemies) === 1 || roll(1, 100) <= 60) {
                $defSlot = $enemyMasterSlot;
            } else {
                $petEnemies = array_values(array_filter($enemies, fn($s) => $s !== $enemyMasterSlot));
                $defSlot    = $petEnemies ? $petEnemies[array_rand($petEnemies)] : $enemyMasterSlot;
            }

            resolve_attack($combatants[$slot], $combatants[$defSlot], $round, $log);

            // Régénération en fin d'action (maîtres seulement)
            if ($combatants[$slot]['role'] === 'master' && $combatants[$slot]['hp'] > 0) {
                if ($regen = has_skill($combatants[$slot], 'regen_flat')) {
                    if ($combatants[$slot]['hp'] < $combatants[$slot]['hp_max']) {
                        $heal = min((int)$regen['effect_value'], $combatants[$slot]['hp_max'] - $combatants[$slot]['hp']);
                        $combatants[$slot]['hp'] += $heal;
                        $log[] = [
                            'turn'       => $round,
                            'event'      => 'regen',
                            'actor'      => $combatants[$slot]['name'],
                            'actor_slot' => $combatants[$slot]['slot'],
                            'heal'       => $heal,
                            'actor_hp'   => $combatants[$slot]['hp'],
                        ];
                    }
                }
            }
        }
        $round++;
    }

    // Détermination du vainqueur (maître debout)
    if ($combatants['L0']['hp'] > 0 && $combatants['R0']['hp'] > 0) {
        $log[] = ['event' => 'timeout'];
        $rL = $combatants['L0']['hp'] / max(1, $combatants['L0']['hp_max']);
        $rR = $combatants['R0']['hp'] / max(1, $combatants['R0']['hp_max']);
        $winner = $rL >= $rR ? $combatants['L0'] : $combatants['R0'];
    } else {
        $winner = $combatants['L0']['hp'] > 0 ? $combatants['L0'] : $combatants['R0'];
    }

    $log[] = [
        'event'     => 'end',
        'winner_id' => $winner['id'],
        'winner'    => $winner['name'],
    ];

    return [
        'winner_id' => (int)$winner['id'],
        'log'       => $log,
    ];
}

// ============================================================
// Résolution d'une attaque (avec esquive, contre, crit, armure)
// ============================================================

function resolve_attack(array &$att, array &$def, int $turn, array &$log): void
{
    $weapon = $att['role'] === 'master' ? pick_weapon($att) : null;

    // Chance d'esquive
    $baseDodge = 5 + max(0, $def['agility'] - $att['agility']) * 3;
    if ($def['role'] === 'master') {
        if ($dodgeSkill = has_skill($def, 'dodge_pct')) {
            $baseDodge += (int)$dodgeSkill['effect_value'];
        }
    }
    $baseDodge = max(0, min(75, $baseDodge));

    if (roll(1, 100) <= $baseDodge) {
        $log[] = [
            'turn'          => $turn,
            'event'         => 'dodge',
            'attacker'      => $att['name'],
            'attacker_slot' => $att['slot'],
            'defender'      => $def['name'],
            'defender_slot' => $def['slot'],
            'weapon'        => $weapon['name'] ?? ($att['species'] ?? 'griffes'),
            'att_hp'        => $att['hp'],
            'def_hp'        => $def['hp'],
        ];

        // Contre-attaque (maître défenseur uniquement)
        if ($def['role'] === 'master') {
            if ($counter = has_skill($def, 'counter_pct')) {
                if (roll(1, 100) <= (int)$counter['effect_value']) {
                    $log[] = [
                        'turn'          => $turn,
                        'event'         => 'counter',
                        'attacker'      => $def['name'],
                        'attacker_slot' => $def['slot'],
                        'defender'      => $att['name'],
                        'defender_slot' => $att['slot'],
                    ];
                    resolve_raw_hit($def, $att, $turn, $log);
                }
            }
        }
        return;
    }

    resolve_raw_hit($att, $def, $turn, $log, $weapon);
}

function resolve_raw_hit(array &$att, array &$def, int $turn, array &$log, ?array $weapon = null): void
{
    // Dégâts de base
    if ($att['role'] === 'master') {
        $weapon     = $weapon ?? pick_weapon($att);
        $damage     = roll((int)$weapon['damage_min'], (int)$weapon['damage_max']);
        $damage    += (int)floor($att['strength'] / 2);
        $weaponName = $weapon['name'];
        $critChance = (int)$weapon['crit_chance'];

        if ($s = has_skill($att, 'dmg_bonus_pct')) {
            $damage = (int)floor($damage * (1 + $s['effect_value'] / 100));
        }
        if (($s = has_skill($att, 'rage_pct')) && $att['hp'] / max(1, $att['hp_max']) <= 0.3) {
            $damage = (int)floor($damage * (1 + $s['effect_value'] / 100));
        }
        if ($s = has_skill($att, 'crit_bonus_pct')) {
            $critChance += (int)$s['effect_value'];
        }
    } else {
        // Attaque animale : pas d'arme ni de skill
        $damage     = roll((int)$att['damage_min'], (int)$att['damage_max']);
        $weaponName = $att['species'] ?? 'griffes';
        $critChance = 5;
    }

    $isCrit = roll(1, 100) <= $critChance;
    if ($isCrit) {
        $damage = (int)floor($damage * 1.8);
    }

    // Armure / bouclier (maître défenseur uniquement)
    if ($def['role'] === 'master') {
        if ($s = has_skill($def, 'armor_flat')) {
            $damage = max(1, $damage - (int)$s['effect_value']);
        }
        foreach ($def['weapons'] as $w) {
            if (strtolower($w['name']) === 'bouclier') {
                $damage = max(1, $damage - 2);
                break;
            }
        }
    }

    $damage = max(1, $damage);
    $def['hp'] = max(0, $def['hp'] - $damage);

    $log[] = [
        'turn'          => $turn,
        'event'         => 'hit',
        'attacker'      => $att['name'],
        'attacker_slot' => $att['slot'],
        'defender'      => $def['name'],
        'defender_slot' => $def['slot'],
        'weapon'        => $weaponName,
        'damage'        => $damage,
        'crit'          => $isCrit,
        'att_hp'        => $att['hp'],
        'def_hp'        => $def['hp'],
    ];

    // Mise hors de combat d'un pet (le combat continue)
    if ($def['hp'] <= 0 && $def['role'] === 'pet') {
        $log[] = [
            'turn'       => $turn,
            'event'      => 'down',
            'actor'      => $def['name'],
            'actor_slot' => $def['slot'],
        ];
    }

    // Vol de vie (maître attaquant uniquement)
    if ($att['role'] === 'master') {
        if ($s = has_skill($att, 'lifesteal_pct')) {
            $heal = (int)floor($damage * $s['effect_value'] / 100);
            if ($heal > 0 && $att['hp'] < $att['hp_max']) {
                $heal = min($heal, $att['hp_max'] - $att['hp']);
                $att['hp'] += $heal;
                $log[] = [
                    'turn'       => $turn,
                    'event'      => 'lifesteal',
                    'actor'      => $att['name'],
                    'actor_slot' => $att['slot'],
                    'heal'       => $heal,
                    'actor_hp'   => $att['hp'],
                ];
            }
        }
    }
}

// ============================================================
// Sélection d'adversaire
// ============================================================

function find_opponent(int $bruteId, int $level): ?array
{
    $pdo = db();
    // Même niveau ± 2, hors propriétaire, choix aléatoire
    $stmt = $pdo->prepare('
        SELECT b.* FROM brutes b
        WHERE b.id != ?
          AND b.level BETWEEN ? AND ?
          AND b.user_id != (SELECT user_id FROM brutes WHERE id = ?)
        ORDER BY RAND()
        LIMIT 1
    ');
    $stmt->execute([$bruteId, max(1, $level - 2), $level + 2, $bruteId]);
    $opp = $stmt->fetch();
    if ($opp) {
        return $opp;
    }

    // Elargir si rien trouvé
    $stmt = $pdo->prepare('
        SELECT b.* FROM brutes b
        WHERE b.id != ?
          AND b.user_id != (SELECT user_id FROM brutes WHERE id = ?)
        ORDER BY ABS(b.level - ?) ASC, RAND()
        LIMIT 1
    ');
    $stmt->execute([$bruteId, $bruteId, $level]);
    return $stmt->fetch() ?: null;
}
