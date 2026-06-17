<?php
declare(strict_types=1);

// ============================================================
// Arbre de compétences — définition déclarative
// 3 branches × 4 nœuds = 25 points pour l'arbre complet.
// Chaque nœud a un coût en points, des prérequis, et un effect_type
// compatible avec le moteur de combat existant.
// ============================================================

const SKILL_TREE = [
    'conquerant' => [
        'label' => 'Conquérant',
        'icon'  => '⚔',
        'desc'  => 'Maximise tes dégâts et ta puissance offensive.',
        'color' => '#c84010',
        'nodes' => [
            'conq_t1_frappe'   => [
                'tier'         => 1,
                'name'         => 'Frappe Brute',
                'desc'         => '+15 % aux dégâts infligés.',
                'cost'         => 1,
                'effect_type'  => 'dmg_bonus_pct',
                'effect_value' => 15,
                'requires'     => [],
                'requires_any' => false,
                'icon'         => '⚔',
            ],
            'conq_t2_cri'      => [
                'tier'         => 2,
                'name'         => 'Cri de Guerre',
                'desc'         => '+20 % de dégâts supplémentaires quand tes PV passent sous 30 %.',
                'cost'         => 2,
                'effect_type'  => 'rage_pct',
                'effect_value' => 20,
                'requires'     => ['conq_t1_frappe'],
                'requires_any' => false,
                'icon'         => '💢',
            ],
            'conq_t2_instinct' => [
                'tier'         => 2,
                'name'         => 'Instinct de Tueur',
                'desc'         => '+10 % de chance de coup critique.',
                'cost'         => 2,
                'effect_type'  => 'crit_bonus_pct',
                'effect_value' => 10,
                'requires'     => ['conq_t1_frappe'],
                'requires_any' => false,
                'icon'         => '🎯',
            ],
            'conq_t3_frenzy'   => [
                'tier'         => 3,
                'name'         => 'Frénésie',
                'desc'         => 'Les deux bonus T2 sont amplifiés : Rage 40 % + Critique 20 %.',
                'cost'         => 4,
                'effect_type'  => 'frenzy',
                'effect_value' => 1,
                'requires'     => ['conq_t2_cri', 'conq_t2_instinct'],
                'requires_any' => false,
                'icon'         => '🔥',
            ],
            'conq_t4_titan'    => [
                'tier'         => 4,
                'name'         => 'Frappe Titanesque',
                'desc'         => 'Une fois par combat, quand tu passes sous 50 % PV, ton prochain coup inflige le double de dégâts.',
                'cost'         => 4,
                'effect_type'  => 'ult_double_dmg',
                'effect_value' => 1,
                'requires'     => ['conq_t3_frenzy'],
                'requires_any' => false,
                'icon'         => '💥',
            ],
        ],
    ],
    'rempart' => [
        'label' => 'Rempart',
        'icon'  => '🛡',
        'desc'  => 'Encaisse, récupère, refuse de tomber.',
        'color' => '#3f7d3b',
        'nodes' => [
            'ramp_t1_peau'   => [
                'tier'         => 1,
                'name'         => 'Peau de Fer',
                'desc'         => 'Réduit de 2 les dégâts reçus à chaque frappe.',
                'cost'         => 1,
                'effect_type'  => 'armor_flat',
                'effect_value' => 2,
                'requires'     => [],
                'requires_any' => false,
                'icon'         => '🛡',
            ],
            'ramp_t2_regen'  => [
                'tier'         => 2,
                'name'         => 'Régénération',
                'desc'         => 'Récupère 2 PV au début de chaque tour.',
                'cost'         => 2,
                'effect_type'  => 'regen_flat',
                'effect_value' => 2,
                'requires'     => ['ramp_t1_peau'],
                'requires_any' => false,
                'icon'         => '💚',
            ],
            'ramp_t2_vdv'    => [
                'tier'         => 2,
                'name'         => 'Vol de Vie',
                'desc'         => 'Récupère 25 % des dégâts que tu infliges.',
                'cost'         => 2,
                'effect_type'  => 'lifesteal_pct',
                'effect_value' => 25,
                'requires'     => ['ramp_t1_peau'],
                'requires_any' => false,
                'icon'         => '🩸',
            ],
            'ramp_t2_epines' => [
                'tier'         => 2,
                'name'         => 'Épines',
                'desc'         => 'Chaque frappe reçue renvoie 3 dégâts à l\'attaquant.',
                'cost'         => 2,
                'effect_type'  => 'thorns_flat',
                'effect_value' => 3,
                'requires'     => ['ramp_t1_peau'],
                'requires_any' => false,
                'icon'         => '🌵',
            ],
            'ramp_t3_revive' => [
                'tier'         => 3,
                'name'         => 'Seconde Vie',
                'desc'         => 'Une fois par combat, tu reviens à 30 % de tes PV max au lieu de mourir.',
                'cost'         => 3,
                'effect_type'  => 'ult_revive_pct',
                'effect_value' => 30,
                'requires'     => ['ramp_t2_regen', 'ramp_t2_vdv', 'ramp_t2_epines'],
                'requires_any' => true,
                'icon'         => '✨',
            ],
            'ramp_t4_warshout' => [
                'tier'         => 4,
                'name'         => 'Cri de Guerre',
                'desc'         => 'Une fois par combat, le premier coup critique reçu déclenche un soin de 20 % de tes PV max.',
                'cost'         => 3,
                'effect_type'  => 'ult_heal_pct',
                'effect_value' => 20,
                'requires'     => ['ramp_t3_revive'],
                'requires_any' => false,
                'icon'         => '🛡️',
            ],
        ],
    ],
    'duelliste' => [
        'label' => 'Duelliste',
        'icon'  => '⚡',
        'desc'  => 'Danse avec la mort — esquive et frappe en retour.',
        'color' => '#5b8aa0',
        'nodes' => [
            'duel_t1_esquive' => [
                'tier'         => 1,
                'name'         => 'Esquive',
                'desc'         => '+10 % de chance d\'esquiver une attaque.',
                'cost'         => 1,
                'effect_type'  => 'dodge_pct',
                'effect_value' => 10,
                'requires'     => [],
                'requires_any' => false,
                'icon'         => '💨',
            ],
            'duel_t2_riposte' => [
                'tier'         => 2,
                'name'         => 'Riposte',
                'desc'         => '20 % de chance de contre-attaquer après une esquive.',
                'cost'         => 2,
                'effect_type'  => 'counter_pct',
                'effect_value' => 20,
                'requires'     => ['duel_t1_esquive'],
                'requires_any' => false,
                'icon'         => '⚡',
            ],
            'duel_t2_ombre'   => [
                'tier'         => 2,
                'name'         => 'Ombre Rapide',
                'desc'         => '+10 % d\'esquive supplémentaire (total 20 %).',
                'cost'         => 2,
                'effect_type'  => 'dodge_pct',
                'effect_value' => 10,
                'requires'     => ['duel_t1_esquive'],
                'requires_any' => false,
                'icon'         => '🌑',
            ],
            'duel_t3_lame'    => [
                'tier'         => 3,
                'name'         => 'Lame-Fantôme',
                'desc'         => 'Toute esquive déclenche une contre-attaque garantie (100 %).',
                'cost'         => 3,
                'effect_type'  => 'phantom_blade',
                'effect_value' => 1,
                'requires'     => ['duel_t2_riposte'],
                'requires_any' => false,
                'icon'         => '👻',
            ],
            'duel_t4_tourbillon' => [
                'tier'         => 4,
                'name'         => 'Tourbillon',
                'desc'         => '35 % de chance de porter un coup supplémentaire à chaque attaque.',
                'cost'         => 3,
                'effect_type'  => 'double_strike_pct',
                'effect_value' => 35,
                'requires'     => ['duel_t3_lame'],
                'requires_any' => false,
                'icon'         => '🌀',
            ],
        ],
    ],
];

