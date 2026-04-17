<?php
// Utilitaires pour l'arbre maître/pupilles

declare(strict_types=1);

require_once __DIR__ . '/db.php';

// Remonte la chaîne des maîtres (au plus 3 niveaux au-dessus)
function get_ancestors(int $bruteId): array
{
    $ancestors = [];
    $current   = $bruteId;
    $pdo       = db();
    for ($i = 0; $i < 3; $i++) {
        $stmt = $pdo->prepare('SELECT id, name, level, master_id FROM brutes WHERE id = ? LIMIT 1');
        $stmt->execute([$current]);
        $b = $stmt->fetch();
        if (!$b || !$b['master_id']) {
            break;
        }
        $stmt = $pdo->prepare('SELECT id, name, level FROM brutes WHERE id = ? LIMIT 1');
        $stmt->execute([(int)$b['master_id']]);
        $master = $stmt->fetch();
        if (!$master) {
            break;
        }
        $ancestors[] = $master;
        $current = (int)$master['id'];
    }
    return $ancestors;
}

// Récupère les descendants (pupilles et leurs pupilles jusqu'à 2 niveaux)
function get_descendants(int $bruteId): array
{
    $pdo = db();

    $stmt = $pdo->prepare('
        SELECT b.id, b.name, b.level
        FROM pupils p
        JOIN brutes b ON b.id = p.pupil_id
        WHERE p.master_id = ?
        ORDER BY b.level DESC, b.name ASC
    ');
    $stmt->execute([$bruteId]);
    $pupils = $stmt->fetchAll();

    foreach ($pupils as &$p) {
        $sub = $pdo->prepare('
            SELECT b.id, b.name, b.level
            FROM pupils pp
            JOIN brutes b ON b.id = pp.pupil_id
            WHERE pp.master_id = ?
            ORDER BY b.level DESC, b.name ASC
        ');
        $sub->execute([(int)$p['id']]);
        $p['grand_pupils'] = $sub->fetchAll();
    }
    unset($p);

    return $pupils;
}

// Stats globales : combien de pupilles, combien d'XP passif cumulé
function pupil_stats(int $bruteId): array
{
    $pdo = db();

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM pupils WHERE master_id = ?');
    $stmt->execute([$bruteId]);
    $directCount = (int)$stmt->fetchColumn();

    // Compte de tous les combats gagnés/perdus par les pupilles directs (1 XP par combat)
    $stmt = $pdo->prepare('
        SELECT COUNT(*) FROM fights f
        JOIN pupils p ON (p.pupil_id = f.brute1_id)
        WHERE p.master_id = ? AND f.context = "arena"
    ');
    $stmt->execute([$bruteId]);
    $xpEarned = (int)$stmt->fetchColumn();

    return [
        'direct_count' => $directCount,
        'xp_earned'    => $xpEarned,
    ];
}
