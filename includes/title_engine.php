<?php
// Titres de gloire — récompenses prestigieuses débloquables par exploits.
//
// Un seul titre actif à la fois ; quand un titre est actif, ses bonus
// (stats / PV) sont appliqués dans load_fighter() au moment du combat.
//
// Détection : appelée après chaque combat, sacrifice, tournoi ou
// transition de saison via check_and_award_titles($bruteId).
// Idempotent : un titre déjà débloqué ne re-déclenche pas de notif.

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/notif_helper.php';

// ============================================================
// Constantes de seuils — centralisées ici pour faciliter l'équilibrage
// ============================================================

const TITLE_INVAINCU_STREAK     = 20;   // victoires consécutives
const TITLE_FLEAU_WINS          = 500;  // victoires totales
const TITLE_GLADIATEUR_TOURNEYS = 5;    // tournois gagnés
const TITLE_MAUDIT_SACRIFICES   = 10;   // sacrifices accomplis
const TITLE_ETERNEL_STREAK      = 3;    // saisons Légende consécutives

// ============================================================
// Bonus — chargement depuis la table `titles`
// ============================================================

function title_definitions(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;

    $pdo  = db();
    $rows = $pdo->query('SELECT * FROM titles ORDER BY sort_order ASC, code ASC')->fetchAll();

    $cache = [];
    foreach ($rows as $r) {
        $bonus = json_decode((string)$r['bonus_json'], true) ?: [];
        $cache[$r['code']] = [
            'code'        => $r['code'],
            'label'       => $r['label'],
            'description' => $r['description'],
            'flavor'      => $r['flavor'],
            'bonus'       => is_array($bonus) ? $bonus : [],
            'rarity'      => $r['rarity'],
            'icon_path'   => $r['icon_path'],
            'sort_order'  => (int)$r['sort_order'],
        ];
    }
    return $cache;
}

function title_get(string $code): ?array
{
    return title_definitions()[$code] ?? null;
}

/**
 * Retourne le HTML d'un petit chip de titre, prêt à insérer dans n'importe
 * quelle vue. Retourne '' si le code est null ou inconnu.
 */
function title_chip_html(?string $code): string
{
    if ($code === null || $code === '') return '';
    $def = title_get($code);
    if (!$def) return '';
    $rClass = 'title-chip title-' . $def['rarity'];
    return '<span class="' . $rClass . '" title="' . htmlspecialchars($def['description'], ENT_QUOTES) . '">'
         . htmlspecialchars($def['label'], ENT_QUOTES)
         . '</span>';
}

function title_bonus_summary(array $bonus): string
{
    if (empty($bonus)) return '';
    $labels = [
        'strength'  => 'Force',
        'agility'   => 'Agilité',
        'endurance' => 'Endurance',
        'hp_max'    => 'PV max',
    ];
    $parts = [];
    foreach ($bonus as $stat => $val) {
        $v = (int)$val;
        if ($v === 0) continue;
        $sign = $v > 0 ? '+' : '';
        $parts[] = $sign . $v . ' ' . ($labels[$stat] ?? $stat);
    }
    return implode(' · ', $parts);
}

// ============================================================
// Application des bonus au combattant
// ============================================================

/**
 * Lit le titre actif d'une brute (code uniquement, NULL si aucun).
 */
function active_title_code(int $bruteId): ?string
{
    $stmt = db()->prepare('SELECT active_title_code FROM brutes WHERE id = ? LIMIT 1');
    $stmt->execute([$bruteId]);
    $code = $stmt->fetchColumn();
    return ($code === false || $code === null || $code === '') ? null : (string)$code;
}

/**
 * Applique le bonus d'un titre à un tableau fighter (depuis load_fighter()).
 * Modifie en place et retourne le tableau modifié.
 */
function apply_title_bonus(array $fighter, ?string $code): array
{
    if ($code === null) return $fighter;
    $def = title_get($code);
    if (!$def || empty($def['bonus'])) return $fighter;

    foreach ($def['bonus'] as $stat => $val) {
        $v = (int)$val;
        if ($v === 0) continue;
        switch ($stat) {
            case 'strength':
            case 'agility':
            case 'endurance':
                if (isset($fighter[$stat])) {
                    $fighter[$stat] = (int)$fighter[$stat] + $v;
                }
                break;
            case 'hp_max':
                $fighter['hp_max'] = (int)$fighter['hp_max'] + $v;
                $fighter['hp']     = (int)$fighter['hp_max'];
                break;
        }
    }
    $fighter['active_title'] = $code;
    return $fighter;
}

// ============================================================
// Lecture pour la page titles.php
// ============================================================

/**
 * Retourne tous les titres avec leur état (débloqué ou non) pour une brute.
 */
