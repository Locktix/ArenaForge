<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/clan_engine.php';
require_login();

$brute = current_brute();
if (!$brute) {
    header('Location: dashboard.php');
    exit;
}

$bruteId = (int)$brute['id'];
$csrf    = csrf_token();
$myClan  = get_brute_clan($bruteId);

// Si dans un clan, rediriger vers sa page
if ($myClan) {
    header('Location: clan.php?id=' . (int)$myClan['id']);
    exit;
}

$clans = list_clans(50);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Clans – ArenaForge</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" href="/ArenaForge/assets/svg/logo/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="/ArenaForge/assets/css/main.css">
</head>
<body>
<?php include __DIR__ . '/_nav.php'; ?>

<main class="wrap">
    <section class="card">
        <h1><img src="/ArenaForge/assets/svg/ui/trophy.svg" alt="" class="inline-icon"> Clans</h1>
        <p class="muted">
            Rejoins un clan pour progresser en équipe. Le classement des clans est basé sur le MMR cumulé des membres.
            Créer un clan coûte <strong><?= CLAN_CREATE_XP_COST ?> XP</strong>.
        </p>
    </section>

    <section class="card">
        <h2>Créer un clan</h2>
        <form id="clan-create-form" class="clan-create-form">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="brute_id" value="<?= $bruteId ?>">
            <input type="hidden" name="action" value="create">
            <label>Nom du clan
                <input type="text" name="name" required minlength="3" maxlength="40" placeholder="Les Lions de Rome">
            </label>
            <label>Tag (2-5 lettres MAJ/chiffres)
                <input type="text" name="tag" required minlength="2" maxlength="5" pattern="[A-Z0-9]{2,5}" placeholder="LION">
            </label>
            <label>Description (optionnelle)
                <input type="text" name="description" maxlength="255" placeholder="Un clan de combattants aguerris.">
            </label>
            <button type="submit" class="btn btn-secondary">Fonder le clan (<?= CLAN_CREATE_XP_COST ?> XP)</button>
            <p class="form-msg" data-msg></p>
        </form>
    </section>

    <section class="card">
        <h2>Clans existants</h2>
        <?php if (empty($clans)): ?>
            <p class="muted">Aucun clan pour l'instant. Sois le premier à en fonder un !</p>
        <?php else: ?>
            <div class="clan-list">
                <?php foreach ($clans as $c): ?>
                    <div class="clan-tile">
                        <div class="clan-tag">[<?= h($c['tag']) ?>]</div>
                        <div class="clan-info">
                            <strong><?= h($c['name']) ?></strong>
                            <?php if ($c['description']): ?>
                                <p class="muted small"><?= h($c['description']) ?></p>
                            <?php endif; ?>
                            <p class="clan-stats">
                                <span><?= (int)$c['member_count'] ?>/<?= (int)$c['max_members'] ?> membres</span>
                                <span>MMR cumulé : <strong><?= (int)$c['total_mmr'] ?></strong></span>
                                <span>Niveau moyen : <?= number_format((float)$c['avg_level'], 1) ?></span>
                            </p>
                        </div>
                        <div class="clan-action">
                            <?php if ((int)$c['member_count'] < (int)$c['max_members']): ?>
                                <form class="clan-join-form">
                                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                    <input type="hidden" name="brute_id" value="<?= $bruteId ?>">
                                    <input type="hidden" name="action" value="join">
                                    <input type="hidden" name="clan_id" value="<?= (int)$c['id'] ?>">
                                    <button type="submit" class="btn btn-secondary">Rejoindre</button>
                                </form>
                            <?php else: ?>
                                <span class="clan-full">Complet</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</main>

<script src="/ArenaForge/assets/js/clan.js"></script>
</body>
</html>
