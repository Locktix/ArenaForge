<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_login();

$brute = current_brute();
$csrf  = csrf_token();

if ($brute) {
    header('Location: brute.php?id=' . (int)$brute['id']);
    exit;
}

$prefilledMaster = (string)($_GET['master'] ?? '');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Forge ton gladiateur – ArenaForge</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" href="../assets/svg/logo/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="../assets/css/main.css">
</head>
<body>
<?php include __DIR__ . '/_nav.php'; ?>

<main class="wrap">
    <section class="card create-card">
        <h1>Forge ton gladiateur</h1>
        <p>Choisis un nom. Son apparence et ses statistiques seront générées à partir de celui-ci.</p>

        <form id="create-form">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <label>Nom du gladiateur <input type="text" name="name" minlength="3" maxlength="20" pattern="[A-Za-z0-9_\-]+" required></label>
            <label>Maître (optionnel) <input type="text" name="master_name" maxlength="20" placeholder="Nom d'un gladiateur existant" value="<?= h($prefilledMaster) ?>"></label>
            <button type="submit" class="btn btn-primary">Entrer dans l'arène</button>
            <p class="form-msg" data-msg></p>
        </form>
    </section>
</main>

<script src="../assets/js/create.js"></script>
</body>
</html>
