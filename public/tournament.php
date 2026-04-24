<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/tournament_engine.php';
require_login();

$myBrute = current_brute();
$csrf = csrf_token();

// --- Tournoi quotidien ---
$daily   = ensure_today_tournament();
$dEntries = tournament_entries((int)$daily['id']);
$dBracket = tournament_bracket((int)$daily['id']);
$dJoined  = $myBrute ? is_brute_entered((int)$daily['id'], (int)$myBrute['id']) : false;
$dHuman   = count(array_filter($dEntries, fn($e) => (int)$e['is_ai'] === 0));

// --- Tournoi hebdomadaire ---
$weekly  = ensure_this_week_tournament();
$wEntries = tournament_entries((int)$weekly['id']);
$wBracket = tournament_bracket((int)$weekly['id']);
$wJoined  = $myBrute ? is_brute_entered((int)$weekly['id'], (int)$myBrute['id']) : false;
$wHuman   = count(array_filter($wEntries, fn($e) => (int)$e['is_ai'] === 0));

// Onglet actif (paramètre URL ou défaut quotidien)
$tab = ($_GET['tab'] ?? 'daily') === 'weekly' ? 'weekly' : 'daily';

function round_labels(int $size): array
{
    $rounds = (int)log(max(2, $size), 2);
    if ($rounds === 4) return ['1er tour', 'Quarts', 'Demi-finales', 'Finale'];
    if ($rounds === 3) return ['Quarts', 'Demi-finales', 'Finale'];
    if ($rounds === 2) return ['Demi-finales', 'Finale'];
    return array_map(fn($i) => 'Round ' . ($i + 1), range(0, $rounds - 1));
}

