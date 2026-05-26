<?php
/**
 * seed_bots.php — Peuple l'arène avec ~80 gladiateurs-bots romains
 * Usage CLI uniquement : php scripts/seed_bots.php
 */
declare(strict_types=1);

// Bloque l'accès web direct
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Accès refusé — exécuter via CLI uniquement.');
}

define('ARENAFORGE_CLI', true);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/brute_generator.php';
require_once __DIR__ . '/../includes/combat_engine.php';
require_once __DIR__ . '/../includes/skill_tree.php';
require_once __DIR__ . '/../includes/elo_engine.php';

skill_tree_ensure_schema();

// ─── Configuration ────────────────────────────────────────────────────────────

const BOT_SUFFIX   = '@arenaforge.local';
const FIGHT_DAYS   = 60;   // historique sur 60 jours
const FIGHTS_COUNT = 400;  // combats à générer entre bots

// Distribution niveaux → nombre de bots
const LEVEL_DIST = [
    1 => 4,  2 => 4,  3 => 4,  4 => 4,  5 => 4,   // 20
    6 => 4,  7 => 4,  8 => 4,  9 => 4, 10 => 4,   // 20
   11 => 3, 12 => 3, 13 => 3, 14 => 3, 15 => 3,   // 15
   16 => 3, 17 => 3, 18 => 3, 19 => 3, 20 => 3,   // 15
   21 => 2, 22 => 2, 23 => 2, 24 => 2, 25 => 2,   // 10
];

// 80 noms romains / gladiateurs
const BOT_NAMES = [
    'Corvus','Draco','Vindex','Brutus','Cassian','Marius','Sulla','Gallus',
    'Titus','Flavian','Agrippa','Cato','Decimus','Felix','Galerius','Hadrian',
    'Iovinus','Kaeso','Leonidas','Magnus','Naso','Octavian','Publius','Quintus',
    'Remus','Severus','Tiberius','Ulpian','Valens','Vibius','Aldric','Brennus',
    'Carbo','Drusus','Ferox','Gratus','Helios','Ignatius','Janus','Kyros',
    'Lepidus','Milo','Nasica','Orion','Piso','Rufus','Scaeva','Tarquin',
    'Urbicus','Victor','Acer','Balbus','Crispus','Dion','Ennius','Fabius',
    'Geta','Hector','Iras','Julianus','Krato','Lycos','Mentor','Niger',
    'Ovidius','Primus','Regulus','Styx','Thrax','Ursus','Varro','Xenon',
    'Arcas','Bero','Castor','Daemon','Eurus','Furor','Glauco','Havoc',
];

// ─── Helpers ──────────────────────────────────────────────────────────────────

function log_msg(string $msg): void
{
    echo '[' . date('H:i:s') . '] ' . $msg . PHP_EOL;
}

/** LCG rapide seedé sur un entier */
function lcg_next(int &$seed): int
{
    $seed = (($seed * 1664525) + 1013904223) & 0x7FFFFFFF;
    return $seed;
}

/** Dépense les skill_points d'un bot aléatoirement dans l'arbre */
function bot_spend_skill_points(int $bruteId, int $sp): void
{
    $pdo      = db();
    $unlocked = [];

    while ($sp > 0) {
        $available = [];
        foreach (SKILL_TREE as $branch) {
            foreach ($branch['nodes'] as $nodeId => $node) {
                if (!in_array($nodeId, $unlocked, true)
                    && (int)$node['cost'] <= $sp
                    && skill_tree_can_unlock($bruteId, $nodeId, $unlocked)
                ) {
                    $available[] = ['id' => $nodeId, 'cost' => (int)$node['cost']];
                }
            }
        }
        if (empty($available)) break;

        $pick = $available[array_rand($available)];
        $pdo->prepare('INSERT IGNORE INTO brute_skill_nodes (brute_id, node_id) VALUES (?, ?)')
            ->execute([$bruteId, $pick['id']]);
        $unlocked[] = $pick['id'];
        $sp -= $pick['cost'];
    }

    $pdo->prepare('UPDATE brutes SET skill_points = ? WHERE id = ?')
        ->execute([$sp, $bruteId]);
}

// ─── Étape 1 : Création des bots ─────────────────────────────────────────────

log_msg('=== Création des bots ===');

$pdo     = db();
$botIds  = [];
$nameIdx = 0;
$namePool = BOT_NAMES;

// Récupère les armes disponibles (hors poings nus)
$allWeapons = $pdo->query(
    'SELECT id, name, min_level, damage_max FROM weapons
     WHERE name != "Poings nus"
     ORDER BY min_level ASC, damage_max ASC'
)->fetchAll(PDO::FETCH_ASSOC);

