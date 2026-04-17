<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';

if (current_user_id() !== null) {
    header('Location: dashboard.php');
    exit;
}
$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>ArenaForge</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" href="assets/svg/logo/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="assets/css/main.css">
</head>
<body class="landing">
<main class="landing-wrap">
    <header class="landing-header">
        <object data="assets/svg/logo/logo.svg" type="image/svg+xml" class="logo" aria-label="ArenaForge"></object>
        <p class="tagline">Forge ton gladiateur, combats dans l'arène, domine le classement.</p>
    </header>

    <section class="auth-grid">
        <form class="card auth-card" id="login-form">
            <h2>Connexion</h2>
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <label>E-mail <input type="email" name="email" required autocomplete="email"></label>
            <label>Mot de passe <input type="password" name="password" required autocomplete="current-password"></label>
            <button type="submit" class="btn btn-primary">Entrer dans l'arène</button>
            <p class="form-msg" data-msg></p>
        </form>

        <form class="card auth-card" id="register-form">
            <h2>Inscription</h2>
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <label>E-mail <input type="email" name="email" required autocomplete="email"></label>
            <label>Mot de passe <input type="password" name="password" required minlength="6" autocomplete="new-password"></label>
            <button type="submit" class="btn btn-secondary">Forger un compte</button>
            <p class="form-msg" data-msg></p>
        </form>
    </section>

    <footer class="landing-footer">
        <p>Compte de démo : <code>demo@arenaforge.local</code> / <code>demodemo</code></p>
    </footer>
</main>
<script src="assets/js/auth.js"></script>
</body>
</html>
