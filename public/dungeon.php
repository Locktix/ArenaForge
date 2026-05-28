<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/dungeon_engine.php';

require_login();

$me = current_brute();
if (!$me) { header('Location: dashboard.php'); exit; }

$bruteId = (int)$me['id'];
$csrf    = csrf_token();

// ── Détermination de la vue ───────────────────────────────────────────────────
// Vues : select | between | defeat | victory
$view      = 'select';
$run       = null;
$def       = null;
$roomIdx   = 0;   // 0-based, salle actuelle de la run
$isLastRoom = false;
$playerWon = false;
$winnerHp  = 0;
$lastFight = null;

// Victoire d'une run terminée passée via URL (?result=victory&run_id=X)
$resultParam = (string)($_GET['result'] ?? '');
$resultRunId = (int)($_GET['run_id'] ?? 0);
if ($resultParam === 'victory' && $resultRunId > 0) {
    dungeon_ensure_table();
    $stmt = db()->prepare("SELECT * FROM dungeon_runs WHERE id = ? AND brute_id = ? LIMIT 1");
    $stmt->execute([$resultRunId, $bruteId]);
    $completedRun = $stmt->fetch();
    if ($completedRun && (string)$completedRun['status'] === 'victory') {
        $view = 'victory';
        $run  = $completedRun;
        $def  = DUNGEON_DEFS[(string)$run['dungeon_code']] ?? null;
    }
}

if ($view === 'select') {
    $activeRun = dungeon_get_active_run($bruteId);
    if ($activeRun) {
        $run     = $activeRun;
        $code    = (string)$run['dungeon_code'];
        $def     = DUNGEON_DEFS[$code] ?? null;
        $roomIdx = (int)$run['current_room'] - 1;  // 0-based index de la salle combattue

        if ($def && (int)$run['fight_id'] > 0) {
            $stmt = db()->prepare('SELECT winner_id, log_json FROM fights WHERE id = ? LIMIT 1');
            $stmt->execute([(int)$run['fight_id']]);
            $lastFight = $stmt->fetch();

            if ($lastFight) {
                $playerWon = ((int)$lastFight['winner_id'] === $bruteId);

                // Extraire les PV restants du vainqueur
                $log = json_decode((string)$lastFight['log_json'], true) ?: [];
                foreach (array_reverse($log) as $ev) {
                    if (($ev['event'] ?? '') === 'end' && isset($ev['winner_hp'])) {
                        $winnerHp = max(1, (int)$ev['winner_hp']);
                        break;
                    }
                }
                if ($winnerHp === 0) $winnerHp = (int)$run['current_hp'];

                if (!$playerWon) {
                    $view = 'defeat';
                } else {
                    $isLastRoom = ($roomIdx + 1 >= (int)$run['total_rooms']);
                    $view = 'between';
                }
            }
        }
    }
}

// Historique des runs récentes + état "déjà tenté aujourd'hui" par donjon
dungeon_ensure_table();
$stmt = db()->prepare("SELECT * FROM dungeon_runs WHERE brute_id = ? ORDER BY id DESC LIMIT 6");
$stmt->execute([$bruteId]);
$recentRuns = $stmt->fetchAll();

