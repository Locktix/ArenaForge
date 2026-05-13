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
            <h1>Antre du Boss</h1>
            <p class="muted">Le mal sommeille... Aucune menace n'a été détectée aujourd'hui.</p>
        </section>
    <?php else: ?>
        <section class="card boss-card">
            <div class="boss-portrait">
                <?php include __DIR__ . '/_gladiator.php'; ?>
                <div class="boss-aura"></div>
            </div>
            <div class="boss-info">
                <span class="boss-tag">⚠️ Menace Émergente</span>
                <h1><?= h((string)$boss['name']) ?> <span class="level boss-level">Niv. <?= (int)$boss['level'] ?></span></h1>
                <p class="muted italic">"<?= h((string)$boss['description']) ?>"</p>

                <div class="hero-stats boss-stats">
                    <div class="stat-box">
                        <span class="stat-label">Santé</span>
                        <span class="stat-value"><?= (int)$boss['hp_max'] ?></span>
                    </div>
                    <div class="stat-box">
                        <span class="stat-label">Force</span>
                        <span class="stat-value"><?= (int)$boss['strength'] ?></span>
                    </div>
                    <div class="stat-box">
                        <span class="stat-label">Agilité</span>
                        <span class="stat-value"><?= (int)$boss['agility'] ?></span>
                    </div>
                    <div class="stat-box">
                        <span class="stat-label">Endurance</span>
                        <span class="stat-value"><?= (int)$boss['endurance'] ?></span>
                    </div>
                </div>

                <div class="hero-weapon">
                    <div class="weapon-info">
                        <span class="weapon-name">⚔ <?= h($bossWeaponName ?: 'Poings nus') ?></span>
                        <span class="weapon-damage">⚡ <?= h($bossSkillName ?: 'Aucune compétence') ?></span>
                    </div>
                </div>

                <?php if ($attempt): ?>
                    <div class="boss-result">
                        <p>
                            <strong class="<?= (int)$attempt['won'] === 1 ? 'color-success' : 'color-danger' ?>">
                                <?= (int)$attempt['won'] === 1 ? '🏆 VICTOIRE ÉPIQUE !' : '💀 VOUS AVEZ PÉRI' ?>
                            </strong><br>
                            <span class="muted"><?= (int)$attempt['damage_dealt'] ?> points de dégâts infligés au monstre.</span>
                        </p>
                        <a class="btn btn-secondary" href="fight.php?id=<?= (int)$attempt['fight_id'] ?>">Voir le replay de la bataille</a>
                    </div>
                <?php else: ?>
                    <form id="boss-attempt-form" style="margin-top: 20px;">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="brute_id" value="<?= $bruteId ?>">
                        <button class="btn btn-primary btn-large btn-hero" type="submit">⚔ ENTAMER LE SIÈGE</button>
                        <p class="form-msg" data-msg></p>
                    </form>
                    <p class="muted small text-center">Tentative unique. L'or de la cité sera distribué selon votre bravoure.</p>
                <?php endif; ?>
            </div>
        </section>

        <section class="card">
            <h2>🏆 Tableau de chasse</h2>
            <?php if (empty($leaderboard)): ?>
                <p class="muted">Personne n'a encore frappé le boss aujourd'hui. À toi de l'inaugurer.</p>
            <?php else: ?>
                <div class="ranking-board">
                    <?php foreach ($leaderboard as $i => $row): 
                        $isMe = ((int)$row['brute_id'] === $bruteId);
                    ?>
                        <div class="rank-plaque <?= $isMe ? 'rank-me' : '' ?>">
                            <div class="rank-num"><?= $i + 1 ?></div>
                            <div class="rank-identity">
                                <a href="brute.php?id=<?= (int)$row['brute_id'] ?>" class="rank-name"><?= h((string)$row['name']) ?></a>
                                <div class="rank-meta">
                                    <span class="muted small">Niveau <?= (int)$row['level'] ?></span>
                                    <span class="muted small"><?= (int)$row['won'] === 1 ? '🏆 A triomphé' : '💀 A succombé' ?></span>
                                </div>
                            </div>
                            <div class="rank-stats">
                                <div class="rank-stat-item">
                                    <label>Tours</label>
                                    <span><?= (int)$row['rounds'] ?></span>
                                </div>
                                <div class="rank-stat-item">
                                    <label>Dégâts</label>
                                    <span class="ranking-mmr"><?= (int)$row['damage_dealt'] ?></span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
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
