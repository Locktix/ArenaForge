<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/clan_engine.php';
require_login();

$clanId = (int)($_GET['id'] ?? 0);
$clan   = get_clan($clanId);
if (!$clan) {
    http_response_code(404);
    echo 'Clan introuvable.';
    exit;
}

$members = get_clan_members($clanId);
$brute   = current_brute();
$bruteId = $brute ? (int)$brute['id'] : 0;
$csrf    = csrf_token();

$myMembership = null;
foreach ($members as $m) {
    if ((int)$m['id'] === $bruteId) { $myMembership = $m; break; }
}
$isMember = $myMembership !== null;
$isLeader = $myMembership !== null && $myMembership['role'] === 'leader';

$totalMmr = array_sum(array_map(fn($m) => (int)$m['mmr'], $members));
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title><?= h($clan['name']) ?> – Clan</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" href="../assets/svg/logo/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="../assets/css/main.css">
</head>
<body>
<?php include __DIR__ . '/_nav.php'; ?>

<main class="wrap">
    <section class="card">
        <div class="clan-header">
            <div class="clan-tag-lg">[<?= h($clan['tag']) ?>]</div>
            <div>
                <h1><?= h($clan['name']) ?></h1>
                <?php if ($clan['description']): ?>
                    <p class="muted"><?= h($clan['description']) ?></p>
                <?php endif; ?>
            </div>
            <div class="clan-meta">
                <div><strong><?= count($members) ?></strong><small>/<?= (int)$clan['max_members'] ?> membres</small></div>
                <div><strong><?= $totalMmr ?></strong><small>MMR cumulé</small></div>
            </div>
        </div>

        <div class="clan-actions">
            <?php if ($isMember): ?>
                <form class="clan-leave-form">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="brute_id" value="<?= $bruteId ?>">
                    <input type="hidden" name="action" value="leave">
                    <button type="submit" class="btn btn-primary">Quitter le clan</button>
                </form>
            <?php elseif ($brute && !$brute['clan_id'] && count($members) < (int)$clan['max_members']): ?>
                <form class="clan-join-form">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="brute_id" value="<?= $bruteId ?>">
                    <input type="hidden" name="action" value="join">
                    <input type="hidden" name="clan_id" value="<?= $clanId ?>">
                    <button type="submit" class="btn btn-secondary">Rejoindre</button>
                </form>
            <?php endif; ?>
            <a href="clans.php" class="btn btn-secondary">Tous les clans</a>
        </div>
    </section>

    <?php if ($isMember): ?>
        <section class="card clan-chat-card">
            <h2><img src="../assets/svg/ui/scroll.svg" alt="" class="inline-icon"> Chat du clan</h2>
            <div class="clan-chat-window" id="clan-chat-window" data-clan-id="<?= $clanId ?>" data-brute-id="<?= $bruteId ?>">
                <p class="muted small">Chargement…</p>
            </div>
            <form id="clan-chat-form" class="clan-chat-form">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="brute_id" value="<?= $bruteId ?>">
                <input type="text" name="body" maxlength="255" placeholder="Écris un message au clan…" autocomplete="off" required>
                <button type="submit" class="btn btn-secondary">Envoyer</button>
            </form>
        </section>
    <?php endif; ?>

    <section class="card">
        <h2>Membres</h2>
        <ul class="clan-members">
            <?php foreach ($members as $m): ?>
                <li class="clan-member <?= $m['role'] === 'leader' ? 'is-leader' : '' ?>">
                    <a href="brute.php?id=<?= (int)$m['id'] ?>" class="member-name">
                        <?= h($m['name']) ?>
                    </a>
                    <?php if ($m['role'] === 'leader'): ?>
                        <span class="role-badge leader">Chef</span>
                    <?php elseif ($m['role'] === 'officer'): ?>
                        <span class="role-badge officer">Officier</span>
                    <?php endif; ?>
                    <span class="member-level">Niv. <?= (int)$m['level'] ?></span>
                    <span class="member-mmr"><?= (int)$m['mmr'] ?> MMR</span>
                    <?php if ($isLeader && (int)$m['id'] !== $bruteId): ?>
                        <form class="clan-kick-form" style="display:inline-block; margin-left:auto;">
                            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                            <input type="hidden" name="brute_id" value="<?= $bruteId ?>">
                            <input type="hidden" name="action" value="kick">
                            <input type="hidden" name="target_id" value="<?= (int)$m['id'] ?>">
                            <button type="submit" class="btn btn-primary btn-sm" title="Renvoyer">✕</button>
                        </form>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
</main>

<script src="../assets/js/clan.js"></script>
<script src="../assets/js/clan_chat.js"></script>
</body>
</html>