// Leaderboard global — meilleur run par brute, top 10
$leaderboard = db()->query("
    SELECT dr.dungeon_code, dr.current_room, dr.total_rooms, dr.status,
           b.name AS brute_name, b.id AS brute_id
    FROM dungeon_runs dr
    JOIN brutes b ON b.id = dr.brute_id
    WHERE dr.status != 'active'
      AND dr.id = (
          SELECT dr2.id FROM dungeon_runs dr2
          WHERE dr2.brute_id = dr.brute_id AND dr2.status != 'active'
          ORDER BY
              CASE dr2.dungeon_code WHEN 'nexus' THEN 50 WHEN 'abisse' THEN 30 WHEN 'forteresse' THEN 20 ELSE 10 END + dr2.current_room DESC,
              CASE dr2.status WHEN 'victory' THEN 1 ELSE 0 END DESC,
              dr2.id DESC
          LIMIT 1
      )
    ORDER BY
        CASE dr.dungeon_code WHEN 'nexus' THEN 50 WHEN 'abisse' THEN 30 WHEN 'forteresse' THEN 20 ELSE 10 END + dr.current_room DESC,
        CASE dr.status WHEN 'victory' THEN 1 ELSE 0 END DESC
    LIMIT 10
")->fetchAll();

$stmt = db()->prepare("
    SELECT dungeon_code, COUNT(*) AS attempts
    FROM dungeon_runs
    WHERE brute_id = ? AND DATE(started_at) = CURDATE()
    GROUP BY dungeon_code
");
$stmt->execute([$bruteId]);
$todayAttempts = [];
foreach ($stmt->fetchAll() as $row) {
    $todayAttempts[$row['dungeon_code']] = (int)$row['attempts'];
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Donjons – ArenaForge</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" href="../assets/svg/logo/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="../assets/css/main.css">
</head>
<body>
<?php include __DIR__ . '/_nav.php'; ?>

<main class="wrap" data-body-class="">


<?php if ($view === 'select'): ?>
<!-- ═══════════════════════ SÉLECTION DE DONJON ═══════════════════════════════ -->

<section class="card dungeon-hero">
    <h1 class="dungeon-title">⚔ Les Donjons</h1>
    <p class="muted dungeon-subtitle">
        Séquences de salles maudites. Tes PV persistent entre les combats —
        chaque salle t'affaiblit mais t'enrichit.
    </p>
</section>

<div class="dungeon-grid">
<?php foreach (DUNGEON_DEFS as $code => $d):
    $locked      = (int)$me['level'] < (int)$d['min_level'];
    $hasCost     = (int)$d['entry_cost'] > 0;
    $canAfford   = (int)$me['gold'] >= (int)$d['entry_cost'];
    $doneToday   = ($todayAttempts[$code] ?? 0) > 0;
    $totalXp     = array_sum(array_column($d['rooms'], 'xp'))  + (int)$d['final_bonus']['xp'];
    $totalGold   = array_sum(array_column($d['rooms'], 'gold')) + (int)$d['final_bonus']['gold'];
?>
<?php
    $diff      = $d['difficulty'] ?? 'facile';
    $diffLabel = ['facile' => 'Facile', 'intermediaire' => 'Intermédiaire', 'difficile' => 'Difficile', 'legendaire' => 'Légendaire'][$diff] ?? $diff;
?>
<div class="card dungeon-card dungeon-diff-<?= h($diff) ?> <?= ($locked || $doneToday) ? 'dungeon-card--locked' : '' ?>">
    <div class="dungeon-card-head">
        <span class="dungeon-icon"><?= $d['icon'] ?></span>
        <div class="dungeon-card-info">
            <div class="dungeon-card-title-row">
                <h2 class="dungeon-card-title"><?= h($d['name']) ?></h2>
                <span class="dungeon-diff-badge"><?= h($diffLabel) ?></span>
            </div>
            <p class="dungeon-card-desc muted"><?= h($d['desc']) ?></p>
        </div>
    </div>

    <div class="dungeon-meta">
        <span class="dungeon-meta-item">
            <span class="dungeon-meta-label">Salles</span>
            <span class="dungeon-meta-val"><?= count($d['rooms']) ?></span>
        </span>
        <span class="dungeon-meta-item">
            <span class="dungeon-meta-label">Niveau min.</span>
            <span class="dungeon-meta-val <?= $locked ? 'dungeon-locked-val' : '' ?>"><?= (int)$d['min_level'] ?></span>
        </span>
        <span class="dungeon-meta-item">
            <span class="dungeon-meta-label">Coût</span>
            <span class="dungeon-meta-val <?= ($hasCost && !$canAfford && !$locked) ? 'dungeon-locked-val' : '' ?>">
                <?= $hasCost ? $d['entry_cost'] . ' or' : 'Gratuit' ?>
            </span>
        </span>
        <span class="dungeon-meta-item">
            <span class="dungeon-meta-label">Butin max.</span>
            <span class="dungeon-meta-val"><?= $totalXp ?> XP · <?= $totalGold ?> or</span>
        </span>
    </div>

    <ol class="dungeon-rooms-list">
        <?php foreach ($d['rooms'] as $i => $room): ?>
        <li class="dungeon-room-preview">
            <span class="dungeon-room-num"><?= $i + 1 ?></span>
            <span class="dungeon-room-name"><?= h($room['name']) ?></span>
            <span class="dungeon-room-loot"><?= $room['xp'] ?> XP · <?= $room['gold'] ?> or</span>
        </li>
        <?php endforeach; ?>
        <li class="dungeon-room-preview dungeon-room-final">
            <span class="dungeon-room-num">★</span>
            <span class="dungeon-room-name">Bonus de victoire totale</span>
            <span class="dungeon-room-loot">+<?= $d['final_bonus']['xp'] ?> XP · +<?= $d['final_bonus']['gold'] ?> or</span>
        </li>
    </ol>

    <?php if ($doneToday): ?>
        <p class="dungeon-locked-msg">🌙 Déjà tenté aujourd'hui — reviens demain</p>
    <?php elseif ($locked): ?>
        <p class="dungeon-locked-msg">🔒 Niveau <?= (int)$d['min_level'] ?> requis</p>
    <?php elseif ($hasCost && !$canAfford): ?>
        <p class="dungeon-locked-msg">💰 <?= (int)$d['entry_cost'] ?> or requis — tu en as <?= (int)$me['gold'] ?></p>
    <?php else: ?>
        <button class="btn btn-primary dungeon-enter-btn"
                data-code="<?= h($code) ?>"
                data-csrf="<?= h($csrf) ?>"
                data-cost="<?= (int)$d['entry_cost'] ?>">
            <?= $hasCost ? '⚔ Entrer (' . $d['entry_cost'] . ' or)' : '⚔ Entrer dans le donjon' ?>
        </button>
    <?php endif; ?>
</div>
<?php endforeach; ?>
</div>

<?php if (!empty($recentRuns)): ?>
<section class="card dungeon-history">
    <h2 class="dungeon-history-title">Expéditions récentes</h2>
    <table class="dungeon-history-table">
        <thead><tr>
            <th>Donjon</th><th>Salles</th><th>Résultat</th><th>XP</th><th>Or</th><th>Date</th>
        </tr></thead>
        <tbody>
        <?php foreach ($recentRuns as $r):
            $rd = DUNGEON_DEFS[(string)$r['dungeon_code']] ?? null;
            $statusLabel = match((string)$r['status']) {
                'victory'   => '<span class="dungeon-status dungeon-status--victory">Victoire</span>',
                'defeat'    => '<span class="dungeon-status dungeon-status--defeat">Défaite</span>',
                'abandoned' => '<span class="dungeon-status dungeon-status--abandoned">Abandonné</span>',
                default     => '<span class="dungeon-status dungeon-status--active">En cours</span>',
            };
        ?>
        <tr>
            <td><?= $rd ? $rd['icon'] . ' ' . h($rd['name']) : h($r['dungeon_code']) ?></td>
            <td><?= (int)$r['current_room'] ?> / <?= (int)$r['total_rooms'] ?></td>
            <td><?= $statusLabel ?></td>
            <td class="accent"><?= (int)$r['loot_xp'] ?> XP</td>
            <td class="accent"><?= (int)$r['loot_gold'] ?> or</td>
            <td class="muted small"><?= h(substr((string)$r['started_at'], 0, 10)) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php endif; ?>

<?php if (!empty($leaderboard)): ?>
<section class="card dungeon-leaderboard">
    <h2 class="dungeon-history-title">🏆 Explorateurs les plus loin</h2>
    <table class="dungeon-history-table">
        <thead><tr>
            <th>#</th><th>Gladiateur</th><th>Donjon</th><th>Salle atteinte</th><th>Résultat</th>
        </tr></thead>
        <tbody>
        <?php foreach ($leaderboard as $i => $row):
            $rd = DUNGEON_DEFS[(string)$row['dungeon_code']] ?? null;
            $isVictory = (string)$row['status'] === 'victory';
            $isMe = (int)$row['brute_id'] === $bruteId;
        ?>
        <tr class="<?= $isMe ? 'dungeon-lb-me' : '' ?>">
            <td class="dungeon-lb-rank"><?= $i + 1 ?></td>
            <td>
                <a href="brute.php?id=<?= (int)$row['brute_id'] ?>" class="accent"><?= h($row['brute_name']) ?></a>
                <?php if ($isMe): ?><span class="dungeon-lb-you">toi</span><?php endif; ?>
            </td>
            <td><?= $rd ? $rd['icon'] . ' ' . h($rd['name']) : h($row['dungeon_code']) ?></td>
            <td>
                <?= $isVictory ? '★' : '' ?>
                Salle <?= (int)$row['current_room'] ?> / <?= (int)$row['total_rooms'] ?>
            </td>
            <td>
                <?php if ($isVictory): ?>
                    <span class="dungeon-status dungeon-status--victory">Victoire</span>
                <?php else: ?>
                    <span class="dungeon-status dungeon-status--defeat">Défaite</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php endif; ?>


<?php elseif ($view === 'between' && $run && $def): ?>
<!-- ═══════════════════════ ENTRE DEUX SALLES ════════════════════════════════ -->
<?php
    $hpMax      = (int)$run['hp_max'];
    $hpPct      = $hpMax > 0 ? (int)round($winnerHp / $hpMax * 100) : 0;
    $roomDef    = $def['rooms'][$roomIdx] ?? null;
    $nextRoomDef = !$isLastRoom ? ($def['rooms'][$roomIdx + 1] ?? null) : null;
    $code        = (string)$run['dungeon_code'];
?>
<section class="card dungeon-between-header">
    <div class="dungeon-between-badge"><?= $def['icon'] ?> <?= h($def['name']) ?></div>
    <?php if ($isLastRoom): ?>
        <h2 class="dungeon-between-title success">🏆 Dernier gardien abattu !</h2>
        <p class="muted">Tu as traversé toutes les salles. Réclame ta victoire et ton butin.</p>
    <?php else: ?>
        <h2 class="dungeon-between-title">✅ Salle <?= $roomIdx + 1 ?> / <?= (int)$run['total_rooms'] ?> vaincue !</h2>
        <p class="muted">Tes PV persistent. La prochaine salle t'attend.</p>
    <?php endif; ?>
</section>

<div class="dungeon-between-grid">
    <section class="card dungeon-between-card">
        <h3 class="dungeon-between-card-title">Ton état actuel</h3>
        <div class="dungeon-hp-display">
            <div class="dungeon-hp-bar-wrap">
                <div class="dungeon-hp-bar-fill" style="width:<?= $hpPct ?>%"></div>
            </div>
            <span class="dungeon-hp-text"><?= $winnerHp ?> / <?= $hpMax ?> PV (<?= $hpPct ?>%)</span>
        </div>
        <?php if (!$isLastRoom): ?>
        <p class="dungeon-hp-warning muted small">
            <?php if ($hpPct <= 30): ?>⚠ PV critiques — la prochaine salle pourrait être fatale.
            <?php elseif ($hpPct <= 60): ?>⚡ Tu tiens debout, mais reste prudent.
            <?php else: ?>💪 En forme. Poursuis l'assaut.
            <?php endif; ?>
        </p>
        <?php endif; ?>
    </section>

    <?php if ($roomDef): ?>
    <section class="card dungeon-between-card">
        <h3 class="dungeon-between-card-title">Butin de cette salle</h3>
        <div class="dungeon-loot-row">
            <span class="dungeon-loot-icon">⚡</span>
            <span class="dungeon-loot-label">Expérience</span>
            <span class="dungeon-loot-val accent">+<?= (int)$roomDef['xp'] ?> XP</span>
        </div>
        <div class="dungeon-loot-row">
            <span class="dungeon-loot-icon">💰</span>
            <span class="dungeon-loot-label">Or</span>
            <span class="dungeon-loot-val accent">+<?= (int)$roomDef['gold'] ?> or</span>
        </div>
        <div class="dungeon-loot-row">
            <span class="dungeon-loot-icon">🔮</span>
            <span class="dungeon-loot-label">Fragments</span>
            <span class="dungeon-loot-val accent">+<?= (int)$roomDef['frags'] ?> frags</span>
        </div>
        <?php if ($isLastRoom): ?>
        <div class="dungeon-loot-divider"></div>
        <p class="dungeon-between-card-title" style="margin-top:10px">Bonus de victoire totale</p>
        <div class="dungeon-loot-row">
            <span class="dungeon-loot-icon">★</span>
            <span class="dungeon-loot-label">XP bonus</span>
            <span class="dungeon-loot-val accent">+<?= (int)$def['final_bonus']['xp'] ?> XP</span>
        </div>
        <div class="dungeon-loot-row">
            <span class="dungeon-loot-icon">★</span>
            <span class="dungeon-loot-label">Or bonus</span>
            <span class="dungeon-loot-val accent">+<?= (int)$def['final_bonus']['gold'] ?> or</span>
        </div>
        <div class="dungeon-loot-row">
            <span class="dungeon-loot-icon">★</span>
            <span class="dungeon-loot-label">Fragments bonus</span>
            <span class="dungeon-loot-val accent">+<?= (int)$def['final_bonus']['frags'] ?> frags</span>
        </div>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if (!$isLastRoom && $nextRoomDef): ?>
    <section class="card dungeon-between-card">
        <h3 class="dungeon-between-card-title">Prochaine salle</h3>
        <p class="dungeon-next-name"><?= h($nextRoomDef['name']) ?></p>
        <p class="muted small">
            Boss environ <?= $nextRoomDef['hp_pct'] ?>% de tes PV max
            <?php if ($nextRoomDef['skill']): ?> · compétence : <em><?= h($nextRoomDef['skill']) ?></em><?php endif; ?>
        </p>
        <div class="dungeon-loot-row small muted">
            <span>Récompense : <?= $nextRoomDef['xp'] ?> XP · <?= $nextRoomDef['gold'] ?> or</span>
        </div>
    </section>
    <?php endif; ?>
</div>

<div class="dungeon-between-actions">
    <?php if ($isLastRoom): ?>
    <button class="btn btn-primary btn-lg dungeon-next-btn"
            data-run-id="<?= (int)$run['id'] ?>"
            data-csrf="<?= h($csrf) ?>">
        🏆 Réclamer la victoire
    </button>
    <?php else: ?>
    <button class="btn btn-primary btn-lg dungeon-next-btn"
            data-run-id="<?= (int)$run['id'] ?>"
            data-csrf="<?= h($csrf) ?>">
        ⚔ Passer à la salle <?= $roomIdx + 2 ?> / <?= (int)$run['total_rooms'] ?>
    </button>
    <?php endif; ?>
    <button class="btn btn-ghost dungeon-abandon-btn"
            data-run-id="<?= (int)$run['id'] ?>"
            data-csrf="<?= h($csrf) ?>"
            data-redirect="dungeon.php">
        🏳 Abandonner l'expédition
    </button>
</div>


<?php elseif ($view === 'defeat' && $run && $def): ?>
<!-- ═══════════════════════ DÉFAITE ══════════════════════════════════════════ -->
<?php $roomIdx = max(0, (int)$run['current_room'] - 1); ?>
<section class="card dungeon-result dungeon-result--defeat">
    <div class="dungeon-result-icon">💀</div>
    <h2 class="dungeon-result-title">Ton expédition s'arrête ici</h2>
    <p class="muted">
        Vaincu en salle <?= $roomIdx + 1 ?> / <?= (int)$run['total_rooms'] ?>
        de <strong><?= h($def['name']) ?></strong>.
    </p>
    <?php if ((int)$run['loot_xp'] > 0 || (int)$run['loot_gold'] > 0): ?>
    <div class="dungeon-result-loot">
        <p class="dungeon-result-loot-title">Butin des salles franchies</p>
        <?php if ((int)$run['loot_xp'] > 0): ?>
            <span class="dungeon-result-loot-item accent">+<?= (int)$run['loot_xp'] ?> XP</span>
        <?php endif; ?>
        <?php if ((int)$run['loot_gold'] > 0): ?>
            <span class="dungeon-result-loot-item accent">+<?= (int)$run['loot_gold'] ?> or</span>
        <?php endif; ?>
        <?php if ((int)$run['loot_frags'] > 0): ?>
            <span class="dungeon-result-loot-item accent">+<?= (int)$run['loot_frags'] ?> frags</span>
        <?php endif; ?>
        <p class="muted small" style="margin-top:6px">✓ Déjà crédité à ton compte</p>
    </div>
    <?php else: ?>
    <p class="muted small">Aucun butin — tu n'as franchi aucune salle.</p>
    <?php endif; ?>
    <div class="dungeon-result-actions">
        <button class="btn btn-ghost dungeon-abandon-btn"
                data-run-id="<?= (int)$run['id'] ?>"
                data-csrf="<?= h($csrf) ?>"
                data-redirect="dungeon.php"
                data-no-confirm="1">
            🔄 Retourner à la sélection
        </button>
        <a class="btn btn-ghost" href="brute.php?id=<?= $bruteId ?>">Retour au gladiateur</a>
    </div>
</section>


<?php elseif ($view === 'victory' && $run && $def): ?>
<!-- ═══════════════════════ VICTOIRE TOTALE ══════════════════════════════════ -->
<section class="card dungeon-result dungeon-result--victory">
    <div class="dungeon-result-icon">🏆</div>
    <h2 class="dungeon-result-title">Expédition accomplie !</h2>
    <p class="muted">
        Tu as traversé toutes les <?= (int)$run['total_rooms'] ?> salles de
        <strong><?= h($def['name']) ?></strong>.
    </p>
    <div class="dungeon-result-loot">
        <p class="dungeon-result-loot-title">Butin total</p>
        <span class="dungeon-result-loot-item accent big">+<?= (int)$run['loot_xp'] ?> XP</span>
        <span class="dungeon-result-loot-item accent big">+<?= (int)$run['loot_gold'] ?> or</span>
        <?php if ((int)$run['loot_frags'] > 0): ?>
            <span class="dungeon-result-loot-item accent">+<?= (int)$run['loot_frags'] ?> frags</span>
        <?php endif; ?>
    </div>
    <div class="dungeon-result-actions">
        <a class="btn btn-primary" href="dungeon.php">⚔ Nouvelle expédition</a>
        <a class="btn btn-ghost" href="brute.php?id=<?= $bruteId ?>">Retour au gladiateur</a>
    </div>
</section>

<?php endif; ?>

<script src="../assets/js/dungeon.js"></script>
</main>
</body>
</html>
