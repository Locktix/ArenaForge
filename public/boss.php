<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/boss_engine.php';
require_once __DIR__ . '/../includes/brute_generator.php';
require_login();

$brute   = current_brute();
if (!$brute) { header('Location: dashboard.php'); exit; }
$bruteId = (int)$brute['id'];

// Distribuer les XP journaliers au boss si nouvelle journée
maybe_award_boss_daily_xp();

$boss        = get_pvp_boss();
$history     = get_pvp_boss_history(5);
$challengers = get_pvp_boss_challengers();
$csrf        = csrf_token();

$isBoss        = $boss && ((int)$boss['brute_id'] === $bruteId);
$throneVacant  = ($boss === null);
$canChallenge  = !$throneVacant && !$isBoss && ($brute['boss_last_challenge_date'] ?? '') !== date('Y-m-d');
$alreadyTriedToday = !$throneVacant && !$isBoss && ($brute['boss_last_challenge_date'] ?? '') === date('Y-m-d');

// Données portrait boss
$bossAppearance = [];
if ($boss) {
    $bossAppearance = json_decode((string)$boss['appearance_seed'], true) ?: [];
}

// Durée de règne
$reignDays = 0;
if ($boss) {
    $since     = new DateTimeImmutable($boss['since_date']);
    $now       = new DateTimeImmutable();
    $reignDays = max(0, (int)$since->diff($now)->days);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Antre du Trône — ArenaForge</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" href="../assets/svg/logo/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="../assets/css/main.css">
</head>
<body>
<?php include __DIR__ . '/_nav.php'; ?>

<main class="wrap">

    <?php if ($throneVacant): ?>
    <!-- Trône vacant -->
    <section class="card boss-vacant-card text-center">
        <div class="boss-vacant-icon">👑</div>
        <h1>Le Trône est Vacant</h1>
        <p class="muted">Aucun roi ne règne. Le premier gladiateur à revendiquer ce trône en deviendra le Maître.</p>
        <?php if ((int)$brute['pending_levelup'] !== 1): ?>
        <form id="claim-form" style="margin-top:24px">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="brute_id" value="<?= $bruteId ?>">
            <input type="hidden" name="action" value="claim">
            <button class="btn btn-primary btn-hero" type="submit">👑 Revendiquer le Trône</button>
            <p class="form-msg" data-msg></p>
        </form>
        <?php else: ?>
        <p class="muted small">Choisis d'abord ton bonus de niveau avant de revendiquer le Trône.</p>
        <?php endif; ?>
    </section>

    <?php else: ?>
    <!-- Trône occupé -->
    <section class="card boss-throne-card">
        <div class="boss-throne-banner">
            <span class="boss-tag-crown">👑 MAÎTRE DU TRÔNE</span>
        </div>

        <div class="boss-profile-layout">
            <div class="boss-portrait-wrap">
                <?php
                $appearance = $bossAppearance;
                include __DIR__ . '/_gladiator.php';
                ?>
                <div class="boss-aura"></div>
            </div>

            <div class="boss-throne-info">
                <h1 class="boss-name"><?= h((string)$boss['name']) ?></h1>
                <p class="boss-sub">Niveau <?= (int)$boss['level'] ?> · MMR <?= (int)$boss['mmr'] ?></p>

                <div class="boss-reign-stats">
                    <div class="reign-stat">
                        <span class="reign-num"><?= $reignDays ?></span>
                        <span class="reign-lbl">Jour<?= $reignDays > 1 ? 's' : '' ?> de règne</span>
                    </div>
                    <div class="reign-stat">
                        <span class="reign-num"><?= (int)$boss['defense_wins'] ?></span>
                        <span class="reign-lbl">Défense<?= (int)$boss['defense_wins'] > 1 ? 's' : '' ?> réussie<?= (int)$boss['defense_wins'] > 1 ? 's' : '' ?></span>
                    </div>
                    <div class="reign-stat">
                        <span class="reign-num">+<?= BOSS_DAILY_XP ?></span>
                        <span class="reign-lbl">XP / jour</span>
                    </div>
                </div>

                <?php if ($isBoss): ?>
                <div class="boss-self-panel">
                    <p class="boss-self-msg">⚔ Vous régnez sur ce Trône. Défendez-le contre les challengers !</p>
                    <p class="muted small">Chaque victoire en défense vous rapporte +5 XP. Vous gagnez automatiquement <?= BOSS_DAILY_XP ?> XP par jour de règne.</p>
                </div>

                <?php elseif ($canChallenge): ?>
                <form id="challenge-form" style="margin-top:20px">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="brute_id" value="<?= $bruteId ?>">
                    <input type="hidden" name="action" value="challenge">
                    <button class="btn btn-primary btn-hero" type="submit">⚔ DÉFIER LE MAÎTRE</button>
                    <p class="form-msg" data-msg></p>
                </form>
                <p class="muted small text-center" style="margin-top:8px">
                    Victoire : vous devenez le nouveau Maître · Défaite : +2 XP
                </p>

                <?php elseif ($alreadyTriedToday): ?>
                <div class="boss-result muted" style="margin-top:20px">
                    Vous avez déjà défié le Trône aujourd'hui. Revenez demain.
                </div>

                <?php else: ?>
                <p class="muted small" style="margin-top:16px">Choisis ton bonus de niveau avant de défier le Maître.</p>
                <?php endif; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- Challengers du règne actuel -->
    <?php if (!empty($challengers)): ?>
    <section class="card">
        <h2>⚔ Challengers de ce règne</h2>
        <p class="muted small" style="margin-top:-10px">Réinitialisé à chaque nouveau couronnement.</p>
        <div class="boss-challengers-list">
            <?php foreach ($challengers as $row):
                $won = ((int)$row['winner_id'] === (int)$row['challenger_id']);
            ?>
            <div class="boss-challenger-row <?= $won ? 'bc-win' : 'bc-loss' ?>">
                <span class="bc-result">
                    <?= $won ? '🏆' : '💀' ?>
                </span>
                <a href="brute.php?id=<?= (int)$row['challenger_id'] ?>" class="bc-name">
                    <?= h((string)$row['challenger_name']) ?>
                </a>
                <span class="bc-level muted small">Niv. <?= (int)$row['challenger_level'] ?></span>
                <a href="fight.php?id=<?= (int)$row['fight_id'] ?>" class="bc-replay muted small">replay →</a>
                <span class="bc-date muted small"><?= date('d/m H\hi', strtotime($row['created_at'])) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <!-- Historique des 5 derniers règnes -->
    <?php if (!empty($history)): ?>
    <section class="card">
        <h2>📜 Annales du Trône</h2>
        <div class="boss-history-list">
            <?php foreach ($history as $i => $row):
                $duration = '';
                try {
                    $s = new DateTimeImmutable($row['became_boss_at']);
                    $e = new DateTimeImmutable($row['dethroned_at']);
                    $d = max(0, (int)$s->diff($e)->days);
                    $duration = $d . ' jour' . ($d > 1 ? 's' : '');
                } catch (\Throwable $t) {}
            ?>
            <div class="boss-history-row">
                <span class="bh-rank">#<?= $i + 1 ?></span>
                <a href="brute.php?id=<?= (int)$row['brute_id'] ?>" class="bh-name"><?= h((string)$row['name']) ?></a>
                <span class="bh-meta muted">Niv. <?= (int)$row['level'] ?></span>
                <span class="bh-defense"><?= (int)$row['defense_wins'] ?> déf.</span>
                <span class="bh-duration muted small"><?= h($duration) ?></span>
                <?php if (!empty($row['challenger_name'])): ?>
                <span class="bh-dethroned muted small">Détrôné par <a href="#"><?= h((string)$row['challenger_name']) ?></a></span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

</main>

<script>
(function () {
    function bindForm(id, endpoint) {
        const form = document.getElementById(id);
        if (!form) return;
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const btn = form.querySelector('button[type=submit]');
            const msg = form.querySelector('[data-msg]');
            if (btn) { btn.disabled = true; btn.textContent = '⏳ En cours…'; }
            if (msg) { msg.className = 'form-msg'; msg.textContent = '…'; }
            try {
                const res  = await fetch(endpoint, { method: 'POST', body: new FormData(form) });
                const data = await res.json();
                if (data.ok && data.redirect) {
                    window.location.href = data.redirect;
                } else if (msg) {
                    msg.className = 'form-msg error';
                    msg.textContent = data.error || 'Erreur';
                    if (btn) { btn.disabled = false; btn.textContent = btn.dataset.label || 'Réessayer'; }
                }
            } catch {
                if (msg) { msg.className = 'form-msg error'; msg.textContent = 'Erreur réseau'; }
                if (btn) btn.disabled = false;
            }
        });
    }
    bindForm('claim-form',     '../api/boss_attempt.php');
    bindForm('challenge-form', '../api/boss_attempt.php');
})();
</script>
</body>
</html>