function get_brute_titles(int $bruteId): array
{
    $defs = title_definitions();
    $pdo  = db();

    $stmt = $pdo->prepare('SELECT title_code, unlocked_at FROM brute_titles WHERE brute_id = ?');
    $stmt->execute([$bruteId]);
    $owned = [];
    foreach ($stmt->fetchAll() as $row) {
        $owned[$row['title_code']] = $row['unlocked_at'];
    }

    $active = active_title_code($bruteId);

    $result = [];
    foreach ($defs as $code => $def) {
        $result[] = array_merge($def, [
            'unlocked'    => isset($owned[$code]),
            'unlocked_at' => $owned[$code] ?? null,
            'active'      => ($code === $active),
        ]);
    }
    return $result;
}

// ============================================================
// Attribution
// ============================================================

/**
 * Octroie un titre à une brute si elle ne l'a pas déjà.
 * Pousse une notification persistante en cas de succès.
 * Retourne true si nouvellement débloqué.
 */
function award_title(int $bruteId, string $code): bool
{
    $def = title_get($code);
    if (!$def) return false;

    $stmt = db()->prepare('INSERT IGNORE INTO brute_titles (brute_id, title_code) VALUES (?, ?)');
    $stmt->execute([$bruteId, $code]);
    if ($stmt->rowCount() === 0) return false;

    $bonusTxt = title_bonus_summary($def['bonus']);
    push_notif(
        $bruteId,
        'title',
        '🏆 Nouveau titre : ' . $def['label'],
        $bonusTxt === '' ? $def['description'] : ($def['description'] . ' · Bonus : ' . $bonusTxt),
        'titles.php',
        $def['icon_path']
    );
    return true;
}

/**
 * Définit le titre actif. Renvoie false si le code n'est pas débloqué.
 * Code NULL ou '' = retire le titre actif.
 */
function set_active_title(int $bruteId, ?string $code): bool
{
    $pdo = db();
    if ($code === null || $code === '') {
        $pdo->prepare('UPDATE brutes SET active_title_code = NULL WHERE id = ?')->execute([$bruteId]);
        return true;
    }
    $stmt = $pdo->prepare('SELECT 1 FROM brute_titles WHERE brute_id = ? AND title_code = ? LIMIT 1');
    $stmt->execute([$bruteId, $code]);
    if (!$stmt->fetchColumn()) return false;

    $pdo->prepare('UPDATE brutes SET active_title_code = ? WHERE id = ?')->execute([$code, $bruteId]);
    return true;
}

// ============================================================
// Vérification — appelée après chaque événement pertinent
// ============================================================

/**
 * Vérifie l'ensemble des conditions et octroie les titres méritants.
 * Conçue pour être appelée :
 *   - après chaque combat d'arène (start_fight.php)
 *   - après un sacrifice (sacrifice_engine.php)
 *   - après un tournoi (tournament_engine.php)
 *   - après une transition de saison (elo_engine.php)
 *   - à l'ouverture de la page titles.php (rattrapage rétroactif)
 *
 * Retourne la liste des codes nouvellement débloqués.
 */