// ============================================================
// Migration automatique
// ============================================================

function skill_tree_ensure_schema(): void
{
    static $done = false;
    if ($done) return;
    $pdo = db();

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS brute_skill_nodes (
            brute_id    INT NOT NULL,
            node_id     VARCHAR(32) NOT NULL,
            unlocked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (brute_id, node_id),
            KEY idx_brute_skill (brute_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $col = $pdo->query("SHOW COLUMNS FROM brutes LIKE 'skill_points'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE brutes ADD COLUMN skill_points INT NOT NULL DEFAULT 0");
        // Brutes existantes : skill_points = level (migration rétroactive)
        $pdo->exec("UPDATE brutes SET skill_points = level");
    }

    $done = true;
}

// ============================================================
// Helpers
// ============================================================

function skill_tree_get_node(string $nodeId): ?array
{
    foreach (SKILL_TREE as $branch) {
        if (isset($branch['nodes'][$nodeId])) {
            return $branch['nodes'][$nodeId];
        }
    }
    return null;
}

function skill_tree_nodes_for_brute(int $bruteId): array
{
    skill_tree_ensure_schema();
    $stmt = db()->prepare('SELECT node_id FROM brute_skill_nodes WHERE brute_id = ?');
    $stmt->execute([$bruteId]);
    return $stmt->fetchAll(\PDO::FETCH_COLUMN);
}

function skill_tree_can_unlock(int $bruteId, string $nodeId, array $unlocked): bool
{
    $node = skill_tree_get_node($nodeId);
    if (!$node) return false;
    if (in_array($nodeId, $unlocked, true)) return false;

    if (empty($node['requires'])) return true;

    if ($node['requires_any']) {
        foreach ($node['requires'] as $req) {
            if (in_array($req, $unlocked, true)) return true;
        }
        return false;
    }

    foreach ($node['requires'] as $req) {
        if (!in_array($req, $unlocked, true)) return false;
    }
    return true;
}

/**
 * Retourne le tableau de skills compatible avec le moteur de combat.
 * Format : [['effect_type' => '...', 'effect_value' => N, 'name' => '...'], ...]
 */
function skill_tree_skills_array(int $bruteId): array
{
    $unlockedNodes = skill_tree_nodes_for_brute($bruteId);
    if (empty($unlockedNodes)) return [];

    $effectMap = [];

    foreach ($unlockedNodes as $nodeId) {
        $node = skill_tree_get_node($nodeId);
        if (!$node) continue;
        $et = $node['effect_type'];
        $ev = (int)$node['effect_value'];

        if ($et === 'frenzy' || $et === 'phantom_blade') {
            $effectMap[$et] = $ev;
            continue;
        }
        $effectMap[$et] = ($effectMap[$et] ?? 0) + $ev;
    }

    // Frénésie : amplifie les T2 Conquérant
    if (isset($effectMap['frenzy'])) {
        unset($effectMap['frenzy']);
        $effectMap['rage_pct']       = 40;
        $effectMap['crit_bonus_pct'] = 20;
    }

    // Lame-Fantôme : contre garanti (100 %)
    if (isset($effectMap['phantom_blade'])) {
        unset($effectMap['phantom_blade']);
        $effectMap['counter_pct'] = 100;
    }

    $skills = [];
    foreach ($effectMap as $effectType => $effectValue) {
        $skills[] = ['effect_type' => $effectType, 'effect_value' => $effectValue, 'name' => $effectType];
    }
    return $skills;
}
