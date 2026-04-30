<?php
// Bots auto-fight quotidien
//
// Sans cron sur o2switch, on procède en mode "lazy" : à chaque chargement
// de page authentifié, on vérifie si le quota du jour a été initialisé,
// puis on consomme jusqu'à BOT_FIGHTS_PER_TICK combats programmés. Les
// joueurs voient progressivement le mouvement dans le classement.
//
// Quota cible : ~3 combats par bot et par jour (random 1-3) — on ne veut
// pas saturer la base ni faire grimper les bots trop vite.

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/combat_engine.php';
require_once __DIR__ . '/elo_engine.php';
require_once __DIR__ . '/brute_generator.php';

const BOT_FIGHTS_PER_BOT_AVG = 3;   // moyenne par bot par jour
const BOT_FIGHTS_PER_TICK    = 3;   // combats consommés par chargement de page
const BOT_TICK_PROBABILITY   = 60;  // % de chance qu'un page-load déclenche le tick

// ============================================================
// State accessors
// ============================================================

function system_state_get(string $key, ?string $default = null): ?string
{
    $stmt = db()->prepare('SELECT state_value FROM system_state WHERE state_key = ? LIMIT 1');
    $stmt->execute([$key]);
    $v = $stmt->fetchColumn();
    return $v === false ? $default : (string)$v;
}

