<?php
// FICHIER DE DIAGNOSTIC — SUPPRIMER APRÈS USAGE
error_reporting(E_ALL);
ini_set('display_errors', '1');

echo '<pre>';
echo 'PHP: ' . PHP_VERSION . "\n";
echo 'SAPI: ' . php_sapi_name() . "\n\n";

// Test elo_engine.php (cause principale du 500)
echo "Test elo_engine.php... ";
try {
    require_once __DIR__ . '/../includes/elo_engine.php';
    echo "OK\n";
} catch (Throwable $e) {
    echo "ERREUR: " . $e->getMessage() . "\n";
}

// Test auth.php
echo "Test auth.php... ";
try {
    require_once __DIR__ . '/../includes/auth.php';
    echo "OK\n";
} catch (Throwable $e) {
    echo "ERREUR: " . $e->getMessage() . "\n";
}

// Test connexion DB
echo "Test DB... ";
try {
    $pdo = db();
    echo "OK\n";
} catch (Throwable $e) {
    echo "ERREUR DB: " . $e->getMessage() . "\n";
}

echo '</pre>';