function check_and_award_titles(int $bruteId): array
{
    $pdo = db();
    $newly = [];

    // Charge l'état actuel : déjà-débloqués → on saute la vérif coûteuse
    $stmt = $pdo->prepare('SELECT title_code FROM brute_titles WHERE brute_id = ?');
    $stmt->execute([$bruteId]);
    $owned = array_flip(array_column($stmt->fetchAll(), 'title_code'));

    // --- L'Invaincu : win_streak ≥ 20 (suivi en colonne dédiée) ---
    if (!isset($owned['invaincu'])) {
        $stmt = $pdo->prepare('SELECT win_streak, win_streak_best FROM brutes WHERE id = ? LIMIT 1');
        $stmt->execute([$bruteId]);
        $row = $stmt->fetch();
        if ($row) {
            $best = max((int)$row['win_streak'], (int)$row['win_streak_best']);
            if ($best >= TITLE_INVAINCU_STREAK && award_title($bruteId, 'invaincu')) {
                $newly[] = 'invaincu';
            }
        }
    }

    // --- Fléau de l'Arène : 500 victoires en arène ---
    if (!isset($owned['fleau'])) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM fights WHERE winner_id = ? AND context = 'arena'");
        $stmt->execute([$bruteId]);
        if ((int)$stmt->fetchColumn() >= TITLE_FLEAU_WINS && award_title($bruteId, 'fleau')) {
            $newly[] = 'fleau';
        }
    }

    // --- Maudit des Dieux : ≥ 10 sacrifices ---
    if (!isset($owned['maudit'])) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM sacrifices WHERE brute_id = ?');
        $stmt->execute([$bruteId]);
        if ((int)$stmt->fetchColumn() >= TITLE_MAUDIT_SACRIFICES && award_title($bruteId, 'maudit')) {
            $newly[] = 'maudit';
        }
    }

    // --- Gladiateur des Gladiateurs : ≥ 5 tournois remportés ---
    if (!isset($owned['gladiateur'])) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM tournament_entries WHERE brute_id = ? AND placement = 1 AND is_ai = 0');
        $stmt->execute([$bruteId]);
        if ((int)$stmt->fetchColumn() >= TITLE_GLADIATEUR_TOURNEYS && award_title($bruteId, 'gladiateur')) {
            $newly[] = 'gladiateur';
        }
    }

    // --- Seigneur de Rome : au moins une saison terminée au tier Légende ---
    if (!isset($owned['seigneur'])) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM season_rewards WHERE brute_id = ? AND tier_code = 'legend'");
        $stmt->execute([$bruteId]);
        if ((int)$stmt->fetchColumn() >= 1 && award_title($bruteId, 'seigneur')) {
            $newly[] = 'seigneur';
        }
    }

    // --- Éternel Champion : 3 saisons consécutives Légende ---
    if (!isset($owned['eternel'])) {
        if (check_consecutive_legend_seasons($bruteId, TITLE_ETERNEL_STREAK)
            && award_title($bruteId, 'eternel')) {
            $newly[] = 'eternel';
        }
    }

    // --- Fils de Mars : combo Fléau + Seigneur + Gladiateur ---
    if (!isset($owned['mars'])) {
        // On relit `brute_titles` car les titres ci-dessus viennent peut-être d'être ajoutés
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM brute_titles WHERE brute_id = ? AND title_code IN ('fleau','seigneur','gladiateur')");
        $stmt->execute([$bruteId]);
        if ((int)$stmt->fetchColumn() >= 3 && award_title($bruteId, 'mars')) {
            $newly[] = 'mars';
        }
    }

    // Titres de complétion (100 % d'une catégorie de trophées)
    $newly = array_merge($newly, check_completion_titles($bruteId));

    // 'parfait' et 'intouchable' sont déclenchés explicitement par les
    // appelants (qui ont accès au log du combat / au déroulé du tournoi).
    return $newly;
}

/**
 * Vérifie si la brute a terminé `n` saisons consécutives au tier Légende.
 * Une saison "manquée" (non-récompensée) brise la suite.
 */
