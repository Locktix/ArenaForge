<?php
// Moteur de combat automatisé

declare(strict_types=1);

require_once __DIR__ . '/db.php';

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

function run_fight(int $b1Id, int $b2Id): array
{
    $f1 = load_fighter($b1Id);
    $f2 = load_fighter($b2Id);

    $log = [];

    $log[] = [
        'event'    => 'start',
        'fighters' => [fighter_public($f1), fighter_public($f2)],
    ];

    // Ordre de frappe basé sur agilité (+ aléatoire)
    $order = ($f1['agility'] + roll(1, 6)) >= ($f2['agility'] + roll(1, 6))
        ? [[&$f1, &$f2], [&$f2, &$f1]]
        : [[&$f2, &$f1], [&$f1, &$f2]];

    $turn = 1;
    $maxTurns = 60;
    $winner = null;

    while ($turn <= $maxTurns && $f1['hp'] > 0 && $f2['hp'] > 0) {
        foreach ($order as [$att, $def]) {
            if ($att['hp'] <= 0 || $def['hp'] <= 0) {
                continue;
            }

            resolve_attack($att, $def, $turn, $log);

            if ($def['hp'] <= 0) {
                $winner = $att;
                break;
            }

            // Régénération en fin de tour du camp attaquant
            if ($regen = has_skill($att, 'regen_flat')) {
                if ($att['hp'] < $att['hp_max']) {
                    $heal = min((int)$regen['effect_value'], $att['hp_max'] - $att['hp']);
                    $att['hp'] += $heal;
                    $log[] = [
                        'turn'      => $turn,
                        'event'     => 'regen',
                        'actor'     => $att['name'],
                        'heal'      => $heal,
                        'actor_hp'  => $att['hp'],
                    ];
                }
            }
        }
        $turn++;
    }

    if ($winner === null) {
        // Egalité → victoire au PV restants (pourcentage)
        $r1 = $f1['hp'] / max(1, $f1['hp_max']);
        $r2 = $f2['hp'] / max(1, $f2['hp_max']);
        $winner = $r1 >= $r2 ? $f1 : $f2;
        $log[] = ['event' => 'timeout'];
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

function fighter_public(array $f): array
{
    return [
        'id'         => $f['id'],
        'name'       => $f['name'],
        'level'      => $f['level'],
        'hp'         => $f['hp'],
        'hp_max'     => $f['hp_max'],
        'appearance' => $f['appearance'],
        'weapon'     => pick_weapon($f)['name'] ?? 'Poings nus',
        'skills'     => array_map(fn($s) => $s['name'], $f['skills']),
    ];
}

function resolve_attack(array &$att, array &$def, int $turn, array &$log): void
{
    $weapon = pick_weapon($att);

    // Chance d'esquive basée sur différence d'agilité
    $baseDodge = 5 + max(0, $def['agility'] - $att['agility']) * 3;
    if ($dodgeSkill = has_skill($def, 'dodge_pct')) {
        $baseDodge += (int)$dodgeSkill['effect_value'];
    }
    $baseDodge = max(0, min(75, $baseDodge));

    if (roll(1, 100) <= $baseDodge) {
        $log[] = [
            'turn'      => $turn,
            'event'     => 'dodge',
            'attacker'  => $att['name'],
            'defender'  => $def['name'],
            'weapon'    => $weapon['name'],
            'att_hp'    => $att['hp'],
            'def_hp'    => $def['hp'],
        ];

        // Contre-attaque éventuelle
        if ($counter = has_skill($def, 'counter_pct')) {
            if (roll(1, 100) <= (int)$counter['effect_value']) {
                $log[] = [
                    'turn'     => $turn,
                    'event'    => 'counter',
                    'attacker' => $def['name'],
                    'defender' => $att['name'],
                ];
                resolve_raw_hit($def, $att, $turn, $log);
            }
        }
        return;
    }

    resolve_raw_hit($att, $def, $turn, $log, $weapon);
}

function resolve_raw_hit(array &$att, array &$def, int $turn, array &$log, ?array $weapon = null): void
{
    $weapon = $weapon ?? pick_weapon($att);

    $damage = roll((int)$weapon['damage_min'], (int)$weapon['damage_max']);
    $damage += (int)floor($att['strength'] / 2);

    // Force brute
    if ($s = has_skill($att, 'dmg_bonus_pct')) {
        $damage = (int)floor($damage * (1 + $s['effect_value'] / 100));
    }

    // Rage (< 30% PV)
    if (($s = has_skill($att, 'rage_pct')) && $att['hp'] / max(1, $att['hp_max']) <= 0.3) {
        $damage = (int)floor($damage * (1 + $s['effect_value'] / 100));
    }

    // Crit
    $critChance = (int)$weapon['crit_chance'];
    if ($s = has_skill($att, 'crit_bonus_pct')) {
        $critChance += (int)$s['effect_value'];
    }
    $isCrit = roll(1, 100) <= $critChance;
    if ($isCrit) {
        $damage = (int)floor($damage * 1.8);
    }

    // Armure
    if ($s = has_skill($def, 'armor_flat')) {
        $damage = max(1, $damage - (int)$s['effect_value']);
    }
    // Bouclier équipé : absorption mineure
    foreach ($def['weapons'] as $w) {
        if (strtolower($w['name']) === 'bouclier') {
            $damage = max(1, $damage - 2);
            break;
        }
    }

    $damage = max(1, $damage);
    $def['hp'] = max(0, $def['hp'] - $damage);

    $log[] = [
        'turn'     => $turn,
        'event'    => 'hit',
        'attacker' => $att['name'],
        'defender' => $def['name'],
        'weapon'   => $weapon['name'],
        'damage'   => $damage,
        'crit'     => $isCrit,
        'att_hp'   => $att['hp'],
        'def_hp'   => $def['hp'],
    ];

    // Vol de vie
    if ($s = has_skill($att, 'lifesteal_pct')) {
        $heal = (int)floor($damage * $s['effect_value'] / 100);
        if ($heal > 0 && $att['hp'] < $att['hp_max']) {
            $heal = min($heal, $att['hp_max'] - $att['hp']);
            $att['hp'] += $heal;
            $log[] = [
                'turn'     => $turn,
                'event'    => 'lifesteal',
                'actor'    => $att['name'],
                'heal'     => $heal,
                'actor_hp' => $att['hp'],
            ];
        }
    }
}

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
