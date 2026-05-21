<?php
/**
 * MigrationEngine - Système de synchronisation déclaratif de la base de données.
 */

declare(strict_types=1);

/**
 * Point d'entrée principal pour la synchronisation.
 */
function check_migrations(PDO $pdo): void
{
    try {
        // 1. Initialisation de base si la table 'users' n'existe pas
        if (!table_exists($pdo, 'users')) {
            run_sql_file($pdo, __DIR__ . '/../sql/schema.sql');
        }

        // 2. Synchronisation des colonnes et tables
        
        // --- Table USERS ---
        if (table_exists($pdo, 'users')) {
            ensure_column($pdo, 'users', 'streak_days', 'INT UNSIGNED NOT NULL DEFAULT 0');
            ensure_column($pdo, 'users', 'last_login_date', 'DATE NULL');
            ensure_column($pdo, 'users', 'streak_claim_date', 'DATE NULL');
            ensure_column($pdo, 'users', 'tutorial_skipped', 'TINYINT(1) NOT NULL DEFAULT 0');
        }

        // --- Table BRUTES ---
        if (table_exists($pdo, 'brutes')) {
            ensure_column($pdo, 'brutes', 'gold', 'INT UNSIGNED NOT NULL DEFAULT 0');
            ensure_column($pdo, 'brutes', 'levelup_choices', 'TEXT NULL AFTER pending_levelup');
            ensure_column($pdo, 'brutes', 'bonus_fights_available', 'INT UNSIGNED NOT NULL DEFAULT 0');
            ensure_column($pdo, 'brutes', 'pupil_bonus_progress', 'INT UNSIGNED NOT NULL DEFAULT 0');
        }

        // --- Table TOURNAMENTS ---
        if (table_exists($pdo, 'tournaments')) {
            ensure_column($pdo, 'tournaments', 'type', "ENUM('daily','weekly') NOT NULL DEFAULT 'daily' AFTER tour_date");
            drop_index($pdo, 'tournaments', 'tour_date');
            ensure_unique_index($pdo, 'tournaments', 'uk_tour_date_type', ['tour_date', 'type']);
        }

        // --- Table QUEST_DEFINITIONS ---
        if (table_exists($pdo, 'quest_definitions')) {
            ensure_column($pdo, 'quest_definitions', 'scope', "ENUM('daily','weekly') NOT NULL DEFAULT 'daily' AFTER code");
        }

        // --- NOUVELLES TABLES ---
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

        ensure_table($pdo, 'daily_bosses', "
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            boss_date DATE NOT NULL UNIQUE,
            name VARCHAR(40) NOT NULL,
            level INT UNSIGNED NOT NULL,
            hp_max INT UNSIGNED NOT NULL,
            appearance_seed TEXT NOT NULL
        ");

        ensure_table($pdo, 'boss_attempts', "
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            boss_id INT UNSIGNED NOT NULL,
            brute_id INT UNSIGNED NOT NULL,
            damage_dealt INT UNSIGNED NOT NULL DEFAULT 0,
            won TINYINT(1) NOT NULL DEFAULT 0,
            UNIQUE KEY uk_boss_brute (boss_id, brute_id)
        ");

        ensure_table($pdo, 'challenges', "
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            challenger_id INT UNSIGNED NOT NULL,
            target_id INT UNSIGNED NOT NULL,
            status ENUM('pending','accepted','declined','expired') NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ");

        // --- Boss PvP : Trône du Maître ---
        ensure_table($pdo, 'pvp_boss_throne', "
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            brute_id INT UNSIGNED NOT NULL,
            since_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_xp_award_date DATE NULL,
            defense_wins INT UNSIGNED NOT NULL DEFAULT 0,
            UNIQUE KEY uk_throne_brute (brute_id)
        ");

        ensure_table($pdo, 'pvp_boss_log', "
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            brute_id INT UNSIGNED NOT NULL,
            became_boss_at DATETIME NOT NULL,
            dethroned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            dethroned_by INT UNSIGNED NULL,
            defense_wins INT UNSIGNED NOT NULL DEFAULT 0
        ");

        // --- Mini-jeux ---
        if (table_exists($pdo, 'brutes')) {
            ensure_column($pdo, 'brutes', 'minigame_claimed_at', 'DATETIME NULL');
            ensure_column($pdo, 'brutes', 'boss_last_challenge_date', 'DATE NULL');
        }

        ensure_table($pdo, 'minigame_scores', "
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            brute_id INT UNSIGNED NOT NULL,
            game VARCHAR(20) NOT NULL DEFAULT 'snake',
            score INT UNSIGNED NOT NULL,
            achieved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_game_score (game, score DESC)
        ");

        // 3. Insertion de données vitales
        if (table_exists($pdo, 'quest_definitions')) {
            sync_weekly_quests($pdo);
        }

    } catch (Throwable $t) {
        // En cas d'erreur, on affiche un message propre au lieu d'une erreur 500 brute
        die("<html><body style='font-family:sans-serif;padding:20px;'>
            <h2 style='color:#d9534f;'>Erreur de Synchronisation BDD</h2>
            <p>Une erreur est survenue lors de la mise à jour automatique de la base de données :</p>
            <pre style='background:#f8f8f8;padding:10px;border:1px solid #ddd;'>" . htmlspecialchars($t->getMessage()) . "</pre>
            <p>Veuillez vérifier vos accès MySQL ou contacter le support.</p>
        </body></html>");
    }
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
        $pdo->exec("ALTER TABLE `$table` DROP INDEX `$indexName` ");
    } catch (Exception $e) {
        // Ignore
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
        // Ignore
    }
}

/**
 * Exécute un fichier SQL (pour le schéma de base).
 */
function run_sql_file(PDO $pdo, string $path): void
{
    if (!file_exists($path)) return;
    $sql = file_get_contents($path);
    
    // Nettoyage des commentaires et séparation des requêtes
    $sql = preg_replace('/--.*$/m', '', $sql);
    $queries = explode(';', $sql);

    foreach ($queries as $query) {
        $query = trim($query);
        if (empty($query)) continue;
        
        // Ignorer CREATE DATABASE et USE
        if (preg_match('/^(CREATE\s+DATABASE|USE)\b/i', $query)) continue;

        try {
            $pdo->exec($query);
        } catch (PDOException $e) {
            // Ignorer si déjà existant
            if (str_contains($e->getMessage(), 'already exists')) continue;
            throw $e;
        }
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
