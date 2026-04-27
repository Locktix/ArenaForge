<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM brutes WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$brute = $stmt->fetch();

if (!$brute) {
    http_response_code(404);
    echo 'Gladiateur introuvable.';
    exit;
}

// ============================================================
// Calculs sur les 200 derniers combats (raisonnable pour PHP local)
// ============================================================
$stmt = db()->prepare('
    SELECT f.id, f.brute1_id, f.brute2_id, f.winner_id, f.context, f.log_json, f.created_at,
           b1.name AS n1, b2.name AS n2
    FROM fights f
    JOIN brutes b1 ON b1.id = f.brute1_id
    JOIN brutes b2 ON b2.id = f.brute2_id
    WHERE f.brute1_id = ? OR f.brute2_id = ?
    ORDER BY f.created_at DESC
    LIMIT 200
');
$stmt->execute([$id, $id]);
$fights = $stmt->fetchAll();

$totalFights = count($fights);
$wins = 0; $losses = 0;
$critsTotal = 0; $hitsTotal = 0;
$dodgesTotal = 0; $attacksFaced = 0;
$damageDealtTotal = 0; $damageTakenTotal = 0;
$flawless = 0; $upsets = 0;
$rivalCounter = []; // opp_name => ['wins' => x, 'losses' => y]
$contextCounter = []; // arena/tournament/challenge/boss/duo

foreach ($fights as $f) {
    $log = json_decode((string)$f['log_json'], true) ?: [];
    $isLeft = (int)$f['brute1_id'] === $id;
    $opponentName = $isLeft ? (string)$f['n2'] : (string)$f['n1'];
    $myName = $isLeft ? (string)$f['n1'] : (string)$f['n2'];
    $won = (int)$f['winner_id'] === $id;

    if ($won) $wins++; else $losses++;

    $ctx = (string)($f['context'] ?? 'arena');
    if (!isset($contextCounter[$ctx])) $contextCounter[$ctx] = ['wins' => 0, 'losses' => 0];
    $contextCounter[$ctx][$won ? 'wins' : 'losses']++;

    $myDamageThisFight = 0;
    $damageTakenThisFight = 0;
    foreach ($log as $ev) {
        $type = $ev['event'] ?? '';
        if ($type === 'hit') {
            $attSlot = (string)($ev['attacker_slot'] ?? '');
            // Dégâts portés par le maître joueur (slot ?0)
            $isMyMasterAttacker = (($ev['attacker'] ?? '') === $myName) && substr($attSlot, 1) === '0';
            if ($isMyMasterAttacker) {
                $hitsTotal++;
                $myDamageThisFight += (int)($ev['damage'] ?? 0);
                if (!empty($ev['crit'])) $critsTotal++;
            }
            if (($ev['defender'] ?? '') === $myName) {
                $damageTakenThisFight += (int)($ev['damage'] ?? 0);
            }
        } elseif ($type === 'dodge') {
            if (($ev['defender'] ?? '') === $myName) {
                $dodgesTotal++;
                $attacksFaced++;
            }
        }
    }
    $damageDealtTotal += $myDamageThisFight;
    $damageTakenTotal += $damageTakenThisFight;

    if ($won && $damageTakenThisFight === 0) $flawless++;

    // Rival counter
    if ($opponentName !== '' && $opponentName !== $myName) {
        if (!isset($rivalCounter[$opponentName])) $rivalCounter[$opponentName] = ['wins' => 0, 'losses' => 0, 'opp_id' => $isLeft ? (int)$f['brute2_id'] : (int)$f['brute1_id']];
        $rivalCounter[$opponentName][$won ? 'wins' : 'losses']++;
    }
}

// Note: les "attacksFaced" sont uniquement les esquives recensées ; pour
// un dodgeRate plus précis on additionne aussi les hits subis.
$attacksFacedTotal = $attacksFaced + array_reduce($fights, function($acc, $f) use ($id) {
    $log = json_decode((string)$f['log_json'], true) ?: [];
    $myName = (int)$f['brute1_id'] === $id ? (string)$f['n1'] : (string)$f['n2'];
    foreach ($log as $ev) {
        if (($ev['event'] ?? '') === 'hit' && ($ev['defender'] ?? '') === $myName) $acc++;
    }
    return $acc;
}, 0);

$winrate    = $totalFights ? round($wins * 100 / $totalFights, 1) : 0.0;
$critrate   = $hitsTotal   ? round($critsTotal * 100 / $hitsTotal, 1) : 0.0;
$dodgerate  = $attacksFacedTotal ? round($dodgesTotal * 100 / $attacksFacedTotal, 1) : 0.0;
$avgDamage  = $totalFights ? round($damageDealtTotal / $totalFights, 1) : 0.0;
$avgTaken   = $totalFights ? round($damageTakenTotal / $totalFights, 1) : 0.0;

uasort($rivalCounter, fn($a, $b) => ($b['wins'] + $b['losses']) <=> ($a['wins'] + $a['losses']));
$topRivals = array_slice($rivalCounter, 0, 8, true);

$appearance = json_decode((string)$brute['appearance_seed'], true) ?: [];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Stats — <?= h($brute['name']) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" href="../assets/svg/logo/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="../assets/css/main.css">
</head>
<body>
<?php include __DIR__ . '/_nav.php'; ?>

<main class="wrap">
    <section class="card">
        <div class="stats-page-header">
            <div class="stats-portrait">
                <?php include __DIR__ . '/_gladiator.php'; ?>
            </div>
            <div>
                <h1><?= h($brute['name']) ?> — Statistiques</h1>
                <p class="muted">Analyse calculée sur les <?= $totalFights ?> derniers combats.</p>
                <p>
                    <a class="btn btn-ghost btn-sm" href="brute.php?id=<?= $id ?>">← Retour à la fiche</a>
                </p>
            </div>
        </div>
    </section>

    <section class="card">
        <h2>Vue d'ensemble</h2>
        <div class="stats-grid">
            <div class="stat-tile"><span>Combats analysés</span><strong><?= $totalFights ?></strong></div>
            <div class="stat-tile stat-tile-good"><span>Victoires</span><strong><?= $wins ?></strong></div>
            <div class="stat-tile stat-tile-bad"><span>Défaites</span><strong><?= $losses ?></strong></div>
            <div class="stat-tile"><span>Winrate</span><strong><?= $winrate ?>%</strong></div>
            <div class="stat-tile"><span>Crits portés</span><strong><?= $critsTotal ?></strong></div>
            <div class="stat-tile"><span>Taux de crit</span><strong><?= $critrate ?>%</strong></div>
            <div class="stat-tile"><span>Esquives</span><strong><?= $dodgesTotal ?></strong></div>
            <div class="stat-tile"><span>Taux d'esquive</span><strong><?= $dodgerate ?>%</strong></div>
            <div class="stat-tile"><span>Dégâts portés (total)</span><strong><?= $damageDealtTotal ?></strong></div>
            <div class="stat-tile"><span>Dégâts portés (moy.)</span><strong><?= $avgDamage ?></strong></div>
            <div class="stat-tile"><span>Dégâts subis (total)</span><strong><?= $damageTakenTotal ?></strong></div>
            <div class="stat-tile"><span>Dégâts subis (moy.)</span><strong><?= $avgTaken ?></strong></div>
            <div class="stat-tile"><span>Combats parfaits</span><strong><?= $flawless ?></strong></div>
            <div class="stat-tile"><span>MMR</span><strong><?= (int)$brute['mmr'] ?></strong></div>
            <div class="stat-tile"><span>Pic MMR</span><strong><?= (int)$brute['peak_mmr'] ?></strong></div>
            <div class="stat-tile"><span>Niveau</span><strong><?= (int)$brute['level'] ?></strong></div>
        </div>
    </section>

    <?php if (!empty($contextCounter)): ?>
        <section class="card">
            <h2>Par contexte</h2>
            <table class="ranking">
                <thead>
                    <tr><th>Contexte</th><th>Combats</th><th>V</th><th>D</th><th>%</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($contextCounter as $ctx => $c):
                        $sub = $c['wins'] + $c['losses'];
                        $rate = $sub ? round($c['wins'] * 100 / $sub, 1) : 0;
                    ?>
                        <tr>
                            <td><?= h(ucfirst($ctx)) ?></td>
                            <td><?= $sub ?></td>
                            <td><?= $c['wins'] ?></td>
                            <td><?= $c['losses'] ?></td>
                            <td><?= $rate ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    <?php endif; ?>

    <?php if (!empty($topRivals)): ?>
        <section class="card">
            <h2>Top adversaires (rencontres)</h2>
            <table class="ranking">
                <thead>
                    <tr><th>Gladiateur</th><th>Rencontres</th><th>V</th><th>D</th><th>Bilan</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($topRivals as $name => $r):
                        $sub = $r['wins'] + $r['losses'];
                        $diff = $r['wins'] - $r['losses'];
                        $cls = $diff > 0 ? 'positive' : ($diff < 0 ? 'negative' : '');
                    ?>
                        <tr>
                            <td><a href="brute.php?id=<?= (int)$r['opp_id'] ?>"><?= h($name) ?></a></td>
                            <td><?= $sub ?></td>
                            <td><?= $r['wins'] ?></td>
                            <td><?= $r['losses'] ?></td>
                            <td class="rival-diff <?= $cls ?>"><?= ($diff > 0 ? '+' : '') . $diff ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    <?php endif; ?>
</main>
</body>
</html>
