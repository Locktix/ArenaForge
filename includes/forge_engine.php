<?php
// Moteur de la forge : améliorations d'armes + armures
//
// Règles :
// - Upgrades d'armes : 0..5 niveaux. Coût croissant (10, 25, 50, 100, 200 fragments pour L1..L5).
//   Chaque niveau = +10% dégâts (appliqué dans combat_engine.php).
// - Armures : 3 tiers (cuir / bronze / plates) × 2 slots (tête / corps). Achat one-shot.
//   Chaque armure procure hp_bonus + damage_reduction. Max 1 équipée par slot.

declare(strict_types=1);

require_once __DIR__ . '/db.php';

const FORGE_WEAPON_MAX_UPGRADE = 5;
const FORGE_UPGRADE_COSTS = [
    1 => 10,
    2 => 25,
    3 => 50,
    4 => 100,
    5 => 200,
];

function get_brute_weapons_with_upgrades(int $bruteId): array
{
    $stmt = db()->prepare('
        SELECT w.*, COALESCE(bwu.upgrade_level, 0) AS upgrade_level
        FROM weapons w
        JOIN brute_weapons bw ON bw.weapon_id = w.id
        LEFT JOIN brute_weapon_upgrades bwu
          ON bwu.weapon_id = w.id AND bwu.brute_id = bw.brute_id
        WHERE bw.brute_id = ?
        ORDER BY (w.damage_min + w.damage_max) DESC
    ');
    $stmt->execute([$bruteId]);
    return $stmt->fetchAll();
}

/**
 * Tous les modèles d'armures + état pour la brute (possédée, équipée, prix).
 */
function get_armors_for_brute(int $bruteId): array
{
    $stmt = db()->prepare('
        SELECT a.*,
               COALESCE(ba.brute_id IS NOT NULL, 0) AS owned,
               COALESCE(ba.equipped, 0) AS equipped
        FROM armors a
        LEFT JOIN brute_armors ba
          ON ba.armor_id = a.id AND ba.brute_id = ?
        ORDER BY a.tier ASC, a.slot ASC
    ');
    $stmt->execute([$bruteId]);
    return $stmt->fetchAll();
}

function upgrade_cost(int $currentLevel): ?int
{
    $next = $currentLevel + 1;
    return FORGE_UPGRADE_COSTS[$next] ?? null;
}

/**
 * Améliore une arme possédée. Retourne ['ok' => true, ...] ou ['ok' => false, 'error' => ...].
 */
function forge_upgrade_weapon(int $bruteId, int $weaponId): array
{
    $pdo = db();

    $stmt = $pdo->prepare('
        SELECT COALESCE(bwu.upgrade_level, 0) AS upgrade_level
        FROM brute_weapons bw
        LEFT JOIN brute_weapon_upgrades bwu
          ON bwu.brute_id = bw.brute_id AND bwu.weapon_id = bw.weapon_id
        WHERE bw.brute_id = ? AND bw.weapon_id = ?
        LIMIT 1
    ');
    $stmt->execute([$bruteId, $weaponId]);
    $row = $stmt->fetch();
    if ($row === false) {
        return ['ok' => false, 'error' => 'Tu ne possèdes pas cette arme'];
    }

    $currentLevel = (int)$row['upgrade_level'];
    if ($currentLevel >= FORGE_WEAPON_MAX_UPGRADE) {
        return ['ok' => false, 'error' => 'Amélioration maximale atteinte'];
    }

    $cost = upgrade_cost($currentLevel);
    if ($cost === null) {
        return ['ok' => false, 'error' => 'Niveau inconnu'];
    }

    $stmt = $pdo->prepare('SELECT fragments FROM brutes WHERE id = ? LIMIT 1');
    $stmt->execute([$bruteId]);
    $fragments = (int)$stmt->fetchColumn();

    if ($fragments < $cost) {
        return ['ok' => false, 'error' => "Fragments insuffisants ($fragments / $cost)"];
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE brutes SET fragments = fragments - ? WHERE id = ?')
            ->execute([$cost, $bruteId]);
        $pdo->prepare('
            INSERT INTO brute_weapon_upgrades (brute_id, weapon_id, upgrade_level)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE upgrade_level = VALUES(upgrade_level)
        ')->execute([$bruteId, $weaponId, $currentLevel + 1]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['ok' => false, 'error' => 'Erreur forge'];
    }

    return [
        'ok'          => true,
        'new_level'   => $currentLevel + 1,
        'cost'        => $cost,
        'remaining'   => $fragments - $cost,
    ];
}

/**
 * Achète une armure. La brute la possède ensuite (non équipée par défaut).
 */
function forge_buy_armor(int $bruteId, int $armorId): array
{
    $pdo = db();

    $stmt = $pdo->prepare('SELECT * FROM armors WHERE id = ? LIMIT 1');
    $stmt->execute([$armorId]);
    $armor = $stmt->fetch();
    if (!$armor) return ['ok' => false, 'error' => 'Armure inconnue'];

    $stmt = $pdo->prepare('SELECT 1 FROM brute_armors WHERE brute_id = ? AND armor_id = ? LIMIT 1');
    $stmt->execute([$bruteId, $armorId]);
    if ($stmt->fetchColumn()) return ['ok' => false, 'error' => 'Armure déjà possédée'];

    $cost = (int)$armor['cost_fragments'];
    $stmt = $pdo->prepare('SELECT fragments FROM brutes WHERE id = ? LIMIT 1');
    $stmt->execute([$bruteId]);
    $fragments = (int)$stmt->fetchColumn();
    if ($fragments < $cost) {
        return ['ok' => false, 'error' => "Fragments insuffisants ($fragments / $cost)"];
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE brutes SET fragments = fragments - ? WHERE id = ?')
            ->execute([$cost, $bruteId]);
        $pdo->prepare('INSERT INTO brute_armors (brute_id, armor_id, equipped) VALUES (?, ?, 0)')
            ->execute([$bruteId, $armorId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['ok' => false, 'error' => 'Erreur achat'];
    }

    return ['ok' => true, 'cost' => $cost, 'remaining' => $fragments - $cost];
}

/**
 * Équipe / déséquipe une armure. Pour équiper, déséquipe d'abord toute autre
 * armure du même slot.
 */
function forge_toggle_equip(int $bruteId, int $armorId): array
{
    $pdo = db();

    $stmt = $pdo->prepare('
        SELECT a.slot, ba.equipped
        FROM armors a
        JOIN brute_armors ba ON ba.armor_id = a.id AND ba.brute_id = ?
        WHERE a.id = ?
        LIMIT 1
    ');
    $stmt->execute([$bruteId, $armorId]);
    $row = $stmt->fetch();
    if (!$row) return ['ok' => false, 'error' => 'Armure non possédée'];

    $newState = (int)$row['equipped'] === 1 ? 0 : 1;
    $slot     = (string)$row['slot'];

    $pdo->beginTransaction();
    try {
        if ($newState === 1) {
            // Déséquiper toutes les autres armures du même slot
            $pdo->prepare('
                UPDATE brute_armors ba
                JOIN armors a ON a.id = ba.armor_id
                SET ba.equipped = 0
                WHERE ba.brute_id = ? AND a.slot = ?
            ')->execute([$bruteId, $slot]);
        }
        $pdo->prepare('UPDATE brute_armors SET equipped = ? WHERE brute_id = ? AND armor_id = ?')
            ->execute([$newState, $bruteId, $armorId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['ok' => false, 'error' => 'Erreur équipement'];
    }

    return ['ok' => true, 'equipped' => $newState === 1];
}
