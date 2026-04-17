<?php
// Génération procédurale d'un gladiateur à partir d'un seed (nom)

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function seed_from_name(string $name): int
{
    return (int)(hexdec(substr(md5(strtolower(trim($name))), 0, 8)) & 0x7FFFFFFF);
}

function seeded_int(int &$seed, int $min, int $max): int
{
    // Générateur LCG simple et déterministe
    $seed = ($seed * 1103515245 + 12345) & 0x7FFFFFFF;
    return $min + ($seed % ($max - $min + 1));
}

function generate_appearance(string $name): array
{
    $seed = seed_from_name($name);
    return [
        'body'    => seeded_int($seed, 0, 2),   // 0=fin, 1=normal, 2=costaud
        'hair'    => seeded_int($seed, 0, 3),   // 4 coiffures
        'skin'    => seeded_int($seed, 0, 2),   // 3 teintes
        'color'   => seeded_int($seed, 0, 359), // teinte de tunique
    ];
}

function generate_stats(string $name): array
{
    $seed = seed_from_name($name) ^ 0xA5A5A5;
    $hp       = seeded_int($seed, 45, 60);
    $str      = seeded_int($seed, 4, 8);
    $agi      = seeded_int($seed, 4, 8);
    $end      = seeded_int($seed, 4, 8);
    return [
        'hp_max'    => $hp,
        'strength'  => $str,
        'agility'   => $agi,
        'endurance' => $end,
    ];
}

function create_brute(int $userId, string $name, ?int $masterId = null): int
{
    $appearance = generate_appearance($name);
    $stats      = generate_stats($name);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('
            INSERT INTO brutes
              (user_id, name, level, xp, hp_max, strength, agility, endurance, appearance_seed, master_id)
            VALUES (?, ?, 1, 0, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $userId,
            $name,
            $stats['hp_max'],
            $stats['strength'],
            $stats['agility'],
            $stats['endurance'],
            json_encode($appearance, JSON_UNESCAPED_UNICODE),
            $masterId,
        ]);
        $bruteId = (int)$pdo->lastInsertId();

        // Arme de départ : poings nus
        $w = $pdo->query("SELECT id FROM weapons WHERE name = 'Poings nus' LIMIT 1")->fetch();
        if ($w) {
            $pdo->prepare('INSERT INTO brute_weapons (brute_id, weapon_id) VALUES (?, ?)')
                ->execute([$bruteId, (int)$w['id']]);
        }

        // Lien de parrainage
        if ($masterId !== null) {
            $pdo->prepare('INSERT IGNORE INTO pupils (master_id, pupil_id) VALUES (?, ?)')
                ->execute([$masterId, $bruteId]);
        }

        $pdo->commit();
        return $bruteId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function xp_for_level(int $level): int
{
    // Palier cumulatif nécessaire pour atteindre ce niveau
    return (int)(10 * $level * ($level + 1) / 2);
}

function level_up_bonuses_pool(): array
{
    // Pool de bonus possibles au level up
    return [
        ['type' => 'stat',   'key' => 'hp_max',    'value' => 5, 'label' => '+5 PV max'],
        ['type' => 'stat',   'key' => 'strength',  'value' => 1, 'label' => '+1 Force'],
        ['type' => 'stat',   'key' => 'agility',   'value' => 1, 'label' => '+1 Agilité'],
        ['type' => 'stat',   'key' => 'endurance', 'value' => 1, 'label' => '+1 Endurance'],
        ['type' => 'weapon'],
        ['type' => 'skill'],
    ];
}