function system_state_set(string $key, string $value): void
{
    db()->prepare('
        INSERT INTO system_state (state_key, state_value) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE state_value = VALUES(state_value)
    ')->execute([$key, $value]);
}

// ============================================================
// Initialisation quotidienne du quota
// ============================================================

function ensure_daily_bot_quota(): void
{
    $today = date('Y-m-d');
    $last  = system_state_get('bot_run_date');
    if ($last === $today) return;

    // Compter le nombre de bots actifs
    $stmt = db()->query("
        SELECT COUNT(*)
        FROM brutes b
        JOIN users u ON u.id = b.user_id
        WHERE u.email LIKE 'bot%@arenaforge.local'
    ");
    $botCount = (int)$stmt->fetchColumn();
    if ($botCount < 2) {
        // Pas assez de bots pour faire des combats : on note quand même la date
        system_state_set('bot_run_date', $today);
        system_state_set('bot_fights_remaining', '0');
        return;
    }

    // Cible : moyenne BOT_FIGHTS_PER_BOT_AVG combats par bot, ±30 % de variabilité
    $target = (int)round($botCount * BOT_FIGHTS_PER_BOT_AVG * (0.7 + (random_int(0, 60) / 100)));
    $target = min($target, $botCount * 5); // plafond de sécurité

    system_state_set('bot_run_date', $today);
    system_state_set('bot_fights_remaining', (string)$target);
}

// ============================================================
// Tick : exécute jusqu'à N combats si du quota reste
// ============================================================

function maybe_tick_bot_fights(): void
{
    // Probabilité d'exécution pour répartir la charge
    if (random_int(1, 100) > BOT_TICK_PROBABILITY) return;

    ensure_daily_bot_quota();

    $remaining = (int)system_state_get('bot_fights_remaining', '0');
    if ($remaining <= 0) return;

    $toRun = min(BOT_FIGHTS_PER_TICK, $remaining);
    $ran = 0;

    for ($i = 0; $i < $toRun; $i++) {
        if (run_one_bot_fight()) $ran++;
    }

    if ($ran > 0) {
        system_state_set('bot_fights_remaining', (string)max(0, $remaining - $ran));
    }
}

// ============================================================
// Un combat de bot
// ============================================================

function run_one_bot_fight(): bool
{
    $pdo = db();

    // Pioche un bot challenger au hasard
    $stmt = $pdo->query("
        SELECT b.id, b.level, b.mmr
        FROM brutes b
        JOIN users u ON u.id = b.user_id
        WHERE u.email LIKE 'bot%@arenaforge.local'
          AND b.pending_levelup = 0
        ORDER BY RAND()
        LIMIT 1
    ");
    $a = $stmt->fetch();
    if (!$a) return false;

    // Cherche un adversaire bot proche en MMR (±200)
    $stmt = $pdo->prepare("
        SELECT b.id, b.level, b.mmr
        FROM brutes b
        JOIN users u ON u.id = b.user_id
        WHERE u.email LIKE 'bot%@arenaforge.local'
          AND b.id != ?
          AND b.pending_levelup = 0
          AND ABS(b.mmr - ?) < 250
        ORDER BY RAND()
        LIMIT 1
    ");
    $stmt->execute([(int)$a['id'], (int)$a['mmr']]);
    $b = $stmt->fetch();
    if (!$b) {
        // Fallback : n'importe quel bot
        $stmt = $pdo->prepare("
            SELECT b.id, b.level, b.mmr FROM brutes b
            JOIN users u ON u.id = b.user_id
            WHERE u.email LIKE 'bot%@arenaforge.local'
              AND b.id != ? AND b.pending_levelup = 0
            ORDER BY RAND() LIMIT 1
        ");
        $stmt->execute([(int)$a['id']]);
        $b = $stmt->fetch();
        if (!$b) return false;
    }

    try {
        $result = run_fight((int)$a['id'], (int)$b['id']);
    } catch (Throwable $e) {
        return false;
    }

    // Insertion du fight (context = bot pour le distinguer)
    $pdo->prepare("
        INSERT INTO fights (brute1_id, brute2_id, winner_id, log_json, xp_gained, context)
        VALUES (?, ?, ?, ?, 0, 'bot')
    ")->execute([
        (int)$a['id'], (int)$b['id'], $result['winner_id'],
        json_encode($result['log'], JSON_UNESCAPED_UNICODE),
    ]);

    // XP modeste pour les bots (+1 victoire / 0 défaite, pas de level-up)
    $isAWinner = $result['winner_id'] === (int)$a['id'];
    $awardXp = function (int $bid, int $xpGain) use ($pdo) {
        if ($xpGain <= 0) return;
        $stmt = $pdo->prepare('SELECT xp, level FROM brutes WHERE id = ? LIMIT 1');
        $stmt->execute([$bid]);
        $row = $stmt->fetch();
        if (!$row) return;
        $newXp = (int)$row['xp'] + $xpGain;
        $newLevel = (int)$row['level'];
        // Level-up des bots : on autorise mais sans pending_levelup (auto-applied)
        while ($newXp >= xp_for_level($newLevel + 1)) {
            $newLevel++;
        }
        $pdo->prepare('UPDATE brutes SET xp = ?, level = ? WHERE id = ?')
            ->execute([$newXp, $newLevel, $bid]);
    };
    $awardXp((int)$a['id'], $isAWinner ? 1 : 0);
    $awardXp((int)$b['id'], $isAWinner ? 0 : 1);

    // Auto-application des bonus de level-up : on choisit aléatoirement une stat
    auto_apply_bot_levelup_if_needed((int)$a['id']);
    auto_apply_bot_levelup_if_needed((int)$b['id']);

    // ELO
    $winnerId = $result['winner_id'];
    $loserId  = $winnerId === (int)$a['id'] ? (int)$b['id'] : (int)$a['id'];
    elo_apply_fight($winnerId, $loserId);

    return true;
}

/**
 * Si un bot a atteint un niveau, on lui applique automatiquement un bonus
 * aléatoire (stat / arme / skill / pet) pour qu'il ne reste pas bloqué.
 */
function auto_apply_bot_levelup_if_needed(int $bruteId): void
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT level, hp_max, strength, agility, endurance FROM brutes WHERE id = ? LIMIT 1');
    $stmt->execute([$bruteId]);
    $b = $stmt->fetch();
    if (!$b) return;

    // On vérifie si le bot mérite un bonus en regardant son niveau vs ses stats
    // Heuristique simple : niveau N → ~ N stats supplémentaires totales
    $statTotal = (int)$b['strength'] + (int)$b['agility'] + (int)$b['endurance'];
    $expected  = 12 + (int)$b['level']; // 12 baseline + 1 par niveau
    if ($statTotal >= $expected) return;

    // Choix aléatoire d'un bonus de stat
    $choices = [
        ['col' => 'hp_max',    'val' => 5],
        ['col' => 'strength',  'val' => 1],
        ['col' => 'agility',   'val' => 1],
        ['col' => 'endurance', 'val' => 1],
    ];
    $pick = $choices[random_int(0, 3)];
    $col  = $pick['col']; // colonne whitelistée localement
    $allowed = ['hp_max', 'strength', 'agility', 'endurance'];
    if (!in_array($col, $allowed, true)) return;
    $pdo->prepare("UPDATE brutes SET `$col` = `$col` + ? WHERE id = ?")
        ->execute([$pick['val'], $bruteId]);
}
