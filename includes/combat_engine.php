<?php
// Moteur de combat automatisé (maîtres + compagnons animaux)

declare(strict_types=1);

require_once __DIR__ . '/db.php';

// ============================================================
// Effets de statut — saignement / poison / étourdissement
// ============================================================
// Les effets sont déclenchés à la touche par le type d'arme du maître.
// Les pets n'appliquent pas de statut. Un statut existant est rafraîchi
// (pas de stack) pour éviter les boucles de dégâts ingérables.
//
// Types :
//   - bleed  : dégâts par tour, persiste N tours
//   - poison : dégâts par tour, persiste N tours (légèrement plus longs)
//   - stun   : saute la prochaine action, dure 1 tour
//
// Les compétences existantes restent passives ; les statuts viennent en
// complément des skills, pas en remplacement.

const STATUS_BY_WEAPON = [
    'Hache'  => ['type' => 'bleed',  'chance' => 25, 'damage' => 3, 'turns' => 3],
    'Dague'  => ['type' => 'poison', 'chance' => 30, 'damage' => 2, 'turns' => 4],
    'Masse'  => ['type' => 'stun',   'chance' => 18, 'damage' => 0, 'turns' => 1],
    'Lance'  => ['type' => 'bleed',  'chance' => 15, 'damage' => 2, 'turns' => 2],
    'Epee'   => ['type' => 'bleed',  'chance' => 12, 'damage' => 2, 'turns' => 2],
    'Arc'    => ['type' => 'poison', 'chance' => 18, 'damage' => 1, 'turns' => 3],
];

// ============================================================
// Météo d'arène — pioché à chaque combat, modifie les paramètres
// ============================================================
const ARENA_WEATHERS = [
    'clear'     => ['weight' => 50, 'label' => 'Ciel dégagé',     'icon' => '☀️',
                    'desc'   => 'Aucun effet, conditions parfaites.'],
    'rain'      => ['weight' => 18, 'label' => 'Pluie battante',  'icon' => '🌧️',
                    'desc'   => '-10% crit, +5% esquive pour tous.'],
    'sandstorm' => ['weight' => 17, 'label' => 'Tempête de sable','icon' => '🌪️',
                    'desc'   => 'Dégâts réduits de 2 (minimum 1).'],
    'heat'      => ['weight' => 15, 'label' => 'Soleil de plomb', 'icon' => '🔥',
                    'desc'   => '-1 PV par tour aux porteurs d\'armure.'],
];

function pick_weather(): array
{
    $total = 0;
    foreach (ARENA_WEATHERS as $w) $total += (int)$w['weight'];
    $r = roll(1, max(1, $total));
    $acc = 0;
    foreach (ARENA_WEATHERS as $code => $w) {
        $acc += (int)$w['weight'];
        if ($r <= $acc) {
            return [
                'code'  => $code,
                'label' => $w['label'],
                'icon'  => $w['icon'],
                'desc'  => $w['desc'],
            ];
        }
    }
    return [
        'code'  => 'clear',
        'label' => ARENA_WEATHERS['clear']['label'],
        'icon'  => ARENA_WEATHERS['clear']['icon'],
        'desc'  => ARENA_WEATHERS['clear']['desc'],
    ];
}

/**
 * Setter/getter de la météo courante (utilisé par resolve_attack/raw_hit).
 * Évite de devoir modifier toutes les signatures de fonction.
 */
function combat_weather(?array $set = null): ?array
{
    static $w = null;
    if ($set !== null) {
        $w = $set;
    }
    return $w;
}

function status_index(array $fighter, string $type): ?int
{
    foreach (($fighter['statuses'] ?? []) as $i => $s) {
        if (($s['type'] ?? '') === $type) {
            return (int)$i;
        }
    }
    return null;
}

