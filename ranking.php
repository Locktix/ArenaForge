<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_login();

$rows = db()->query('
    SELECT b.id, b.name, b.level, b.xp,
           (SELECT COUNT(*) FROM fights f WHERE f.winner_id = b.id) AS wins,
           (SELECT COUNT(*) FROM fights f WHERE (f.brute1_id = b.id OR f.brute2_id = b.id) AND f.winner_id != b.id) AS losses
    FROM brutes b
    ORDER BY b.level DESC, b.xp DESC
    LIMIT 50
')->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Classement – ArenaForge</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" href="assets/svg/logo/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="assets/css/main.css">
</head>
<body>
<?php include __DIR__ . '/_nav.php'; ?>

<main class="wrap">
    <section class="card">
        <h1>Classement global</h1>
        <table class="ranking">
            <thead>
                <tr><th>#</th><th>Gladiateur</th><th>Niv.</th><th>XP</th><th>Victoires</th><th>Défaites</th></tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $i => $r): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td><a href="brute.php?id=<?= (int)$r['id'] ?>"><?= h($r['name']) ?></a></td>
                        <td><?= (int)$r['level'] ?></td>
                        <td><?= (int)$r['xp'] ?></td>
                        <td><?= (int)$r['wins'] ?></td>
                        <td><?= (int)$r['losses'] ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>
</main>
</body>
</html>
