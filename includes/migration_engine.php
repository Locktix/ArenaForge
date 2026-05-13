<?php
/**
 * MigrationEngine - Système de synchronisation déclaratif de la base de données.
 * Au lieu de fichiers SQL, on définit ici l'état souhaité de la base.
 */

declare(strict_types=1);

/**
 * Point d'entrée principal pour la synchronisation.
 */
function check_migrations(PDO $pdo): void
{
    // 1. Initialisation de base si la table 'users' n'existe pas
    if (!table_exists($pdo, 'users')) {
        run_sql_file($pdo, __DIR__ . '/../sql/schema.sql');
    }

    // 2. Synchronisation des colonnes et tables (Evolutions)
    
    // --- Table USERS ---
    ensure_column($pdo, 'users', 'streak_days', 'INT UNSIGNED NOT NULL DEFAULT 0');
    ensure_column($pdo, 'users', 'last_login_date', 'DATE NULL');
    ensure_column($pdo, 'users', 'streak_claim_date', 'DATE NULL');
    ensure_column($pdo, 'users', 'tutorial_skipped', 'TINYINT(1) NOT NULL DEFAULT 0');

    // --- Table BRUTES ---
    ensure_column($pdo, 'brutes', 'gold', 'INT UNSIGNED NOT NULL DEFAULT 0');
    ensure_column($pdo, 'brutes', 'levelup_choices', 'TEXT NULL AFTER pending_levelup');
    ensure_column($pdo, 'brutes', 'bonus_fights_available', 'INT UNSIGNED NOT NULL DEFAULT 0');
    ensure_column($pdo, 'brutes', 'pupil_bonus_progress', 'INT UNSIGNED NOT NULL DEFAULT 0');

    // --- Table TOURNAMENTS ---
    ensure_column($pdo, 'tournaments', 'type', "ENUM('daily','weekly') NOT NULL DEFAULT 'daily' AFTER tour_date");
    // Suppression de l'ancienne clé unique simple si elle existe
    drop_index($pdo, 'tournaments', 'tour_date');
    // Ajout de la nouvelle clé composite
    ensure_unique_index($pdo, 'tournaments', 'uk_tour_date_type', ['tour_date', 'type']);

    // --- Table QUEST_DEFINITIONS ---
    ensure_column($pdo, 'quest_definitions', 'scope', "ENUM('daily','weekly') NOT NULL DEFAULT 'daily' AFTER code");

    // --- NOUVELLES TABLES ---
    
    // Quêtes hebdomadaires
    ensure_table($pdo, 'brute_weekly_quests', "
        brute_id INT UNSIGNED NOT NULL,
        quest_code VARCHAR(40) NOT NULL,
        quest_week DATE NOT NULL,
        progress INT UNSIGNED NOT NULL DEFAULT 0,
        claimed TINYINT(1) NOT NULL DEFAULT 0,
        PRIMARY KEY (brute_id, quest_code, quest_week),
        CONSTRAINT fk_bwq_brute FOREIGN KEY (brute_id) REFERENCES brutes(id) ON DELETE CASCADE,
        CONSTRAINT fk_bwq_quest FOREIGN KEY (quest_code) REFERENCES quest_definitions(code) ON DELETE CASCADE
    ");

    // Récompenses de saison
    ensure_table($pdo, 'season_rewards', "
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        season_id INT UNSIGNED NOT NULL,
        brute_id INT UNSIGNED NOT NULL,
        final_mmr INT NOT NULL DEFAULT 1000,
        tier_code VARCHAR(20) NOT NULL,
        gold_awarded INT UNSIGNED NOT NULL DEFAULT 0,
        awarded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_season_brute (season_id, brute_id)
    ");

    // Boss quotidien
    ensure_table($pdo, 'daily_bosses', "
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        boss_date DATE NOT NULL UNIQUE,
        name VARCHAR(40) NOT NULL,
        level INT UNSIGNED NOT NULL,
        hp_max INT UNSIGNED NOT NULL,
        appearance_seed TEXT NOT NULL
    ");

    // Tentatives de boss
    ensure_table($pdo, 'boss_attempts', "
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        boss_id INT UNSIGNED NOT NULL,
        brute_id INT UNSIGNED NOT NULL,
        damage_dealt INT UNSIGNED NOT NULL DEFAULT 0,
        won TINYINT(1) NOT NULL DEFAULT 0,
        UNIQUE KEY uk_boss_brute (boss_id, brute_id)
    ");

    // Défis PvP
    ensure_table($pdo, 'challenges', "
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        challenger_id INT UNSIGNED NOT NULL,
        target_id INT UNSIGNED NOT NULL,
        status ENUM('pending','accepted','declined','expired') NOT NULL DEFAULT 'pending',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ");

    // 3. Insertion de données vitales (Seeds)
    sync_weekly_quests($pdo);
}

/**
 * Vérifie si une table existe.
 */
function table_exists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
        return (bool)$stmt->fetch();
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Assure qu'une colonne existe avec la définition donnée.
 */
function ensure_column(PDO $pdo, string $table, string $column, string $definition): void
{
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $stmt->execute([$column]);
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}

/**
 * Assure qu'une table existe.
 */
function ensure_table(PDO $pdo, string $table, string $definition): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS `$table` ($definition) ENGINE=InnoDB;");
}

/**
 * Supprime un index s'il existe.
 */
function drop_index(PDO $pdo, string $table, string $indexName): void
{
    try {
        // On vérifie d'abord si c'est une clé étrangère ou un index classique
        $pdo->exec("ALTER TABLE `$table` DROP INDEX `$indexName` ");
    } catch (Exception $e) {
        // L'index n'existe probablement pas, on ignore
    }
}

/**
 * Assure qu'un index unique existe.
 */
function ensure_unique_index(PDO $pdo, string $table, string $indexName, array $columns): void
{
    try {
        $cols = implode('`, `', $columns);
        $pdo->exec("ALTER TABLE `$table` ADD UNIQUE KEY `$indexName` (`$cols`)");
    } catch (Exception $e) {
        // Déjà présent ou erreur, on ignore
    }
}

/**
 * Exécute un fichier SQL (pour le schéma de base).
 */
function run_sql_file(PDO $pdo, string $path): void
{
    if (!file_exists($path)) return;
    $sql = file_get_contents($path);
    // Nettoyage standard pour PDO
    $sql = preg_replace('/CREATE\s+DATABASE\s+IF\s+NOT\s+EXISTS\s+[^;]+;/i', '', $sql);
    $sql = preg_replace('/USE\s+[^;]+;/i', '', $sql);
    try {
        $pdo->exec($sql);
    } catch (PDOException $e) {
        // En cas d'erreur fatale au démarrage
        die("Erreur initialisation : " . $e->getMessage());
    }
}

/**
 * Synchronise les définitions des quêtes hebdomadaires.
 */
function sync_weekly_quests(PDO $pdo): void
{
    $quests = [
        ['w_win_20',      'weekly', 'Inarretable',           'Remporter 20 victoires dans la semaine.',          20, 40, 2, 'assets/svg/quests/trophy.svg'],
        ['w_crit_20',     'weekly', 'Frappe du destin',      'Placer 20 coups critiques dans la semaine.',       20, 30, 1, 'assets/svg/quests/crit.svg'],
        ['w_dodge_30',    'weekly', 'Fantome',               'Esquiver 30 attaques dans la semaine.',            30, 30, 1, 'assets/svg/quests/dodge.svg'],
        ['w_damage_1000', 'weekly', 'Demolisseur',           'Infliger 1000 degats cumules dans la semaine.',  1000, 35, 1, 'assets/svg/quests/hammer.svg'],
        ['w_flawless_3',  'weekly', 'Intouchable',           'Remporter 3 victoires sans subir de degats.',       3, 45, 2, 'assets/svg/quests/shield.svg'],
        ['w_upset_3',     'weekly', 'Pourfendeur de titans', 'Battre 3 adversaires de niveau superieur.',         3, 40, 2, 'assets/svg/quests/crown.svg']
    ];

    $stmt = $pdo->prepare("INSERT IGNORE INTO quest_definitions (code, scope, label, description, target, reward_xp, reward_bonus_fights, icon_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($quests as $q) {
        $stmt->execute($q);
    }
}
