<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/tower_engine.php';

require_login();

$me = current_brute();
if (!$me) { header('Location: dashboard.php'); exit; }

$bruteId = (int)$me['id'];
$csrf    = csrf_token();

// ── Détermination de la vue ───────────────────────────────────────────────
$view         = 'enter';
$run          = null;
$playerWon    = false;
$currentFloor = 0;

$activeRun = tower_get_active_run($bruteId);

if ($activeRun) {
    $run          = $activeRun;
    $currentFloor = (int)$run['current_floor'];

    if ((int)$run['fight_id'] > 0) {
        $stmt = db()->prepare('SELECT winner_id FROM fights WHERE id = ? LIMIT 1');
        $stmt->execute([(int)$run['fight_id']]);
        $lastFight = $stmt->fetch();
        if ($lastFight) {
            $playerWon = ((int)$lastFight['winner_id'] === $bruteId);
            $view = $playerWon ? 'between' : 'defeat';
        }
    }
} elseif (tower_has_entered_today($bruteId)) {
    $view = 'done_today';
}

// Finalisation défaite inline (si on arrive sur defeat sans passer par l'API)
if ($view === 'defeat' && $run) {
    $floorsCleared = $currentFloor - 1;
    tower_update_record($bruteId, $floorsCleared);
    db()->prepare("UPDATE tower_runs SET status='defeat', updated_at=NOW() WHERE id=?")
        ->execute([(int)$run['id']]);
}

$myRecord    = tower_get_record($bruteId);
$leaderboard = tower_get_leaderboard(10);
$prevReward  = ($view === 'between' && $currentFloor > 1) ? tower_get_floor_reward($currentFloor - 1) : null;
$todayRun    = ($view === 'done_today') ? tower_get_today_run($bruteId) : null;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Tour Infinie – ArenaForge</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" href="../assets/svg/logo/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="../assets/css/main.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@700;900&display=swap" rel="stylesheet">
<style>
/* ── Tour Infinie — styles exclusifs ────────────────────────────── */

:root {
    --tower-gold:   #c9a84c;
    --tower-red:    #8b1a1a;
    --tower-stone:  #1a1410;
    --tower-line:   rgba(201,168,76,.15);
}

.tower-wrap {
    max-width: 780px;
    margin: 0 auto;
    padding: 0 1rem 4rem;
}

/* ── Hero ── */
.tower-hero {
    position: relative;
    text-align: center;
    padding: 3rem 1rem 2.5rem;
    overflow: hidden;
}
.tower-hero::before {
    content: '';
    position: absolute;
    inset: 0;
    background:
        repeating-linear-gradient(
            to bottom,
            transparent 0px,
            transparent 38px,
            var(--tower-line) 38px,
            var(--tower-line) 39px
        );
    pointer-events: none;
    opacity: .6;
}
.tower-spire {
    position: absolute;
    top: 0; left: 50%;
    transform: translateX(-50%);
    width: 2px;
    height: 100%;
    background: linear-gradient(to bottom, var(--tower-gold), transparent);
    opacity: .3;
}

