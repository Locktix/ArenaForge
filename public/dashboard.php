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

// Pets de base uniquement (sans évolutions)
$basePets = db()->query("SELECT id, name, description, icon_path FROM pets WHERE evolves_from IS NULL AND rarity = 'commun' ORDER BY id")->fetchAll();
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
        <h1>🗡️ L'Appel de la Gloire</h1>
        <p class="muted">Chaque légende commence par un nom. Choisissez-le avec soin : votre apparence et vos attributs divins en découleront par la force du destin.</p>

        <form id="create-form" style="margin-top: 24px;">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <label>Identité du Guerrier <input type="text" name="name" minlength="3" maxlength="20" pattern="[A-Za-z0-9_\-]+" placeholder="Ex: Maximus" required></label>
            <label>Lignage (Maître optionnel) <input type="text" name="master_name" maxlength="20" placeholder="Nom de votre mentor" value="<?= h($prefilledMaster) ?>"></label>

            <?php if (!empty($basePets)): ?>
            <div class="create-pet-section">
                <p class="create-pet-label">Choisis ton compagnon</p>
                <p class="muted small create-pet-hint">Il t'accompagnera dans l'arène. Fais confiance à ton instinct.</p>
                <div class="create-pet-grid">
                    <?php foreach ($basePets as $i => $p): ?>
                    <label class="create-pet-card <?= $i === 0 ? 'create-pet-card--selected' : '' ?>">
                        <input type="radio" name="pet_id" value="<?= (int)$p['id'] ?>" <?= $i === 0 ? 'checked' : '' ?> required>
                        <img src="../<?= h($p['icon_path']) ?>" alt="<?= h($p['name']) ?>" class="create-pet-img">
                        <strong class="create-pet-name"><?= h($p['name']) ?></strong>
                        <p class="create-pet-desc muted small"><?= h($p['description']) ?></p>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <button type="submit" class="btn btn-primary btn-large btn-hero" style="width: 100%; margin-top: 8px;">FORGER MON DESTIN</button>
            <p class="form-msg" data-msg></p>
        </form>
    </section>
<script src="../assets/js/create.js"></script>
</main>
</body>
</html>
