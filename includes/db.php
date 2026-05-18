<?php
// Connexion PDO centralisée

declare(strict_types=1);

const DB_HOST    = 'localhost';
const DB_PORT    = 3306;
const DB_NAME    = 'cujo4479_arenaforge';
const DB_USER    = 'cujo4479_arenaforge';
const DB_PASS    = 'AlanAyaLove3@.';
const DB_CHARSET = 'utf8mb4';

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => true,
        ]);

        // Vérification automatique des migrations SQL
        require_once __DIR__ . '/migration_engine.php';
        check_migrations($pdo);
    }
    return $pdo;
}
