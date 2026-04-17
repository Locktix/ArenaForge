<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/tournament_engine.php';
require_login();

$myBrute = current_brute();
$tournament = ensure_today_tournament();

$entries = tournament_entries((int)$tournament['id']);
$bracket = tournament_bracket((int)$tournament['id']);

$alreadyJoined = false;
if ($myBrute) {
    $alreadyJoined = is_brute_entered((int)$tournament['id'], (int)$myBrute['id']);
}
$humanCount = count(array_filter($entries, fn($e) => (int)$e['is_ai'] === 0));
$totalCount = count($entries);

$csrf = csrf_token();

// Labels de rounds (selon taille)
$roundLabels = [];
$rounds = (int)log(max(2, (int)$tournament['size']), 2);
if ($rounds === 3) {
    $roundLabels = ['Quarts', 'Demi-finales', 'Finale'];
} elseif ($rounds === 2) {
    $roundLabels = ['Demi-finales', 'Finale'];
} else {
    for ($i = 0; $i < $rounds; $i++) {
        $roundLabels[$i] = 'Round ' . ($i + 1);
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Tournoi – ArenaForge</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" href="assets/svg/logo/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="assets/css/main.css">
</head>
<body>
<?php include __DIR__ . '/_nav.php'; ?>

<main class="wrap">
    <section class="card tournament-header">
        <div>
            <h1>
                <img src="assets/svg/ui/trophy.svg" alt="" class="inline-icon">
                Tournoi du <?= h(date('d/m/Y', strtotime($tournament['tour_date']))) ?>
            </h1>
            <p class="muted">
                Bracket à <?= (int)$tournament['size'] ?> gladiateurs. Champion : <?= TOURNAMENT_XP[1] ?> XP + <?= TOURNAMENT_BONUS_FIGHTS[1] ?> combats bonus ⚔.
                Finaliste : <?= TOURNAMENT_XP[2] ?> XP + <?= TOURNAMENT_BONUS_FIGHTS[2] ?> combat bonus. Demi-finalistes : <?= TOURNAMENT_XP[3] ?> XP.
                Les combats du tournoi ne consomment <strong>pas</strong> vos 6 combats journaliers.
            </p>
        </div>
        <div class="tournament-status status-<?= h($tournament['status']) ?>">
            <?php if ($tournament['status'] === 'open'): ?>
                <span>Inscriptions ouvertes (<?= $humanCount ?> joueur<?= $humanCount > 1 ? 's' : '' ?> / <?= (int)$tournament['size'] ?> slots, les places vides seront complétées par des IA)</span>
            <?php elseif ($tournament['status'] === 'running'): ?>
                <span>Tournoi en cours...</span>
            <?php else: ?>
                <?php
                  $winner = null;
                  foreach ($entries as $e) if ((int)$e['brute_id'] === (int)$tournament['winner_id']) $winner = $e;
                ?>
                <span><strong>Champion :</strong> <?= $winner ? h($winner['brute_name']) : '?' ?></span>
            <?php endif; ?>
        </div>
    </section>

    <?php if ($myBrute && $tournament['status'] === 'open'): ?>
        <section class="card">
            <h2>Votre participation</h2>
            <?php if ($alreadyJoined): ?>
                <p class="muted">Vous êtes inscrit avec <strong><?= h($myBrute['name']) ?></strong>.</p>
                <form id="tournament-run-form">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <button type="submit" class="btn btn-primary btn-large">⚔ Lancer le tournoi</button>
                    <p class="form-msg" data-msg></p>
                </form>
                <p class="muted small">Si vous êtes seul inscrit, les autres slots seront complétés par des IA.</p>
            <?php else: ?>
                <form id="tournament-join-form">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="brute_id" value="<?= (int)$myBrute['id'] ?>">
                    <button type="submit" class="btn btn-primary btn-large">S'inscrire avec <?= h($myBrute['name']) ?></button>
                    <p class="form-msg" data-msg></p>
                </form>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <section class="card">
        <h2>Participants</h2>
        <?php if (empty($entries)): ?>
            <p class="muted">Aucun inscrit pour l'instant.</p>
        <?php else: ?>
            <ul class="entry-list">
                <?php foreach ($entries as $e): ?>
                    <li>
                        <a href="brute.php?id=<?= (int)$e['brute_id'] ?>">
                            <span class="entry-slot">#<?= (int)$e['slot'] + 1 ?></span>
                            <span class="entry-name"><?= h($e['brute_name']) ?></span>
                            <span class="entry-level">Niv. <?= (int)$e['brute_level'] ?></span>
                            <?php if ((int)$e['is_ai'] === 1): ?>
                                <span class="entry-ai">IA</span>
                            <?php endif; ?>
                            <?php if ((int)$e['placement'] === 1): ?>
                                <span class="entry-place first">🏆 Champion</span>
                            <?php elseif ((int)$e['placement'] === 2): ?>
                                <span class="entry-place second">🥈 Finaliste</span>
                            <?php elseif ((int)$e['placement'] === 3): ?>
                                <span class="entry-place third">🥉 Demi-finaliste</span>
                            <?php endif; ?>
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
                <?php foreach ($bracket['rounds'] as $roundIdx => $matches): ?>
                    <div class="bracket-round">
                        <h3><?= h($roundLabels[$roundIdx] ?? ('Round ' . ($roundIdx + 1))) ?></h3>
                        <?php foreach ($matches as $m): ?>
                            <?php
                                $b1Won = $m['winner_id'] === $m['b1_id'];
                                $b2Won = $m['winner_id'] === $m['b2_id'];
                            ?>
                            <a class="bracket-match" href="fight.php?id=<?= (int)$m['fight_id'] ?>">
                                <span class="match-fighter <?= $b1Won ? 'winner' : 'loser' ?>"><?= h($m['n1']) ?></span>
                                <span class="match-vs">vs</span>
                                <span class="match-fighter <?= $b2Won ? 'winner' : 'loser' ?>"><?= h($m['n2']) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
</main>

<script src="assets/js/tournament.js"></script>
</body>
</html>