.tower-eyebrow {
    font-family: 'Cinzel', serif;
    font-size: .7rem;
    letter-spacing: .25em;
    text-transform: uppercase;
    color: var(--tower-gold);
    opacity: .8;
    margin-bottom: .75rem;
}
.tower-headline {
    font-family: 'Cinzel', serif;
    font-size: clamp(2.4rem, 8vw, 4.5rem);
    font-weight: 900;
    line-height: 1;
    color: #e8d5a0;
    text-shadow: 0 0 40px rgba(201,168,76,.25);
    margin: 0 0 .5rem;
}
.tower-headline .floor-num {
    display: block;
    font-size: clamp(5rem, 18vw, 10rem);
    color: var(--tower-gold);
    line-height: .9;
    text-shadow:
        0 0 60px rgba(201,168,76,.4),
        0 4px 0 rgba(0,0,0,.6);
    animation: floorPulse 3s ease-in-out infinite;
}
@keyframes floorPulse {
    0%,100% { text-shadow: 0 0 60px rgba(201,168,76,.4), 0 4px 0 rgba(0,0,0,.6); }
    50%      { text-shadow: 0 0 80px rgba(201,168,76,.65), 0 4px 0 rgba(0,0,0,.6); }
}
.tower-sub {
    color: var(--muted, #6b6055);
    font-size: .9rem;
    margin-top: .5rem;
}

/* ── Record chip ── */
.record-chip {
    display: inline-flex;
    align-items: center;
    gap: .5rem;
    border: 1px solid var(--tower-gold);
    border-radius: 3px;
    padding: .3rem .9rem;
    font-family: 'Cinzel', serif;
    font-size: .8rem;
    color: var(--tower-gold);
    letter-spacing: .05em;
    margin-top: 1rem;
    background: rgba(201,168,76,.06);
}
.record-chip .rk {
    font-weight: 900;
    font-size: 1rem;
}

/* ── Rules card ── */
.tower-rules {
    border: 1px solid var(--tower-line);
    border-left: 3px solid var(--tower-gold);
    background: rgba(26,20,16,.6);
    border-radius: 4px;
    padding: 1rem 1.25rem;
    margin-bottom: 1.5rem;
}
.tower-rules-title {
    font-family: 'Cinzel', serif;
    font-size: .75rem;
    letter-spacing: .2em;
    text-transform: uppercase;
    color: var(--tower-gold);
    margin-bottom: .75rem;
}
.tower-rules ul {
    margin: 0;
    padding: 0;
    list-style: none;
    display: grid;
    gap: .4rem;
}
.tower-rules li {
    display: flex;
    gap: .6rem;
    font-size: .85rem;
    color: var(--muted, #6b6055);
    line-height: 1.4;
}
.tower-rules li::before {
    content: '—';
    color: var(--tower-gold);
    flex-shrink: 0;
    opacity: .6;
}

/* ── CTA button ── */
.tower-cta-wrap {
    text-align: center;
    margin: 2rem 0 2.5rem;
}
.tower-cta {
    position: relative;
    display: inline-flex;
    align-items: center;
    gap: .75rem;
    background: transparent;
    border: 2px solid var(--tower-gold);
    color: var(--tower-gold);
    font-family: 'Cinzel', serif;
    font-size: 1rem;
    font-weight: 700;
    letter-spacing: .12em;
    text-transform: uppercase;
    padding: .9rem 2.5rem;
    cursor: pointer;
    border-radius: 2px;
    transition: background .2s, color .2s, box-shadow .2s;
    overflow: hidden;
}
.tower-cta::before {
    content: '';
    position: absolute;
    inset: 0;
    background: var(--tower-gold);
    transform: scaleX(0);
    transform-origin: left;
    transition: transform .3s ease;
    z-index: 0;
}
.tower-cta:hover::before { transform: scaleX(1); }
.tower-cta:hover { color: #0f0a07; box-shadow: 0 0 32px rgba(201,168,76,.3); }
.tower-cta > * { position: relative; z-index: 1; }
.tower-cta:disabled { opacity: .5; cursor: not-allowed; }
.tower-cta:disabled::before { display: none; }

.tower-cta-icon {
    font-size: 1.2rem;
    line-height: 1;
}

/* ── Reward milestone ── */
.reward-banner {
    display: flex;
    justify-content: center;
    gap: 2rem;
    margin: 1rem 0 1.5rem;
    padding: .75rem 1.5rem;
    background: rgba(201,168,76,.07);
    border: 1px solid rgba(201,168,76,.25);
    border-radius: 3px;
}
.reward-item {
    text-align: center;
}
.reward-item .val {
    font-family: 'Cinzel', serif;
    font-size: 1.4rem;
    font-weight: 900;
    color: var(--tower-gold);
    line-height: 1;
}
.reward-item .lbl {
    font-size: .7rem;
    color: var(--muted, #6b6055);
    text-transform: uppercase;
    letter-spacing: .1em;
    margin-top: .2rem;
}

/* ── Defeat / done-today ── */
.tower-result {
    text-align: center;
    padding: 2.5rem 1rem;
    border: 1px solid rgba(139,26,26,.4);
    background: rgba(139,26,26,.05);
    border-radius: 4px;
    margin-bottom: 1.5rem;
}
.tower-result.done {
    border-color: var(--tower-line);
    background: rgba(26,20,16,.4);
}
.result-glyph {
    font-family: 'Cinzel', serif;
    font-size: 3.5rem;
    color: var(--tower-red);
    line-height: 1;
    margin-bottom: .75rem;
    display: block;
}
.result-glyph.neutral { color: var(--tower-gold); opacity: .4; }
.result-title {
    font-family: 'Cinzel', serif;
    font-size: 1.5rem;
    font-weight: 900;
    color: #e8d5a0;
    margin-bottom: .5rem;
}
.result-sub {
    color: var(--muted, #6b6055);
    font-size: .9rem;
}
.result-floor {
    font-size: 1rem;
    color: #a89070;
    margin: .75rem 0;
}
.result-floor strong {
    font-family: 'Cinzel', serif;
    color: var(--tower-gold);
    font-size: 1.3rem;
}

/* ── Leaderboard ── */
.tower-lb {
    margin-top: 2.5rem;
}
.tower-lb-title {
    font-family: 'Cinzel', serif;
    font-size: .75rem;
    letter-spacing: .25em;
    text-transform: uppercase;
    color: var(--tower-gold);
    opacity: .8;
    padding-bottom: .75rem;
    border-bottom: 1px solid var(--tower-line);
    margin-bottom: 0;
}
.tower-lb-table {
    width: 100%;
    border-collapse: collapse;
}
.tower-lb-table th {
    font-size: .7rem;
    letter-spacing: .1em;
    text-transform: uppercase;
    color: var(--muted, #6b6055);
    padding: .6rem .75rem;
    text-align: left;
    border-bottom: 1px solid var(--tower-line);
    font-weight: 400;
}
.tower-lb-table td {
    padding: .65rem .75rem;
    font-size: .88rem;
    border-bottom: 1px solid rgba(255,255,255,.04);
    color: #a89070;
}
.tower-lb-table tr:last-child td { border-bottom: none; }
.tower-lb-table tr.is-me td { background: rgba(201,168,76,.05); }
.lb-rank {
    font-family: 'Cinzel', serif;
    font-weight: 900;
    color: var(--tower-gold);
    width: 2.5rem;
    text-align: center;
}
.lb-rank.gold   { color: #ffd700; }
.lb-rank.silver { color: #c0c0c0; }
.lb-rank.bronze { color: #cd7f32; }
.lb-name a { color: #d4b97a; text-decoration: none; }
.lb-name a:hover { color: var(--tower-gold); }
.lb-floor {
    font-family: 'Cinzel', serif;
    font-weight: 700;
    color: #e8d5a0;
    font-size: .95rem;
}
.lb-you {
    display: inline-flex;
    align-items: center;
    background: var(--tower-gold);
    color: #0f0a07;
    font-size: .6rem;
    font-weight: 700;
    letter-spacing: .08em;
    padding: .1rem .35rem;
    border-radius: 2px;
    margin-left: .4rem;
    vertical-align: middle;
    text-transform: uppercase;
}
.lb-date { font-size: .75rem; color: var(--muted, #6b6055); }
</style>
</head>
<body>
<?php include __DIR__ . '/_nav.php'; ?>

<main class="wrap" data-body-class="">
<div class="tower-wrap">

<?php if ($view === 'enter'): ?>
<!-- ════════════ ENTRÉE ════════════ -->
<div class="tower-hero card">
    <div class="tower-spire"></div>
    <div class="tower-eyebrow">Tour Infinie</div>
    <h1 class="tower-headline">
        <span class="floor-num">∞</span>
        Ascension sans fin
    </h1>
    <p class="tower-sub">Chaque étage plus dur que le précédent. Combien peux-tu en encaisser ?</p>
    <?php if ($myRecord && (int)$myRecord['best_floor'] > 0): ?>
    <div class="record-chip">
        Ton record &nbsp;·&nbsp; <span class="rk">Étage <?= (int)$myRecord['best_floor'] ?></span>
    </div>
    <?php endif; ?>
</div>

<div class="tower-rules card">
    <div class="tower-rules-title">Règles</div>
    <ul>
        <li>1 tentative par jour. PV remis à zéro à chaque étage.</li>
        <li>Étages 1–10 : squelettes · 11–25 : chevaliers noirs · 26–50 : démons · 51+ : titans.</li>
        <li>Récompense tous les 5 étages, doublée tous les 10.</li>
        <li>Défaite = record sauvegardé. Retour à l'étage 0 demain.</li>
    </ul>
</div>

<div class="tower-cta-wrap">
    <button class="tower-cta" id="tower-enter-btn"
            data-brute-id="<?= $bruteId ?>"
            data-csrf="<?= h($csrf) ?>">
        <span class="tower-cta-icon">⚔</span>
        <span>Commencer l'ascension</span>
    </button>
</div>

<?php elseif ($view === 'between'): ?>
<!-- ════════════ ENTRE ÉTAGES ════════════ -->
<div class="tower-hero card">
    <div class="tower-spire"></div>
    <div class="tower-eyebrow">Tour Infinie · En cours</div>
    <h1 class="tower-headline">
        <span class="floor-num"><?= $currentFloor ?></span>
        Étage suivant
    </h1>
    <p class="tower-sub"><?= $currentFloor - 1 ?> étage<?= ($currentFloor - 1) > 1 ? 's' : '' ?> franchi<?= ($currentFloor - 1) > 1 ? 's' : '' ?> · Prêt pour la suite ?</p>
    <?php if ($myRecord && (int)$myRecord['best_floor'] > 0): ?>
    <div class="record-chip">
        Record actuel &nbsp;·&nbsp; <span class="rk">Étage <?= (int)$myRecord['best_floor'] ?></span>
    </div>
    <?php endif; ?>
</div>

<?php if ($prevReward): ?>
<div class="reward-banner">
    <div class="reward-item">
        <div class="val">+<?= (int)$prevReward['xp'] ?> XP</div>
        <div class="lbl">Récompense palier</div>
    </div>
    <div class="reward-item">
        <div class="val">+<?= (int)$prevReward['gold'] ?> or</div>
        <div class="lbl">Récompense palier</div>
    </div>
</div>
<?php endif; ?>

<div class="tower-cta-wrap">
    <button class="tower-cta" id="tower-next-btn"
            data-brute-id="<?= $bruteId ?>"
            data-run-id="<?= (int)$run['id'] ?>"
            data-csrf="<?= h($csrf) ?>">
        <span class="tower-cta-icon">⚡</span>
        <span>Combattre l'étage <?= $currentFloor ?></span>
    </button>
</div>

<?php elseif ($view === 'defeat'): ?>
<!-- ════════════ DÉFAITE ════════════ -->
<?php
$floorsCleared = $currentFloor - 1;
$myRecord   = tower_get_record($bruteId);
$defeatXp   = $run ? (int)$run['loot_xp']   : 0;
$defeatGold = $run ? (int)$run['loot_gold']  : 0;
?>
<div class="tower-result">
    <span class="result-glyph">✝</span>
    <div class="result-title">Tombé à l'étage <?= $currentFloor ?></div>
    <div class="result-floor">Tu as franchi <strong><?= $floorsCleared ?></strong> étage<?= $floorsCleared > 1 ? 's' : '' ?> ce jour.</div>
    <?php if ($defeatXp > 0 || $defeatGold > 0): ?>
    <div class="reward-banner" style="margin-top:1rem">
        <?php if ($defeatXp > 0): ?>
        <div class="reward-item">
            <div class="val">+<?= $defeatXp ?> XP</div>
            <div class="lbl">Gagné ce run</div>
        </div>
        <?php endif; ?>
        <?php if ($defeatGold > 0): ?>
        <div class="reward-item">
            <div class="val">+<?= $defeatGold ?> or</div>
            <div class="lbl">Gagné ce run</div>
        </div>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <p class="result-sub" style="margin-top:.5rem;font-size:.8rem">Les récompenses tombent tous les 5 étages.</p>
    <?php endif; ?>
    <?php if ($myRecord && (int)$myRecord['best_floor'] > 0): ?>
    <div class="record-chip" style="margin:1rem auto 0;display:inline-flex">
        Record &nbsp;·&nbsp; <span class="rk">Étage <?= (int)$myRecord['best_floor'] ?></span>
    </div>
    <?php endif; ?>
    <p class="result-sub" style="margin-top:1rem">Reviens demain pour une nouvelle tentative.</p>
</div>

<?php elseif ($view === 'done_today'): ?>
<!-- ════════════ DÉJÀ FAIT ════════════ -->
<?php
$todayFloor = $todayRun ? max(0, (int)$todayRun['current_floor'] - 1) : 0;
$todayXp    = $todayRun ? (int)$todayRun['loot_xp']   : 0;
$todayGold  = $todayRun ? (int)$todayRun['loot_gold']  : 0;
?>
<div class="tower-result done">
    <span class="result-glyph neutral">⧖</span>
    <div class="result-title">Tentative du jour terminée</div>
    <?php if ($todayFloor > 0): ?>
    <div class="result-floor">Tombé à l'étage <strong><?= $todayFloor + 1 ?></strong> · <?= $todayFloor ?> étage<?= $todayFloor > 1 ? 's' : '' ?> franchi<?= $todayFloor > 1 ? 's' : '' ?>.</div>
    <?php endif; ?>
    <?php if ($todayXp > 0 || $todayGold > 0): ?>
    <div class="reward-banner" style="margin-top:1rem">
        <?php if ($todayXp > 0): ?>
        <div class="reward-item">
            <div class="val">+<?= $todayXp ?> XP</div>
            <div class="lbl">Gagné ce run</div>
        </div>
        <?php endif; ?>
        <?php if ($todayGold > 0): ?>
        <div class="reward-item">
            <div class="val">+<?= $todayGold ?> or</div>
            <div class="lbl">Gagné ce run</div>
        </div>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <p class="result-sub" style="margin-top:.5rem">Aucune récompense — les paliers tombent tous les 5 étages.</p>
    <?php endif; ?>
    <?php if ($myRecord && (int)$myRecord['best_floor'] > 0): ?>
    <div class="record-chip" style="margin:1rem auto;display:inline-flex">
        Record &nbsp;·&nbsp; <span class="rk">Étage <?= (int)$myRecord['best_floor'] ?></span>
    </div>
    <?php endif; ?>
    <p class="result-sub" style="margin-top:.75rem">La Tour t'attend demain.</p>
</div>
<?php endif; ?>

<!-- ════════════ LEADERBOARD ════════════ -->
<?php if (!empty($leaderboard)): ?>
<div class="tower-lb card">
    <div class="tower-lb-title">Conquérants de la Tour</div>
    <table class="tower-lb-table">
        <thead>
            <tr>
                <th style="text-align:center">#</th>
                <th>Gladiateur</th>
                <th>Niv.</th>
                <th>Étage record</th>
                <th>Date</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($leaderboard as $i => $row):
            $rank = $i + 1;
            $isMe = (int)$row['brute_id'] === $bruteId;
            $rankClass = $rank === 1 ? 'gold' : ($rank === 2 ? 'silver' : ($rank === 3 ? 'bronze' : ''));
        ?>
        <tr class="<?= $isMe ? 'is-me' : '' ?>">
            <td class="lb-rank <?= $rankClass ?>"><?= $rank ?></td>
            <td class="lb-name">
                <a href="brute.php?id=<?= (int)$row['brute_id'] ?>"><?= h($row['brute_name']) ?></a>
                <?php if ($isMe): ?><span class="lb-you">toi</span><?php endif; ?>
            </td>
            <td style="color:var(--muted,#6b6055);font-size:.8rem"><?= (int)$row['brute_level'] ?></td>
            <td class="lb-floor">Étage <?= (int)$row['best_floor'] ?></td>
            <td class="lb-date"><?= h(substr((string)$row['achieved_at'], 0, 10)) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

</div><!-- .tower-wrap -->
</main>

<script>
(function () {
    const enterBtn = document.getElementById('tower-enter-btn');
    if (enterBtn) {
        enterBtn.addEventListener('click', async () => {
            enterBtn.disabled = true;
            enterBtn.querySelector('span:last-child').textContent = 'Préparation…';
            const fd = new FormData();
            fd.append('brute_id', enterBtn.dataset.bruteId);
            fd.append('csrf',     enterBtn.dataset.csrf);
            try {
                const r    = await fetch('../api/tower_enter.php', { method: 'POST', body: fd });
                const data = await r.json();
                if (data.ok && data.redirect) {
                    (window.arenaNavigate || (u => { window.location.href = u; }))(data.redirect);
                } else {
                    alert(data.error || 'Erreur inconnue');
                    enterBtn.disabled = false;
                    enterBtn.querySelector('span:last-child').textContent = 'Commencer l\'ascension';
                }
            } catch (e) {
                alert('Erreur réseau');
                enterBtn.disabled = false;
                enterBtn.querySelector('span:last-child').textContent = 'Commencer l\'ascension';
            }
        });
    }

    const nextBtn = document.getElementById('tower-next-btn');
    if (nextBtn) {
        nextBtn.addEventListener('click', async () => {
            nextBtn.disabled = true;
            nextBtn.querySelector('span:last-child').textContent = 'Préparation…';
            const fd = new FormData();
            fd.append('brute_id', nextBtn.dataset.bruteId);
            fd.append('run_id',   nextBtn.dataset.runId);
            fd.append('csrf',     nextBtn.dataset.csrf);
            try {
                const r    = await fetch('../api/tower_fight.php', { method: 'POST', body: fd });
                const data = await r.json();
                const go   = window.arenaNavigate || (u => { window.location.href = u; });
                if (data.ok && data.redirect) {
                    go(data.redirect);
                } else if (data.ok && data.outcome === 'defeat') {
                    go('tower.php');
                } else {
                    alert(data.error || 'Erreur inconnue');
                    nextBtn.disabled = false;
                    nextBtn.querySelector('span:last-child').textContent = 'Combattre l\'étage';
                }
            } catch (e) {
                alert('Erreur réseau');
                nextBtn.disabled = false;
                nextBtn.querySelector('span:last-child').textContent = 'Combattre l\'étage';
            }
        });
    }
})();
</script>
</body>
</html>