function render_tournament_section(array $t, array $entries, array $bracket, bool $joined, int $humanCount, ?array $myBrute, string $csrf, string $type): void
{
    $rl = round_labels((int)$t['size']);
    $xpChamp  = $type === 'weekly' ? WEEKLY_TOURNAMENT_XP[1]   : TOURNAMENT_XP[1];
    $xpFinal  = $type === 'weekly' ? WEEKLY_TOURNAMENT_XP[2]   : TOURNAMENT_XP[2];
    $xpDemi   = $type === 'weekly' ? WEEKLY_TOURNAMENT_XP[3]   : TOURNAMENT_XP[3];
    $bfChamp  = $type === 'weekly' ? WEEKLY_TOURNAMENT_BONUS_FIGHTS[1] : TOURNAMENT_BONUS_FIGHTS[1];
    $bfFinal  = $type === 'weekly' ? WEEKLY_TOURNAMENT_BONUS_FIGHTS[2] : TOURNAMENT_BONUS_FIGHTS[2];
    ?>
    <section class="card tournament-header">
        <div>
            <h1>
                <img src="../assets/svg/ui/trophy.svg" alt="" class="inline-icon">
                <?= $type === 'weekly' ? 'Tournoi de la semaine' : 'Tournoi du ' . h(date('d/m/Y', strtotime($t['tour_date']))) ?>
            </h1>
            <p class="muted">
                Bracket <?= (int)$t['size'] ?> gladiateurs.
                Champion : <?= $xpChamp ?> XP + <?= $bfChamp ?> combats bonus ⚔.
                Finaliste : <?= $xpFinal ?> XP + <?= $bfFinal ?> combat bonus.
                Demi-finalistes : <?= $xpDemi ?> XP.
                Les combats de tournoi ne consomment <strong>pas</strong> vos 6 combats journaliers.
            </p>
        </div>
        <div class="tournament-status status-<?= h($t['status']) ?>">
            <?php if ($t['status'] === 'open'): ?>
                <span>Inscriptions ouvertes (<?= $humanCount ?> joueur<?= $humanCount > 1 ? 's' : '' ?> / <?= (int)$t['size'] ?> slots)</span>
            <?php elseif ($t['status'] === 'running'): ?>
                <span>Tournoi en cours...</span>
            <?php else:
                $winner = null;
                foreach ($entries as $e) if ((int)$e['brute_id'] === (int)$t['winner_id']) $winner = $e;
            ?>
                <span><strong>Champion :</strong> <?= $winner ? h($winner['brute_name']) : '?' ?></span>
            <?php endif; ?>
        </div>
    </section>

    <?php if ($myBrute && $t['status'] === 'open'): ?>
    <section class="card">
        <h2>Votre participation</h2>
        <?php if ($joined): ?>
            <p class="muted">Vous êtes inscrit avec <strong><?= h($myBrute['name']) ?></strong>.</p>
            <form class="tournament-run-form">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="tournament_type" value="<?= h($type) ?>">
                <button type="submit" class="btn btn-primary btn-large">⚔ Lancer le tournoi</button>
                <p class="form-msg" data-msg></p>
            </form>
            <p class="muted small">Les places vides seront complétées par des IA.</p>
        <?php else: ?>
            <form class="tournament-join-form">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="brute_id" value="<?= (int)$myBrute['id'] ?>">
                <input type="hidden" name="tournament_type" value="<?= h($type) ?>">
                <button type="submit" class="btn btn-primary btn-large">S'inscrire avec <?= h($myBrute['name']) ?></button>
                <p class="form-msg" data-msg></p>
            </form>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <section class="card">
        <h2>Participants (<?= count($entries) ?>/<?= (int)$t['size'] ?>)</h2>
        <?php if (empty($entries)): ?>
            <p class="muted">Aucun inscrit pour l'instant.</p>
        <?php else: ?>
            <ul class="entry-list">
                <?php foreach ($entries as $e): ?>
                <li>
                    <a href="brute.php?id=<?= (int)$e['brute_id'] ?>">
                        <span class="entry-slot"><?= (int)$e['slot'] + 1 ?></span>
                        <span class="entry-name"><?= h($e['brute_name']) ?></span>
                        <span class="entry-level">Niv. <?= (int)$e['brute_level'] ?></span>
                        <?php if ((int)$e['is_ai'] === 1): ?><span class="entry-ai">IA</span><?php endif; ?>
                        <?php
                        $pl = (int)($e['placement'] ?? 0);
                        if ($pl === 1) echo '<span class="entry-place first">Champion</span>';
                        elseif ($pl === 2) echo '<span class="entry-place second">Finaliste</span>';
                        elseif ($pl === 3) echo '<span class="entry-place third">Demi-final.</span>';
                        ?>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <?php if (!empty($bracket['rounds'])): ?>
    <section class="card">
        <h2>Bracket</h2>
        <div class="bracket">
            <?php foreach ($bracket['rounds'] as $ri => $matches): ?>
            <div class="bracket-round">
                <h3><?= h($rl[$ri] ?? ('Round ' . ($ri + 1))) ?></h3>
                <?php foreach ($matches as $m): ?>
                <a class="bracket-match" href="fight.php?id=<?= (int)$m['fight_id'] ?>">
                    <span class="match-fighter <?= $m['winner_id'] === $m['b1_id'] ? 'winner' : 'loser' ?>"><?= h($m['n1']) ?></span>
                    <span class="match-vs">vs</span>
                    <span class="match-fighter <?= $m['winner_id'] === $m['b2_id'] ? 'winner' : 'loser' ?>"><?= h($m['n2']) ?></span>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>
    <?php
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Tournoi – ArenaForge</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" href="../assets/svg/logo/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="../assets/css/main.css">
</head>
<body>
<?php include __DIR__ . '/_nav.php'; ?>

<main class="wrap">
    <div class="tab-bar">
        <a href="tournament.php?tab=daily"  class="tab-btn <?= $tab === 'daily'  ? 'active' : '' ?>">⚔ Quotidien</a>
        <a href="tournament.php?tab=weekly" class="tab-btn <?= $tab === 'weekly' ? 'active' : '' ?>">🏆 Hebdomadaire</a>
    </div>

    <?php if ($tab === 'weekly'): ?>
        <?php render_tournament_section($weekly, $wEntries, $wBracket, $wJoined, $wHuman, $myBrute, $csrf, 'weekly'); ?>
    <?php else: ?>
        <?php render_tournament_section($daily, $dEntries, $dBracket, $dJoined, $dHuman, $myBrute, $csrf, 'daily'); ?>
    <?php endif; ?>
</main>

<script src="../assets/js/tournament.js"></script>
</body>
</html>
