<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';

function hof_query(string $sql, array $params = []): array {
    try {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

// ── 1. Légendes — niveau le plus élevé ─────────────────────────────────────
$legends = hof_query('
    SELECT b.id, b.name, b.level
    FROM brutes b
    JOIN users u ON u.id = b.user_id AND u.email NOT LIKE "bot%@arenaforge.local" AND u.email != "demo@arenaforge.local"
    WHERE b.level > 1
    ORDER BY b.level DESC, b.xp DESC
    LIMIT 10
');

// ── 2. Meurtriers — plus de victoires totales ───────────────────────────────
$killers = hof_query('
    SELECT b.id, b.name, b.level, COUNT(f.id) AS wins
    FROM brutes b
    JOIN users u ON u.id = b.user_id AND u.email NOT LIKE "bot%@arenaforge.local" AND u.email != "demo@arenaforge.local"
    JOIN fights f ON f.winner_id = b.id
    WHERE b.level > 1
    GROUP BY b.id
    ORDER BY wins DESC, b.level DESC
    LIMIT 10
');

// ── 3. Champions — plus de tournois gagnés ─────────────────────────────────
$champions = hof_query('
    SELECT b.id, b.name, b.level, COUNT(te.tournament_id) AS crowns
    FROM brutes b
    JOIN tournament_entries te ON te.brute_id = b.id AND te.placement = 1 AND te.is_ai = 0
    GROUP BY b.id
    ORDER BY crowns DESC, b.level DESC
    LIMIT 10
');

// ── 4. Pic de Gloire — meilleur MMR all-time ───────────────────────────────
$peakers = hof_query('
    SELECT b.id, b.name, b.level, COALESCE(b.peak_mmr, b.mmr) AS peak_mmr
    FROM brutes b
    JOIN users u ON u.id = b.user_id AND u.email NOT LIKE "bot%@arenaforge.local" AND u.email != "demo@arenaforge.local"
    WHERE b.level > 1
    ORDER BY peak_mmr DESC, b.level DESC
    LIMIT 10
');

// ── 5. Mentors — plus de pupilles formés ───────────────────────────────────
$mentors = hof_query('
    SELECT b.id, b.name, b.level, COUNT(p.pupil_id) AS pupils
    FROM brutes b
    JOIN users u ON u.id = b.user_id AND u.email NOT LIKE "bot%@arenaforge.local" AND u.email != "demo@arenaforge.local"
    JOIN pupils p ON p.master_id = b.id
    WHERE b.level > 1
    GROUP BY b.id
    ORDER BY pupils DESC, b.level DESC
    LIMIT 10
');

$me = current_brute();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Hall of Fame – ArenaForge</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" href="../assets/svg/logo/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="../assets/css/main.css">
</head>
<body>
<?php include __DIR__ . '/_nav.php'; ?>

<main class="wrap">

    <section class="card hof-hero">
        <h1 class="hof-title">⚡ Hall of Fame</h1>
        <p class="muted hof-subtitle">Les performances légendaires gravées pour l'éternité. Records all-time, toutes saisons confondues.</p>
    </section>

    <div class="hof-grid">

        <?php
        $sections = [
            ['icon' => '🏆', 'title' => 'Légendes de l\'Arène',   'desc' => 'Niveau le plus élevé atteint',         'rows' => $legends,   'value' => fn($r) => 'Niveau ' . (int)$r['level']],
            ['icon' => '⚔',  'title' => 'Meurtriers de l\'Arène', 'desc' => 'Plus grand nombre de victoires',        'rows' => $killers,   'value' => fn($r) => (int)$r['wins'] . ' victoires'],
            ['icon' => '👑',  'title' => 'Champions des Tournois', 'desc' => 'Plus grand nombre de couronnes',        'rows' => $champions, 'value' => fn($r) => (int)$r['crowns'] . ' couronne' . ((int)$r['crowns'] > 1 ? 's' : '')],
            ['icon' => '📈',  'title' => 'Pic de Gloire',          'desc' => 'Meilleur MMR jamais atteint',           'rows' => $peakers,   'value' => fn($r) => (int)$r['peak_mmr'] . ' MMR'],
            ['icon' => '👥',  'title' => 'Maîtres des Ombres',     'desc' => 'Plus grand nombre de pupilles formés', 'rows' => $mentors,   'value' => fn($r) => (int)$r['pupils'] . ' pupille' . ((int)$r['pupils'] > 1 ? 's' : '')],
        ];
        $medals = ['🥇', '🥈', '🥉'];
        foreach ($sections as $s):
        ?>
        <section class="card hof-card">
            <div class="hof-card-head">
                <span class="hof-icon"><?= $s['icon'] ?></span>
                <div>
                    <h2 class="hof-card-title"><?= h($s['title']) ?></h2>
                    <p class="hof-card-desc muted"><?= h($s['desc']) ?></p>
                </div>
            </div>
            <ol class="hof-list">
                <?php if (empty($s['rows'])): ?>
                    <li class="hof-empty">Aucun record pour l'instant</li>
                <?php else: ?>
                    <?php foreach ($s['rows'] as $i => $r): ?>
                    <li class="hof-entry <?= ($me && (int)$r['id'] === (int)$me['id']) ? 'hof-me' : '' ?> <?= $i < 3 ? 'hof-top-' . $i : '' ?>">
                        <span class="hof-rank"><?= $medals[$i] ?? ($i + 1) ?></span>
                        <a href="brute.php?id=<?= (int)$r['id'] ?>" class="hof-name"><?= h($r['name']) ?></a>
                        <span class="hof-value"><?= h(($s['value'])($r)) ?></span>
                    </li>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ol>
        </section>
        <?php endforeach; ?>

    </div>

</main>
</body>
</html>