function check_consecutive_legend_seasons(int $bruteId, int $n): bool
{
    $stmt = db()->prepare("
        SELECT sr.tier_code
        FROM season_rewards sr
        JOIN seasons s ON s.id = sr.season_id
        WHERE sr.brute_id = ?
        ORDER BY s.started_at DESC, s.id DESC
        LIMIT ?
    ");
    $stmt->bindValue(1, $bruteId, PDO::PARAM_INT);
    $stmt->bindValue(2, $n,       PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (count($rows) < $n) return false;
    foreach ($rows as $code) {
        if ($code !== 'legend') return false;
    }
    return true;
}

// ============================================================
// Titres de complétion — 100 % d'une catégorie de trophées
// ============================================================

const COMPLETION_TITLE_MAP = [
    'combat'      => 'compl_combat',
    'minigame'    => 'compl_minigame',
    'social'      => 'compl_social',
    'forge'       => 'compl_forge',
    'tournament'  => 'compl_tournament',
    'collection'  => 'compl_collection',
    'progression' => 'compl_progression',
];

/**
 * Vérifie si une brute a débloqué 100 % des trophées d'une catégorie.
 */
function is_category_complete(int $bruteId, string $category): bool
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM achievements WHERE category = ?');
    $stmt->execute([$category]);
    $total = (int)$stmt->fetchColumn();
    if ($total === 0) return false;

    $stmt = $pdo->prepare('
        SELECT COUNT(*) FROM brute_achievements ba
        JOIN achievements a ON a.code = ba.achievement_code
        WHERE ba.brute_id = ? AND a.category = ?
    ');
    $stmt->execute([$bruteId, $category]);
    return (int)$stmt->fetchColumn() >= $total;
}

/**
 * Vérifie toutes les catégories et octroie les titres de complétion mérités.
 * Appelé depuis achievement_engine.php après chaque trophée et depuis
 * check_and_award_titles() pour le rattrapage rétroactif.
 * Retourne la liste des codes nouvellement débloqués.
 */
function check_completion_titles(int $bruteId): array
{
    $newly = [];

    foreach (COMPLETION_TITLE_MAP as $category => $titleCode) {
        if (is_category_complete($bruteId, $category) && award_title($bruteId, $titleCode)) {
            $newly[] = $titleCode;
        }
    }

    // L'Omniscient : toutes les catégories à 100 %
    $allDone = true;
    foreach (array_keys(COMPLETION_TITLE_MAP) as $cat) {
        if (!is_category_complete($bruteId, $cat)) { $allDone = false; break; }
    }
    if ($allDone && award_title($bruteId, 'omniscient')) {
        $newly[] = 'omniscient';
    }

    return $newly;
}

// ============================================================
// Déclencheurs spécifiques — appelés depuis les flux concernés
// ============================================================

/**
 * Mise à jour de la série de victoires + détection du palier 20.
 * Appelé depuis api/start_fight.php après l'écriture du combat.
 */
function update_win_streak(int $bruteId, bool $won): int
{
    $pdo = db();
    if ($won) {
        $pdo->prepare('
            UPDATE brutes
            SET win_streak = win_streak + 1,
                win_streak_best = GREATEST(win_streak_best, win_streak + 1)
            WHERE id = ?
        ')->execute([$bruteId]);
    } else {
        $pdo->prepare('UPDATE brutes SET win_streak = 0 WHERE id = ?')->execute([$bruteId]);
    }
    $stmt = $pdo->prepare('SELECT win_streak FROM brutes WHERE id = ? LIMIT 1');
    $stmt->execute([$bruteId]);
    return (int)$stmt->fetchColumn();
}

/**
 * "Le Parfait" — détecte si la brute vient de gagner sans subir un seul
 * point de dégât. Inspecte le log fournis par run_fight().
 */
function check_perfect_title(int $bruteId, bool $won, array $log): bool
{
    if (!$won) return false;

    $stmt = db()->prepare('SELECT name FROM brutes WHERE id = ? LIMIT 1');
    $stmt->execute([$bruteId]);
    $name = (string)$stmt->fetchColumn();
    if ($name === '') return false;

    foreach ($log as $ev) {
        $type = (string)($ev['event'] ?? '');
        // Hits frontaux
        if ($type === 'hit') {
            $defender     = (string)($ev['defender']      ?? '');
            $defenderSlot = (string)($ev['defender_slot'] ?? '');
            if ($defender !== $name) continue;
            if (substr($defenderSlot, 1) !== '0') continue;
            if ((int)($ev['damage'] ?? 0) > 0) return false;
            continue;
        }
        // Dégâts de statut (saignement, poison) ou de météo (canicule)
        if ($type === 'status_tick' || $type === 'weather_tick') {
            $target     = (string)($ev['target']      ?? '');
            $targetSlot = (string)($ev['target_slot'] ?? '');
            if ($target !== $name) continue;
            if (substr($targetSlot, 1) !== '0') continue;
            if ((int)($ev['damage'] ?? 0) > 0) return false;
        }
    }
    return award_title($bruteId, 'parfait');
}

/**
 * "L'Intouchable" — appelé après la fin d'un tournoi pour le champion.
 * Vérifie qu'aucun de ses combats du tournoi ne lui a infligé de dégâts.
 */
function check_intouchable_title(int $bruteId, int $tournamentId): bool
{
    $pdo = db();

    $stmt = $pdo->prepare('SELECT name FROM brutes WHERE id = ? LIMIT 1');
    $stmt->execute([$bruteId]);
    $name = (string)$stmt->fetchColumn();
    if ($name === '') return false;

    // Récupère tous les combats du tournoi impliquant la brute
    $stmt = $pdo->prepare("
        SELECT f.log_json
        FROM tournament_fights tm
        JOIN fights f ON f.id = tm.fight_id
        WHERE tm.tournament_id = ?
          AND (tm.b1_id = ? OR tm.b2_id = ?)
    ");
    $stmt->execute([$tournamentId, $bruteId, $bruteId]);
    $logs = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (empty($logs)) return false;

    foreach ($logs as $logJson) {
        $log = json_decode((string)$logJson, true) ?: [];
        foreach ($log as $ev) {
            $type = (string)($ev['event'] ?? '');
            if ($type === 'hit') {
                if (($ev['defender'] ?? '') !== $name) continue;
                $slot = (string)($ev['defender_slot'] ?? '');
                if (substr($slot, 1) !== '0') continue;
                if ((int)($ev['damage'] ?? 0) > 0) return false;
            } elseif ($type === 'status_tick' || $type === 'weather_tick') {
                if (($ev['target'] ?? '') !== $name) continue;
                $slot = (string)($ev['target_slot'] ?? '');
                if (substr($slot, 1) !== '0') continue;
                if ((int)($ev['damage'] ?? 0) > 0) return false;
            }
        }
    }
    return award_title($bruteId, 'intouchable');
}