function apply_status(array &$target, array $def, int $turn, array &$log): void
{
    if ($target['hp'] <= 0) {
        return;
    }
    if (!isset($target['statuses']) || !is_array($target['statuses'])) {
        $target['statuses'] = [];
    }

    $entry = [
        'type'       => $def['type'],
        'damage'     => (int)($def['damage'] ?? 0),
        'turns_left' => (int)$def['turns'],
    ];

    $idx = status_index($target, $def['type']);
    if ($idx !== null) {
        $target['statuses'][$idx] = $entry;
    } else {
        $target['statuses'][] = $entry;
    }

    $log[] = [
        'turn'         => $turn,
        'event'        => 'status_apply',
        'target'       => $target['name'],
        'target_slot'  => $target['slot'] ?? null,
        'status_type'  => $def['type'],
        'duration'     => (int)$def['turns'],
    ];
}

/**
 * Ultime "Seconde vie" — appelé chaque fois qu'un maître pourrait tomber
 * à 0 PV (hit ou tick DoT). Restaure un % de PV et purge les DoT.
 */
function try_ult_revive(array &$fighter, int $turn, array &$log): bool
{
    if (($fighter['hp'] ?? 0) > 0) return false;
    if (($fighter['role'] ?? 'master') !== 'master') return false;
    if (!empty($fighter['ult_used']['ult_revive_pct'])) return false;

    $ult = has_skill($fighter, 'ult_revive_pct');
    if (!$ult) return false;

    $heal = max(1, (int)floor((int)$fighter['hp_max'] * (int)$ult['effect_value'] / 100));
    $fighter['hp'] = $heal;
    $fighter['ult_used']['ult_revive_pct'] = true;
    // Purger les DoT pour ne pas remourir immédiatement
    $fighter['statuses'] = array_values(array_filter(
        $fighter['statuses'] ?? [],
        fn($s) => !in_array($s['type'] ?? '', ['bleed', 'poison'], true)
    ));
    $log[] = [
        'turn'        => $turn,
        'event'       => 'ult_trigger',
        'actor'       => $fighter['name'],
        'actor_slot'  => $fighter['slot'] ?? null,
        'skill_name'  => 'Seconde vie',
        'effect_type' => 'ult_revive_pct',
        'actor_hp'    => $fighter['hp'],
        'heal'        => $heal,
    ];
    return true;
}

/**
 * Tick de fin de round : applique les dégâts des DoT et décrémente les
 * compteurs. Renvoie true si la cible vient de mourir suite au tick.
 */