// Hash bcrypt fixe pour tous les bots (mdp inutilisé)
$botPwHash = password_hash('bot_password_never_used_' . time(), PASSWORD_BCRYPT, ['cost' => 4]);

foreach (LEVEL_DIST as $level => $count) {
    for ($i = 0; $i < $count; $i++) {
        if ($nameIdx >= count($namePool)) {
            log_msg('WARN: plus de noms disponibles, arrêt à ' . count($botIds) . ' bots.');
            break 2;
        }

        $name  = $namePool[$nameIdx++];
        $email = 'bot_' . strtolower($name) . BOT_SUFFIX;

        // Vérification idempotence
        $existing = $pdo->prepare('SELECT u.id FROM users u JOIN brutes b ON b.user_id = u.id WHERE u.email = ? LIMIT 1');
        $existing->execute([$email]);
        if ($row = $existing->fetch()) {
            $botIds[] = (int)$pdo->prepare('SELECT id FROM brutes WHERE user_id = ? LIMIT 1')
                ->execute([(int)$row['id']]) ? (int)$pdo->query('SELECT id FROM brutes WHERE user_id = ' . (int)$row['id'] . ' LIMIT 1')->fetchColumn() : 0;
            log_msg("  SKIP $name (existe déjà)");
            continue;
        }

        // ── Création user ──
        $pdo->prepare('INSERT INTO users (email, password_hash) VALUES (?, ?)')
            ->execute([$email, $botPwHash]);
        $userId = (int)$pdo->lastInsertId();

        // ── Stats de base ──
        $baseStats  = generate_stats($name);
        $appearance = generate_appearance($name);

        // ── Simulation des level-ups en stats ──
        $seed = abs(crc32($name . '_lvlup'));
        $bonusHp  = 0;
        $bonusStr = 0;
        $bonusAgi = 0;
        $bonusEnd = 0;

        $weaponsToGive = [];
        $availableForLevel = array_filter($allWeapons, fn($w) => (int)$w['min_level'] <= $level);
        $availableForLevel = array_values($availableForLevel);
        $weaponBudget = (int)floor($level / 5); // ~1 arme tous les 5 niveaux

        for ($lvl = 2; $lvl <= $level; $lvl++) {
            lcg_next($seed);
            $roll = $seed % 100;

            // 30% chance d'arme si budget restant et armes dispo
            $tryWeapon = $roll < 30 && $weaponBudget > count($weaponsToGive) && !empty($availableForLevel);
            if ($tryWeapon) {
                // Pioche une arme disponible à ce niveau de simulation, pas déjà choisie
                $candidateWeapons = array_filter(
                    $availableForLevel,
                    fn($w) => (int)$w['min_level'] <= $lvl
                        && !in_array((int)$w['id'], array_column($weaponsToGive, 'id'), true)
                );
                if (!empty($candidateWeapons)) {
                    lcg_next($seed);
                    $weaponsToGive[] = array_values($candidateWeapons)[$seed % count($candidateWeapons)];
                    continue;
                }
            }

            // Sinon bonus de stat
            lcg_next($seed);
            $statRoll = $seed % 4;
            if ($statRoll === 0) $bonusHp  += 5;
            elseif ($statRoll === 1) $bonusStr += 1;
            elseif ($statRoll === 2) $bonusAgi += 1;
            else                     $bonusEnd += 1;
        }

        $finalHp  = $baseStats['hp_max']    + $bonusHp;
        $finalStr = $baseStats['strength']  + $bonusStr;
        $finalAgi = $baseStats['agility']   + $bonusAgi;
        $finalEnd = $baseStats['endurance'] + $bonusEnd;

        $xp = xp_for_level($level) + (int)(($level > 1)
            ? rand(0, max(0, xp_for_level($level + 1) - xp_for_level($level) - 1))
            : 0);

        // ── Insertion brute ──
        $pdo->prepare('
            INSERT INTO brutes
              (user_id, name, level, xp, hp_max, strength, agility, endurance,
               appearance_seed, pending_levelup, skill_points)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)
        ')->execute([
            $userId, $name, $level, $xp,
            $finalHp, $finalStr, $finalAgi, $finalEnd,
            json_encode($appearance, JSON_UNESCAPED_UNICODE),
            $level,  // skill_points = level, seront dépensés ensuite
        ]);
        $bruteId = (int)$pdo->lastInsertId();

        // ── Arme de base : poings nus ──
        $fists = $pdo->query("SELECT id FROM weapons WHERE name = 'Poings nus' LIMIT 1")->fetch();
        if ($fists) {
            $pdo->prepare('INSERT IGNORE INTO brute_weapons (brute_id, weapon_id) VALUES (?, ?)')
                ->execute([$bruteId, (int)$fists['id']]);
        }

        // ── Armes supplémentaires ──
        foreach ($weaponsToGive as $w) {
            $pdo->prepare('INSERT IGNORE INTO brute_weapons (brute_id, weapon_id) VALUES (?, ?)')
                ->execute([$bruteId, (int)$w['id']]);
        }

        // ── Skill tree ──
        bot_spend_skill_points($bruteId, $level);

        $botIds[] = $bruteId;
        $weaponNames = implode(', ', array_column($weaponsToGive, 'name')) ?: 'aucune';
        log_msg("  ✓ $name (lvl $level) — str:$finalStr agi:$finalAgi end:$finalEnd hp:$finalHp — armes: $weaponNames");
    }
}

