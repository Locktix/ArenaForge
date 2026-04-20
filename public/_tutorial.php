<?php
// Injection du state de tutoriel pour la page courante.
// À inclure dans le <body> de chaque page protégée après le nav.

if (!function_exists('tutorial_step_for_page')) {
    require_once __DIR__ . '/../includes/tutorial.php';
}

$tutorialUser = current_user_id();
$tutorialStep = null;
if ($tutorialUser !== null) {
    $tutorialCurrentPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $tutorialStep = tutorial_step_for_page($tutorialUser, $tutorialCurrentPage);
}
?>
<script>
window.TUTORIAL = <?= json_encode($tutorialStep, JSON_UNESCAPED_UNICODE) ?>;
window.TUTORIAL_CSRF = <?= json_encode(csrf_token()) ?>;
</script>
<script src="../assets/js/tutorial.js" defer></script>