function tick_dot_statuses(array &$target, int $turn, array &$log): bool
{
    if (empty($target['statuses']) || $target['hp'] <= 0) {
        return false;
    }

    $remaining = [];
    $diedHere  = false;
    $revived   = false;

    foreach ($target['statuses'] as $s) {
        $type = (string)($s['type'] ?? '');

        // Stun ne tick pas ici (consommé à l'action). Il est conservé tel quel.
        if ($type === 'stun') {
            $remaining[] = $s;
            continue;
        }

        if (in_array($type, ['bleed', 'poison'], true)) {
            $dmg = max(0, (int)($s['damage'] ?? 0));
            if ($dmg > 0 && $target['hp'] > 0) {
                $target['hp'] = max(0, $target['hp'] - $dmg);
                $log[] = [
                    'turn'        => $turn,
                    'event'       => 'status_tick',
                    'target'      => $target['name'],
                    'target_slot' => $target['slot'] ?? null,
                    'status_type' => $type,
                    'damage'      => $dmg,
                    'target_hp'   => $target['hp'],
                ];
                if ($target['hp'] <= 0) {
                    // Ultime Seconde vie : peut sauver un maître ici. Si elle
                    // se déclenche, on arrête le tick courant : try_ult_revive
                    // a déjà purgé les DoT, et continuer les itérations
                    // sur l'ancienne liste les remettrait dans $remaining.
                    if (try_ult_revive($target, $turn, $log)) {
                        $revived = true;
                        break;
                    }
                    $diedHere = true;
                    if (($target['role'] ?? 'master') === 'pet') {
                        $log[] = [
                            'turn'       => $turn,
                            'event'      => 'down',
                            'actor'      => $target['name'],
                            'actor_slot' => $target['slot'] ?? null,
                        ];
                    }
                }
            }
        }

        $turns = (int)($s['turns_left'] ?? 0) - 1;
        if ($turns > 0 && !$diedHere) {
            $s['turns_left'] = $turns;
            $remaining[] = $s;
        } else {
            $log[] = [
                'turn'        => $turn,
                'event'       => 'status_expire',
                'target'      => $target['name'],
                'target_slot' => $target['slot'] ?? null,
                'status_type' => $type,
            ];
        }

        if ($diedHere) {
            // Inutile de continuer les ticks suivants une fois mort
            break;
        }
    }

    if (!$revived) {
        // try_ult_revive a déjà fixé $target['statuses'] proprement
        $target['statuses'] = $remaining;
    }
    return $diedHere;
}

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

    // Armes + niveau d'amélioration (forge)
    $stmt = $pdo->prepare('
        SELECT w.*, COALESCE(bwu.upgrade_level, 0) AS upgrade_level
        FROM weapons w
        JOIN brute_weapons bw ON bw.weapon_id = w.id
        LEFT JOIN brute_weapon_upgrades bwu
          ON bwu.weapon_id = w.id AND bwu.brute_id = bw.brute_id
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

    // Armures équipées : somme des bonus PV et réduction de dégâts
    $stmt = $pdo->prepare('
        SELECT a.hp_bonus, a.damage_reduction
        FROM armors a
        JOIN brute_armors ba ON ba.armor_id = a.id
        WHERE ba.brute_id = ? AND ba.equipped = 1
    ');
    $stmt->execute([$bruteId]);
    $armorBonusHp = 0;
    $armorReduction = 0;
    foreach ($stmt->fetchAll() as $row) {
        $armorBonusHp   += (int)$row['hp_bonus'];
        $armorReduction += (int)$row['damage_reduction'];
    }

    $hpMax = (int)$brute['hp_max'] + $armorBonusHp;

    return [
        'id'               => (int)$brute['id'],
        'role'             => 'master',
        'name'             => $brute['name'],
        'level'            => (int)$brute['level'],
        'hp_max'           => $hpMax,
        'hp'               => $hpMax,
        'strength'         => (int)$brute['strength'],
        'agility'          => (int)$brute['agility'],
        'endurance'        => (int)$brute['endurance'],
        'appearance'       => $brute['appearance_seed'],
        'weapons'          => $weapons,
        'skills'           => $skills,
        'armor_reduction'  => $armorReduction,
        'statuses'         => [],
        'ult_used'         => [],
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
            'statuses'   => [],
            'ult_used'   => [],
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
        $slot    = $f['slot'] ?? null;
        $isPartner = $slot && substr((string)$slot, 1) !== '0';
        return [
            'slot'       => $slot,
            'role'       => $isPartner ? 'partner' : 'master',
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
    $team = ['master' => null, 'partner' => null, 'pets' => []];
    foreach ($combatants as $c) {
        if ($c['side'] !== $side) {
            continue;
        }
        $slotIdx = (int)substr((string)$c['slot'], 1);
        if ($c['role'] === 'master') {
            if ($slotIdx === 0) {
                $team['master'] = fighter_public($c);
            } else {
                $team['partner'] = fighter_public($c);
            }
        } else {
            $team['pets'][] = fighter_public($c);
        }
    }
    return $team;
}

/**
 * Construit une équipe duo : maître + partenaire (typiquement un pupille).
 * Pas de pet en mode duo : la jauge visuelle reste lisible.
 */
function build_duo_team(int $masterId, int $partnerId, string $sidePrefix): array
{
    $master = load_fighter($masterId);
    $master['side'] = $sidePrefix;
    $master['slot'] = $sidePrefix . '0';

    $partner = load_fighter($partnerId);
    $partner['side'] = $sidePrefix;
    $partner['slot'] = $sidePrefix . '1';
    // Mécaniquement traité comme un maître (skills + armes + armures actives)

    return [
        $master['slot']  => $master,
        $partner['slot'] => $partner,
    ];
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
    return run_combat_loop($combatants);
}

/**
 * Boucle de combat principale, isolée de la construction des équipes pour
 * permettre les modes PvE (boss) et duo (2v2 avec pupille).
 *
 * Les combattants doivent être indexés par slot (L0, R0, L1, R1, etc.) et
 * porter au moins : id, name, hp, hp_max, agility, strength, role, side,
 * weapons, skills, statuses, ult_used.
 */
function run_combat_loop(array $combatants): array
{
    $log = [];
    $weather = pick_weather();
    combat_weather($weather);

    $log[] = [
        'event'    => 'start',
        'teams'    => [
            'L' => team_public($combatants, 'L'),
            'R' => team_public($combatants, 'R'),
        ],
        'weather'  => $weather,
        // Backward-compat : champ utilisé par les anciens replays (non requis par fight.js)
        'fighters' => [
            fighter_public($combatants['L0']),
            fighter_public($combatants['R0']),
        ],
    ];

    $round = 1;
    $maxRounds = 60;

    while ($round <= $maxRounds && $combatants['L0']['hp'] > 0 && $combatants['R0']['hp'] > 0) {
        // 0) Tick météo — Soleil de plomb : -1 PV / tour aux porteurs d'armure
        if (($weather['code'] ?? '') === 'heat') {
            foreach ($combatants as $slot => $c) {
                if ($c['hp'] <= 0) continue;
                if (($c['role'] ?? 'master') !== 'master') continue;
                if ((int)($c['armor_reduction'] ?? 0) <= 0) continue;
                $combatants[$slot]['hp'] = max(0, $c['hp'] - 1);
                $log[] = [
                    'turn'        => $round,
                    'event'       => 'weather_tick',
                    'target'      => $combatants[$slot]['name'],
                    'target_slot' => $slot,
                    'weather'     => 'heat',
                    'damage'      => 1,
                    'target_hp'   => $combatants[$slot]['hp'],
                ];
                if ($combatants[$slot]['hp'] <= 0) {
                    try_ult_revive($combatants[$slot], $round, $log);
                }
            }
            if ($combatants['L0']['hp'] <= 0 || $combatants['R0']['hp'] <= 0) break;
        }

        // 1) Ticks de DoT (saignement / poison) — peuvent tuer un combattant
        foreach ($combatants as $slot => $c) {
            if ($c['hp'] <= 0) {
                continue;
            }
            tick_dot_statuses($combatants[$slot], $round, $log);
        }
        if ($combatants['L0']['hp'] <= 0 || $combatants['R0']['hp'] <= 0) {
            break;
        }

        // 2) Initiative recalculée chaque round
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

            // Étourdissement : saute l'action et consomme le statut
            $stunIdx = status_index($combatants[$slot], 'stun');
            if ($stunIdx !== null) {
                $log[] = [
                    'turn'        => $round,
                    'event'       => 'status_skip',
                    'actor'       => $combatants[$slot]['name'],
                    'actor_slot'  => $slot,
                    'status_type' => 'stun',
                ];
                $stunEntry = $combatants[$slot]['statuses'][$stunIdx];
                $stunEntry['turns_left'] = (int)$stunEntry['turns_left'] - 1;
                if ($stunEntry['turns_left'] <= 0) {
                    array_splice($combatants[$slot]['statuses'], $stunIdx, 1);
                    $log[] = [
                        'turn'        => $round,
                        'event'       => 'status_expire',
                        'target'      => $combatants[$slot]['name'],
                        'target_slot' => $slot,
                        'status_type' => 'stun',
                    ];
                } else {
                    $combatants[$slot]['statuses'][$stunIdx] = $stunEntry;
                }
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
    // Météo : pluie augmente l'esquive de 5 %
    $weatherCode = combat_weather()['code'] ?? '';
    if ($weatherCode === 'rain') {
        $baseDodge += 5;
    }
    $baseDodge = max(0, min(80, $baseDodge));

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

        // Forge : +10% dégâts par niveau d'amélioration d'arme
        $upgrade = (int)($weapon['upgrade_level'] ?? 0);
        if ($upgrade > 0) {
            $damage = (int)floor($damage * (1 + 0.10 * $upgrade));
        }

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

    // Météo : pluie réduit le crit de 10
    $weatherCode = combat_weather()['code'] ?? '';
    if ($weatherCode === 'rain') {
        $critChance = max(0, $critChance - 10);
    }

    $isCrit = roll(1, 100) <= $critChance;
    if ($isCrit) {
        $damage = (int)floor($damage * 1.8);
    }

    // Météo : tempête de sable réduit les dégâts bruts de 2 (min 1)
    if ($weatherCode === 'sandstorm') {
        $damage = max(1, $damage - 2);
    }

    // Ultime "Frappe titanesque" — double les dégâts si l'attaquant est sous
    // 50% PV. Une seule fois par combat.
    if ($att['role'] === 'master' && empty($att['ult_used']['ult_double_dmg'])) {
        if ($ult = has_skill($att, 'ult_double_dmg')) {
            if ($att['hp'] / max(1, $att['hp_max']) <= 0.5) {
                $damage = (int)floor($damage * 2);
                $att['ult_used']['ult_double_dmg'] = true;
                $log[] = [
                    'turn'        => $turn,
                    'event'       => 'ult_trigger',
                    'actor'       => $att['name'],
                    'actor_slot'  => $att['slot'],
                    'skill_name'  => 'Frappe titanesque',
                    'effect_type' => 'ult_double_dmg',
                    'actor_hp'    => $att['hp'],
                ];
            }
        }
    }

    // Armure / bouclier (maître défenseur uniquement)
    if ($def['role'] === 'master') {
        if ($s = has_skill($def, 'armor_flat')) {
            $damage = max(1, $damage - (int)$s['effect_value']);
        }
        // Forge : armure équipée (somme des damage_reduction)
        if (!empty($def['armor_reduction'])) {
            $damage = max(1, $damage - (int)$def['armor_reduction']);
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

    // Ultime "Cri de guerre" — au premier critique subi, le maître soigne
    // un pourcentage de ses PV max.
    if ($isCrit && $def['role'] === 'master' && $def['hp'] > 0 && empty($def['ult_used']['ult_heal_pct'])) {
        if ($ult = has_skill($def, 'ult_heal_pct')) {
            $heal = max(1, (int)floor((int)$def['hp_max'] * (int)$ult['effect_value'] / 100));
            $def['hp'] = min((int)$def['hp_max'], $def['hp'] + $heal);
            $def['ult_used']['ult_heal_pct'] = true;
            $log[] = [
                'turn'        => $turn,
                'event'       => 'ult_trigger',
                'actor'       => $def['name'],
                'actor_slot'  => $def['slot'],
                'skill_name'  => 'Cri de guerre',
                'effect_type' => 'ult_heal_pct',
                'actor_hp'    => $def['hp'],
                'heal'        => $heal,
            ];
        }
    }

    try_ult_revive($def, $turn, $log);

    // Application d'un statut selon l'arme du maître attaquant
    if ($att['role'] === 'master' && $weapon !== null && $def['hp'] > 0) {
        $statusDef = STATUS_BY_WEAPON[$weapon['name']] ?? null;
        if ($statusDef !== null) {
            $chance = (int)$statusDef['chance'] + ($isCrit ? 15 : 0);
            if (roll(1, 100) <= $chance) {
                apply_status($def, $statusDef, $turn, $log);
            }
        }
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