$botIds = array_filter($botIds); // remove 0s
log_msg(count($botIds) . ' bots créés ou existants.');

// ─── Étape 2 : Historique de combats ─────────────────────────────────────────

log_msg('');
log_msg('=== Génération de ' . FIGHTS_COUNT . ' combats historiques ===');

if (count($botIds) < 2) {
    log_msg('Pas assez de bots pour générer des combats.');
    exit(0);
}

// Groupe les bots par niveau pour des matchups cohérents
$botsByLevel = [];
foreach ($botIds as $bid) {
    $lvl = (int)$pdo->prepare('SELECT level FROM brutes WHERE id = ? LIMIT 1')
        ->execute([$bid]) ? (int)$pdo->query("SELECT level FROM brutes WHERE id = $bid LIMIT 1")->fetchColumn() : 1;
    $botsByLevel[$lvl][] = $bid;
}
ksort($botsByLevel);
$levelKeys = array_keys($botsByLevel);

$fightsDone   = 0;
$baseTime     = time() - (FIGHT_DAYS * 86400);

for ($f = 0; $f < FIGHTS_COUNT; $f++) {
    // Choix d'un niveau pivot aléatoire (légèrement biaisé vers les niveaux élevés)
    $pivotLevel = $levelKeys[min((int)floor(count($levelKeys) * sqrt(lcg_next($seed) / 0x7FFFFFFF)), count($levelKeys) - 1)];

    // Pool des adversaires possibles : niveau pivot ±4
    $pool = [];
    foreach ($botsByLevel as $lvl => $ids) {
        if (abs($lvl - $pivotLevel) <= 4) {
            foreach ($ids as $id) $pool[] = $id;
        }
    }
    if (count($pool) < 2) continue;

    // Tire deux bots distincts
    shuffle($pool);
    [$b1, $b2] = [$pool[0], $pool[1]];
    if ($b1 === $b2) continue;

    // Génère le combat
    try {
        $result = run_fight($b1, $b2);
    } catch (Throwable $e) {
        log_msg("  ERREUR combat $b1 vs $b2 : " . $e->getMessage());
        continue;
    }

    $winnerId = (int)$result['winner_id'];
    $loserId  = ($winnerId === $b1) ? $b2 : $b1;

    // Date historique aléatoire sur les 60 derniers jours
    $createdAt = date('Y-m-d H:i:s', $baseTime + rand(0, FIGHT_DAYS * 86400 - 1));

    // Insertion du combat
    $pdo->prepare('
        INSERT INTO fights (brute1_id, brute2_id, winner_id, log_json, xp_gained, context, created_at)
        VALUES (?, ?, ?, ?, 0, "arena", ?)
    ')->execute([$b1, $b2, $winnerId, json_encode($result['log']), $createdAt]);

    // Mise à jour MMR
    elo_apply_fight($winnerId, $loserId);

    $fightsDone++;
    if ($fightsDone % 50 === 0) {
        log_msg("  ... $fightsDone / " . FIGHTS_COUNT . " combats générés");
    }
}

log_msg("$fightsDone combats insérés.");

// ─── Étape 3 : Résumé ─────────────────────────────────────────────────────────

log_msg('');
log_msg('=== Résumé final ===');

$topBots = $pdo->query('
    SELECT b.name, b.level, b.mmr,
           (SELECT COUNT(*) FROM fights f WHERE f.winner_id = b.id) AS wins
    FROM brutes b
    JOIN users u ON u.id = b.user_id
    WHERE u.email LIKE "%@arenaforge.local"
      AND u.email NOT LIKE "demo@arenaforge.local"
      AND u.email NOT LIKE "bot_admin%"
      AND b.level >= 15
    ORDER BY b.mmr DESC
    LIMIT 10
')->fetchAll(PDO::FETCH_ASSOC);

log_msg('Top bots (niveau >= 15) :');
foreach ($topBots as $r) {
    log_msg(sprintf('  %-18s  lvl %-3d  %d MMR  %d victoires', $r['name'], $r['level'], $r['mmr'], $r['wins']));
}

log_msg('');
log_msg('Terminé ! Lance http://localhost/ArenaForge/public/ pour voir le résultat.');
