<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/challenge_engine.php';
require_login();

$brute = current_brute();
if (!$brute) {
    header('Location: dashboard.php');
    exit;
}

$bruteId = (int)$brute['id'];
$inbox   = get_inbox($bruteId);
$outbox  = get_outbox($bruteId);
$csrf    = csrf_token();
$tab     = ($_GET['tab'] ?? 'inbox') === 'sent' ? 'sent' : 'inbox';

function chal_status_label(string $status): string {
    return [
        'pending'  => 'En attente',
        'accepted' => 'Combat livré',
        'declined' => 'Refusé',
        'expired'  => 'Expiré',
    ][$status] ?? $status;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Défis — ArenaForge</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" href="../assets/svg/logo/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="../assets/css/main.css">
</head>
<body>
<?php include __DIR__ . '/_nav.php'; ?>

<main class="wrap">
    <section class="card">
        <h1><img src="../assets/svg/weapons/sword.svg" alt="" class="inline-icon"> Défis directs</h1>
        <p class="muted">Désigne un adversaire par son nom et lance-lui un défi. Si la cible accepte, le combat se déroule immédiatement. Les défis ne consomment aucun de tes 6 combats journaliers.</p>

        <form id="challenge-send-form" class="challenge-send">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="brute_id" value="<?= $bruteId ?>">
            <label>
                Adversaire
                <input type="text" name="target_name" placeholder="Nom du gladiateur" maxlength="20" required pattern="[A-Za-z0-9_\-]+">
            </label>
            <label>
                Message (optionnel)
                <input type="text" name="message" maxlength="140" placeholder="Une provocation ?">
            </label>
            <button type="submit" class="btn btn-primary">Envoyer le défi</button>
            <p class="form-msg" data-msg></p>
        </form>
    </section>

    <div class="tab-bar">
        <a href="challenges.php?tab=inbox" class="tab-btn <?= $tab === 'inbox' ? 'active' : '' ?>">📥 Reçus (<?= count(array_filter($inbox, fn($c) => $c['status'] === 'pending')) ?>)</a>
        <a href="challenges.php?tab=sent"  class="tab-btn <?= $tab === 'sent'  ? 'active' : '' ?>">📤 Envoyés</a>
    </div>

    <?php if ($tab === 'inbox'): ?>
        <section class="card">
            <h2>Boîte de réception</h2>
            <?php if (empty($inbox)): ?>
                <p class="muted">Aucun défi reçu pour l'instant.</p>
            <?php else: ?>
                <ul class="challenge-list">
                    <?php foreach ($inbox as $c): ?>
                        <li class="challenge-item challenge-status-<?= h($c['status']) ?>">
                            <div class="challenge-main">
                                <div class="challenge-header">
                                    <a href="brute.php?id=<?= (int)$c['challenger_id'] ?>" class="challenge-name"><?= h($c['challenger_name']) ?></a>
                                    <span class="muted small">Niv. <?= (int)$c['challenger_level'] ?> · <?= (int)$c['challenger_mmr'] ?> MMR</span>
                                </div>
                                <?php if (!empty($c['message'])): ?>
                                    <p class="challenge-message">« <?= h($c['message']) ?> »</p>
                                <?php endif; ?>
                                <p class="muted small">
                                    <?= chal_status_label($c['status']) ?>
                                    · <?= h(date('d/m H:i', strtotime((string)$c['created_at']))) ?>
                                </p>
                            </div>
                            <div class="challenge-actions">
                                <?php if ($c['status'] === 'pending'): ?>
                                    <form class="challenge-respond-form" data-action="accept">
                                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                        <input type="hidden" name="brute_id" value="<?= $bruteId ?>">
                                        <input type="hidden" name="challenge_id" value="<?= (int)$c['id'] ?>">
                                        <input type="hidden" name="action" value="accept">
                                        <button type="submit" class="btn btn-primary btn-sm">Accepter</button>
                                    </form>
                                    <form class="challenge-respond-form" data-action="decline">
                                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                        <input type="hidden" name="brute_id" value="<?= $bruteId ?>">
                                        <input type="hidden" name="challenge_id" value="<?= (int)$c['id'] ?>">
                                        <input type="hidden" name="action" value="decline">
                                        <button type="submit" class="btn btn-ghost btn-sm">Refuser</button>
                                    </form>
                                <?php elseif (!empty($c['fight_id'])): ?>
                                    <a class="btn btn-secondary btn-sm" href="fight.php?id=<?= (int)$c['fight_id'] ?>">Voir le combat</a>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    <?php else: ?>
        <section class="card">
            <h2>Défis envoyés</h2>
            <?php if (empty($outbox)): ?>
                <p class="muted">Aucun défi envoyé pour l'instant.</p>
            <?php else: ?>
                <ul class="challenge-list">
                    <?php foreach ($outbox as $c): ?>
                        <li class="challenge-item challenge-status-<?= h($c['status']) ?>">
                            <div class="challenge-main">
                                <div class="challenge-header">
                                    <span class="challenge-name">→ <a href="brute.php?id=<?= (int)$c['target_id'] ?>"><?= h($c['target_name']) ?></a></span>
                                    <span class="muted small">Niv. <?= (int)$c['target_level'] ?> · <?= (int)$c['target_mmr'] ?> MMR</span>
                                </div>
                                <?php if (!empty($c['message'])): ?>
                                    <p class="challenge-message">« <?= h($c['message']) ?> »</p>
                                <?php endif; ?>
                                <p class="muted small">
                                    <?= chal_status_label($c['status']) ?>
                                    · <?= h(date('d/m H:i', strtotime((string)$c['created_at']))) ?>
                                </p>
                            </div>
                            <div class="challenge-actions">
                                <?php if ($c['status'] === 'pending'): ?>
                                    <form class="challenge-respond-form" data-action="cancel">
                                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                        <input type="hidden" name="brute_id" value="<?= $bruteId ?>">
                                        <input type="hidden" name="challenge_id" value="<?= (int)$c['id'] ?>">
                                        <input type="hidden" name="action" value="cancel">
                                        <button type="submit" class="btn btn-ghost btn-sm">Annuler</button>
                                    </form>
                                <?php elseif (!empty($c['fight_id'])): ?>
                                    <a class="btn btn-secondary btn-sm" href="fight.php?id=<?= (int)$c['fight_id'] ?>">Voir le combat</a>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</main>

<script src="../assets/js/challenges.js"></script>
</body>
</html>
