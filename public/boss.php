<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/boss_engine.php';
require_login();

$brute = current_brute();
if (!$brute) {
    header('Location: dashboard.php');
    exit;
}

$bruteId = (int)$brute['id'];
$boss    = ensure_today_boss();
$attempt = $boss ? get_boss_attempt((int)$boss['id'], $bruteId) : null;
$leaderboard = $boss ? get_boss_leaderboard((int)$boss['id'], 15) : [];
$csrf = csrf_token();

$bossWeaponName = '';
$bossSkillName  = '';
if ($boss) {
    if (!empty($boss['weapon_id'])) {
        $stmt = db()->prepare('SELECT name FROM weapons WHERE id = ? LIMIT 1');
        $stmt->execute([(int)$boss['weapon_id']]);
        $bossWeaponName = (string)$stmt->fetchColumn();
    }
    if (!empty($boss['skill_id'])) {
        $stmt = db()->prepare('SELECT name FROM skills WHERE id = ? LIMIT 1');
        $stmt->execute([(int)$boss['skill_id']]);
        $bossSkillName = (string)$stmt->fetchColumn();
    }
}

$appearance = $boss ? (json_decode((string)$boss['appearance_seed'], true) ?: []) : [];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Boss du jour — ArenaForge</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" href="../assets/svg/logo/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="../assets/css/main.css">
</head>
<body>
<?php include __DIR__ . '/_nav.php'; ?>

<main class="wrap">
    <?php if (!$boss): ?>
        <section class="card">
            <h1>Boss du jour</h1>
            <p class="muted">Aucun boss disponible pour le moment.</p>
        </section>
    <?php else: ?>
        <section class="card boss-card">
            <div class="boss-portrait">
                <?php include __DIR__ . '/_gladiator.php'; ?>
                <div class="boss-aura"></div>
            </div>
            <div class="boss-info">
                <span class="boss-tag">Boss du <?= h(date('d/m', strtotime((string)$boss['boss_date']))) ?></span>
                <h1><?= h((string)$boss['name']) ?> <span class="level boss-level">Niv. <?= (int)$boss['level'] ?></span></h1>
                <p class="muted"><?= h((string)$boss['description']) ?></p>

                <ul class="stats boss-stats">
                    <li><span>PV</span><strong><?= (int)$boss['hp_max'] ?></strong></li>
                    <li><span>Force</span><strong><?= (int)$boss['strength'] ?></strong></li>
                    <li><span>Agilité</span><strong><?= (int)$boss['agility'] ?></strong></li>
                    <li><span>Endurance</span><strong><?= (int)$boss['endurance'] ?></strong></li>
                </ul>

                <p class="muted small">
                    Arme : <strong><?= h($bossWeaponName ?: '—') ?></strong>
                    · Compétence : <strong><?= h($bossSkillName ?: '—') ?></strong>
                </p>

                <?php if ($attempt): ?>
                    <div class="boss-result">
                        <p>
                            <strong>
                                <?= (int)$attempt['won'] === 1 ? '🏆 Victoire !' : '💀 Défaite' ?>
                            </strong>
                            — <?= (int)$attempt['damage_dealt'] ?> dégâts infligés en <?= (int)$attempt['rounds'] ?> tours.
                        </p>
                        <a class="btn btn-secondary" href="fight.php?id=<?= (int)$attempt['fight_id'] ?>">Revoir le combat</a>
                    </div>
                <?php else: ?>
                    <form id="boss-attempt-form">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="brute_id" value="<?= $bruteId ?>">
                        <button class="btn btn-primary btn-large" type="submit">⚔ Affronter le boss (1/jour)</button>
                        <p class="form-msg" data-msg></p>
                    </form>
                    <p class="muted small">Une seule tentative par jour. Récompense en or proportionnelle aux dégâts infligés.</p>
                <?php endif; ?>
            </div>
        </section>

        <section class="card">
            <h2>Tableau de chasse</h2>
            <?php if (empty($leaderboard)): ?>
                <p class="muted">Personne n'a encore frappé le boss aujourd'hui. À toi de l'inaugurer.</p>
            <?php else: ?>
                <table class="ranking boss-leaderboard">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Gladiateur</th>
                            <th>Niv.</th>
                            <th>Dégâts</th>
                            <th>Tours</th>
                            <th>Issue</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($leaderboard as $i => $row): ?>
                            <tr<?= (int)$row['brute_id'] === $bruteId ? ' class="rank-me"' : '' ?>>
                                <td><?= $i + 1 ?></td>
                                <td><a href="brute.php?id=<?= (int)$row['brute_id'] ?>"><?= h((string)$row['name']) ?></a></td>
                                <td><?= (int)$row['level'] ?></td>
                                <td class="ranking-mmr"><?= (int)$row['damage_dealt'] ?></td>
                                <td><?= (int)$row['rounds'] ?></td>
                                <td><?= (int)$row['won'] === 1 ? '🏆' : '☠' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</main>

<script>
const bossForm = document.getElementById('boss-attempt-form');
if (bossForm) {
    bossForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const msg = bossForm.querySelector('[data-msg]');
        if (msg) { msg.className = 'form-msg'; msg.textContent = '…'; }
        try {
            const res = await fetch('../api/boss_attempt.php', { method: 'POST', body: new FormData(bossForm) });
            const data = await res.json();
            if (data.ok && data.redirect) {
                window.location.href = data.redirect;
            } else if (msg) {
                msg.className = 'form-msg error';
                msg.textContent = data.error || 'Erreur';
            }
        } catch (err) {
            if (msg) { msg.className = 'form-msg error'; msg.textContent = 'Erreur réseau'; }
        }
    });
}
</script>
</body>
</html>
