<?php
// Évolution des pets — chaque combat partagé incrémente combat_count.
// À 50 combats, le pet de base évolue en sa version aguerrie.

declare(strict_types=1);

require_once __DIR__ . '/db.php';

const PET_EVOLUTION_THRESHOLD = 50;

// Mapping : nom de pet de base → nom de pet évolué
const PET_EVOLUTION_MAP = [
    'Chien'    => 'Molosse',
    'Loup'     => 'Loup alpha',
    'Panthere' => 'Sphinx',
    'Ours'     => 'Ours-roi',
];

/**
 * Incrémente le compteur de combats pour tous les pets de la brute.
 * Si un pet atteint le seuil et a une forme évoluée → swap.
 *
 * Retourne la liste des évolutions déclenchées (pour toast côté client).
 */
function track_pet_combat(int $bruteId): array
{
    $pdo = db();

    $stmt = $pdo->prepare('
        UPDATE brute_pets
        SET combat_count = combat_count + 1
        WHERE brute_id = ?
    ');
    $stmt->execute([$bruteId]);

    return try_evolve_pets($bruteId);
}

function try_evolve_pets(int $bruteId): array
{
    $pdo = db();
    $evolutions = [];

    $stmt = $pdo->prepare('
        SELECT bp.pet_id, bp.combat_count, p.name AS base_name, p.icon_path
        FROM brute_pets bp
        JOIN pets p ON p.id = bp.pet_id
        WHERE bp.brute_id = ?
          AND bp.combat_count >= ?
          AND bp.evolved_from_pet_id IS NULL
          AND p.name IN ("Chien", "Loup", "Panthere", "Ours")
    ');
    $stmt->execute([$bruteId, PET_EVOLUTION_THRESHOLD]);
    $candidates = $stmt->fetchAll();

    foreach ($candidates as $c) {
        $evolvedName = PET_EVOLUTION_MAP[$c['base_name']] ?? null;
        if (!$evolvedName) continue;

        $s = $pdo->prepare('SELECT id, name, icon_path FROM pets WHERE name = ? LIMIT 1');
        $s->execute([$evolvedName]);
        $evolved = $s->fetch();
        if (!$evolved) continue;

        // Swap : on remplace pet_id et on garde la mémoire de l'ancien
        $pdo->prepare('
            UPDATE brute_pets
            SET pet_id = ?, evolved_from_pet_id = ?
            WHERE brute_id = ? AND pet_id = ?
        ')->execute([(int)$evolved['id'], (int)$c['pet_id'], $bruteId, (int)$c['pet_id']]);

        $evolutions[] = [
            'from'      => $c['base_name'],
            'to'        => $evolved['name'],
            'icon_path' => $evolved['icon_path'],
        ];
    }

    return $evolutions;
}
