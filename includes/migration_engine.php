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
            ensure_column($pdo, 'users', 'last_seen_changelog_version', 'VARCHAR(20) NULL');
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

        // --- Table WEAPONS ---
        if (table_exists($pdo, 'weapons')) {
            ensure_column($pdo, 'weapons', 'defense_bonus', 'TINYINT UNSIGNED NOT NULL DEFAULT 0');
            $pdo->exec("UPDATE weapons SET defense_bonus = 2 WHERE name = 'Bouclier' AND defense_bonus = 0");

            ensure_column($pdo, 'weapons', 'rarity', "ENUM('commun','rare','epique') NOT NULL DEFAULT 'commun'");
            $pdo->exec("UPDATE weapons SET rarity = 'rare'   WHERE name IN ('Epee','Masse','Lance') AND rarity = 'commun'");
            $pdo->exec("UPDATE weapons SET rarity = 'epique' WHERE name = 'Hache' AND rarity = 'commun'");
            // Bouclier de base → rare (correction de l'assignation précédente)
            $pdo->exec("UPDATE weapons SET rarity = 'rare' WHERE name = 'Bouclier'");

            // Dégâts Hache revus (8-15)
            $pdo->exec("UPDATE weapons SET damage_min = 8, damage_max = 15 WHERE name = 'Hache'");

            // Niveau minimum de déblocage par arme
            ensure_column($pdo, 'weapons', 'min_level', 'TINYINT UNSIGNED NOT NULL DEFAULT 1');
            $pdo->exec("UPDATE weapons SET min_level = 0  WHERE name = 'Poings nus' AND min_level = 1");
            $pdo->exec("UPDATE weapons SET min_level = 3  WHERE name = 'Lance'      AND min_level = 1");
            $pdo->exec("UPDATE weapons SET min_level = 5  WHERE name IN ('Epee','Bouclier') AND min_level = 1");
            $pdo->exec("UPDATE weapons SET min_level = 7  WHERE name = 'Masse'      AND min_level = 1");
            $pdo->exec("UPDATE weapons SET min_level = 10 WHERE name = 'Hache'      AND min_level = 1");

            // Bouclier en acier (épique, défense pure, niveau 10)
            $pdo->exec("INSERT IGNORE INTO weapons (name, damage_min, damage_max, defense_bonus, rarity, min_level, icon_path)
                VALUES ('Bouclier en acier', 0, 0, 4, 'epique', 10, 'assets/svg/weapons/shield.svg')");
        }

        // --- Rarités skills & pets + évolutions ---
        if (table_exists($pdo, 'skills')) {
            ensure_column($pdo, 'skills', 'rarity', "ENUM('commun','rare','epique') NOT NULL DEFAULT 'commun'");
            $pdo->exec("UPDATE skills SET rarity = 'rare'   WHERE name IN ('Coup critique','Rage') AND rarity = 'commun'");
            $pdo->exec("UPDATE skills SET rarity = 'epique' WHERE name = 'Vol de vie'              AND rarity = 'commun'");
        }
        if (table_exists($pdo, 'pets')) {
            ensure_column($pdo, 'pets', 'rarity',       "ENUM('commun','rare','epique') NOT NULL DEFAULT 'commun'");
            ensure_column($pdo, 'pets', 'evolves_from', 'INT UNSIGNED NULL');
            // Tous les pets de base → commun, évolutions → rare
            $pdo->exec("UPDATE pets SET rarity = 'commun' WHERE name IN ('Chien','Loup','Panthere','Ours')");
            $pdo->exec("UPDATE pets SET rarity = 'rare' WHERE name IN ('Molosse','Loup Alpha','Sphinx','Ours-Roi')");
            // Insérer les évolutions (idempotent via INSERT IGNORE)
            $pdo->exec("
                INSERT IGNORE INTO pets (name, species, hp_max, damage_min, damage_max, agility, description, icon_path, rarity, evolves_from)
                SELECT 'Molosse','dog',42,6,10,6,'Chien de guerre imposant, morsure devastatrice.','assets/svg/pets/molosse.svg','rare', id
                FROM pets WHERE name = 'Chien' LIMIT 1
            ");
            $pdo->exec("
                INSERT IGNORE INTO pets (name, species, hp_max, damage_min, damage_max, agility, description, icon_path, rarity, evolves_from)
                SELECT 'Loup Alpha','wolf',55,8,13,8,'Chef de meute, instinct aiguise et force brutale.','assets/svg/pets/wolf_alpha.svg','rare', id
                FROM pets WHERE name = 'Loup' LIMIT 1
            ");
            $pdo->exec("
                INSERT IGNORE INTO pets (name, species, hp_max, damage_min, damage_max, agility, description, icon_path, rarity, evolves_from)
                SELECT 'Sphinx','panther',46,11,17,10,'Creature mythique, vitesse et puissance legendaires.','assets/svg/pets/sphinx.svg','rare', id
                FROM pets WHERE name = 'Panthere' LIMIT 1
            ");
            $pdo->exec("
                INSERT IGNORE INTO pets (name, species, hp_max, damage_min, damage_max, agility, description, icon_path, rarity, evolves_from)
                SELECT 'Ours-Roi','bear',90,14,21,4,'Titan des forets, aucune armure ne lui resiste.','assets/svg/pets/bear_king.svg','rare', id
                FROM pets WHERE name = 'Ours' LIMIT 1
            ");
        }

        // --- Sacrifices ---
        ensure_table($pdo, 'sacrifices', "
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            brute_id INT UNSIGNED NOT NULL,
            type VARCHAR(20) NOT NULL,
            outcome_code VARCHAR(40) NOT NULL,
            outcome_label TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_brute_type_date (brute_id, type, created_at)
        ");

        // --- Notifications persistantes ---
        ensure_table($pdo, 'notifications', "
            id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            brute_id   INT UNSIGNED NOT NULL,
            kind       VARCHAR(30)  NOT NULL DEFAULT 'info',
            title      VARCHAR(160) NOT NULL,
            body       VARCHAR(320) NOT NULL DEFAULT '',
            href       VARCHAR(160) NOT NULL DEFAULT '',
            icon       VARCHAR(120) NOT NULL DEFAULT 'assets/svg/ui/scroll.svg',
            read_at    DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_brute_notif (brute_id, created_at DESC)
        ");
        // Garantit que les colonnes texte supportent les emoji 4 octets (utf8mb4)
        $pdo->exec("ALTER TABLE notifications
            CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        // Purge les notifs de titre corrompues (??) stockées avant la migration utf8mb4
        $pdo->exec("DELETE FROM notifications WHERE kind = 'title' AND title LIKE '?? %'");
        if (table_exists($pdo, 'brutes')) {
            ensure_column($pdo, 'brutes', 'notifs_unread_count', 'INT UNSIGNED NOT NULL DEFAULT 0');
        }

        // --- Titres de gloire ---
        ensure_table($pdo, 'titles', "
            code           VARCHAR(40)  NOT NULL PRIMARY KEY,
            label          VARCHAR(80)  NOT NULL,
            description    VARCHAR(200) NOT NULL DEFAULT '',
            flavor         VARCHAR(240) NOT NULL DEFAULT '',
            bonus_json     VARCHAR(120) NOT NULL DEFAULT '{}',
            rarity         ENUM('rare','epique','legendaire') NOT NULL DEFAULT 'rare',
            icon_path      VARCHAR(120) NOT NULL DEFAULT 'assets/svg/ui/trophy.svg',
            sort_order     INT UNSIGNED NOT NULL DEFAULT 100
        ");
        ensure_table($pdo, 'brute_titles', "
            brute_id    INT UNSIGNED NOT NULL,
            title_code  VARCHAR(40)  NOT NULL,
            unlocked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (brute_id, title_code),
            KEY idx_bt_brute (brute_id)
        ");
        if (table_exists($pdo, 'brutes')) {
            ensure_column($pdo, 'brutes', 'active_title_code', 'VARCHAR(40) NULL');
            ensure_column($pdo, 'brutes', 'win_streak',        'INT UNSIGNED NOT NULL DEFAULT 0');

            // Détection rétroactive : si la colonne win_streak_best n'existait pas,
            // on scanne l'historique de tous les combats pour recalculer la meilleure
            // série de victoires de chaque brute. Idempotent par construction
            // (n'arrive qu'une fois, à la création de la colonne).
            $chk = $pdo->prepare("SHOW COLUMNS FROM `brutes` LIKE 'win_streak_best'");
            $chk->execute();
            $needsBackfill = !$chk->fetch();
            ensure_column($pdo, 'brutes', 'win_streak_best', 'INT UNSIGNED NOT NULL DEFAULT 0');
            if ($needsBackfill && table_exists($pdo, 'fights')) {
                backfill_win_streak_best($pdo);
            }
        }

        // Définitions des titres (idempotent)
        $pdo->exec("INSERT INTO titles (code, label, description, flavor, bonus_json, rarity, icon_path, sort_order) VALUES
            ('invaincu',    'L''Invaincu',                  '20 victoires consécutives',                                   'La défaite est un mot dans une langue qu''il n''a jamais apprise.',                       '{\"agility\":2}',                  'rare',       'assets/svg/ui/trophy.svg', 10),
            ('parfait',     'Le Parfait',                   'Remporter un combat sans subir un seul point de dégât',       'Ses adversaires affirment l''avoir frappé. Pourtant aucune marque ne le confirme.',       '{\"endurance\":2}',                'rare',       'assets/svg/ui/trophy.svg', 20),
            ('fleau',       'Fléau de l''Arène',            '500 victoires au total',                                      'Le sable de l''arène reconnaît son pas avant même qu''il n''entre.',                      '{\"strength\":3}',                 'epique',     'assets/svg/ui/trophy.svg', 30),
            ('seigneur',    'Seigneur de Rome',             'Finir une saison au palier Légende',                          'Il ne brigue pas les couronnes : elles tombent à ses pieds.',                              '{\"strength\":2}',                 'epique',     'assets/svg/quests/crown.svg', 40),
            ('gladiateur',  'Gladiateur des Gladiateurs',   'Remporter 5 tournois',                                        'Cinq couronnes, cinq foules muettes. La sixième n''ose pas encore le regarder.',          '{\"strength\":2,\"endurance\":1}', 'epique',     'assets/svg/quests/crown.svg', 50),
            ('intouchable', 'L''Intouchable',               'Remporter un tournoi sans perdre un seul round',              'Trois combats, trois victoires nettes. Le sable est resté immaculé.',                     '{\"agility\":3}',                  'epique',     'assets/svg/ui/trophy.svg', 60),
            ('maudit',      'Maudit des Dieux',             'Effectuer 10 sacrifices à l''autel',                          'Il a tout donné aux dieux. Les dieux, eux, lui ont rendu sa rage intacte.',               '{\"hp_max\":5}',                   'epique',     'assets/svg/ui/scroll.svg', 70),
            ('eternel',     'Éternel Champion',             '3 saisons consécutives au palier Légende',                    'Les saisons changent. Le maître, jamais.',                                                '{\"strength\":2,\"agility\":2}',   'legendaire', 'assets/svg/quests/crown.svg', 80),
            ('mars',        'Fils de Mars',                 '500 victoires, 1 saison Légende et 5 tournois remportés',     'Quand Mars descendit dans l''arène, il prit ses traits. Et n''en repartit jamais.',       '{\"strength\":3,\"agility\":3}',   'legendaire', 'assets/svg/quests/crown.svg', 90),
            -- Titres de complétion (100 % d''une catégorie de trophées)
            ('compl_combat',      'Maître du Sang',            'Décroche tous les trophées Combat',                           'Douze exploits de sang et d''acier. Le combat n''a plus de secret pour lui.',               '{\"strength\":4,\"agility\":2}',                    'epique',     'assets/svg/quests/sword.svg',   110),
            ('compl_minigame',    'Roi des Mini-Jeux',         'Décroche tous les trophées Mini-Jeux',                        'Cinquante pommes, zéro pitié. Maintenant il s''ennuie.',                                    '{\"agility\":3,\"strength\":2}',                    'epique',     'assets/svg/ui/scroll.svg',      120),
            ('compl_social',      'Père de l''Arène',          'Décroche tous les trophées Social',                           'Il a fondé, formé, fédéré. L''arène porte sa marque sur chaque nouvelle génération.',       '{\"agility\":3,\"hp_max\":8}',                      'epique',     'assets/svg/ui/nav_pupils.svg',  130),
            ('compl_forge',       'Le Forgeron Légendaire',    'Décroche tous les trophées Forge',                            'Son enclume ne refroidit jamais. Ses armes non plus.',                                      '{\"strength\":2,\"endurance\":2}',                  'rare',       'assets/svg/weapons/axe.svg',    140),
            ('compl_tournament',  'Roi des Colisées',          'Décroche tous les trophées Tournoi',                          'Champion, finaliste, participant. Il a tout vécu du colisée.',                              '{\"strength\":2,\"agility\":2}',                    'rare',       'assets/svg/quests/crown.svg',   150),
            ('compl_collection',  'Le Collectionneur',         'Décroche tous les trophées Collection',                       'Armes, compétences, compagnon — son arsenal est un musée de la victoire.',                  '{\"agility\":2,\"endurance\":2}',                   'rare',       'assets/svg/weapons/sword.svg',  160),
            ('compl_progression', 'L''Ascendant',              'Décroche tous les trophées Progression',                      'Chaque niveau était un obstacle. Il les a tous laissés derrière.',                         '{\"strength\":2,\"endurance\":2}',                  'rare',       'assets/svg/ui/nav_ranking.svg', 170),
            ('omniscient',        'L''Omniscient',             'Complète les 7 catégories de trophées à 100 %',               'Les dieux l''observaient. Puis ils ont pris des notes.',                                    '{\"strength\":5,\"agility\":5,\"endurance\":5}',    'legendaire', 'assets/svg/quests/crown.svg',   200)
            ON DUPLICATE KEY UPDATE
              label = VALUES(label),
              description = VALUES(description),
              flavor = VALUES(flavor),
              bonus_json = VALUES(bonus_json),
              rarity = VALUES(rarity),
              icon_path = VALUES(icon_path),
              sort_order = VALUES(sort_order)
        ");

        // --- Achievements mini-jeux ---
        if (table_exists($pdo, 'achievements')) {
            $pdo->exec("INSERT IGNORE INTO achievements (code, title, description, category, reward_xp, icon_path, sort_order) VALUES
                ('snake_score_5',  'Grignoteur',         'Mange 5 pommes en une partie de Snake.',   'minigame', 5,  'assets/svg/ui/scroll.svg',    70),
                ('snake_score_10', 'Affame',             'Mange 10 pommes en une partie de Snake.',  'minigame', 10, 'assets/svg/ui/scroll.svg',    71),
                ('snake_score_20', 'Insatiable',         'Mange 20 pommes en une partie de Snake.',  'minigame', 20, 'assets/svg/ui/scroll.svg',    72),
                ('snake_score_50', 'Serpent legendaire', 'Mange 50 pommes en une partie de Snake.',  'minigame', 50, 'assets/svg/quests/crown.svg', 73)
            ");
        }

        // --- Achievements donjons ---
        if (table_exists($pdo, 'achievements')) {
            $pdo->exec("INSERT IGNORE INTO achievements (code, title, description, category, reward_xp, icon_path, sort_order) VALUES
                ('dungeon_first_room',  'Premiere Ombre',         'Complete ta premiere salle de donjon.',                          'dungeon',  10,  'assets/svg/ui/trophy.svg',     80),
                ('dungeon_victory_1',   'Explorateur',            'Remporte une victoire complete en donjon.',                      'dungeon',  20,  'assets/svg/ui/trophy.svg',     81),
                ('dungeon_victory_10',  'Briseur de Portes',      'Remporte 10 victoires completes en donjon.',                     'dungeon',  40,  'assets/svg/ui/trophy.svg',     82),
                ('dungeon_victory_50',  'Maitre des Abysses',     'Remporte 50 victoires completes en donjon.',                     'dungeon',  80,  'assets/svg/ui/trophy.svg',     83),
                ('dungeon_low_hp',      'Dernier Souffle',        'Termine un donjon avec moins de 10 PV restants.',                'dungeon',  30,  'assets/svg/ui/trophy.svg',     84),
                ('dungeon_flawless',    'Intouchable',            'Termine un donjon sans perdre le moindre PV.',                   'dungeon',  50,  'assets/svg/quests/crown.svg',  85),
                ('crypte_clear',        'Fossoyeur',              'Termine la Crypte des Damnes.',                                  'dungeon',  15,  'assets/svg/ui/trophy.svg',     86),
                ('crypte_clear_5',      'Profanateur',            'Termine 5 fois la Crypte des Damnes.',                           'dungeon',  30,  'assets/svg/ui/trophy.svg',     87),
                ('forteresse_clear',    'Briseur de Forteresse',  'Termine la Forteresse Maudite.',                                 'dungeon',  25,  'assets/svg/ui/trophy.svg',     88),
                ('forteresse_clear_5',  'Siege Perpetuel',        'Termine 5 fois la Forteresse Maudite.',                          'dungeon',  50,  'assets/svg/ui/trophy.svg',     89),
                ('abisse_clear',        'Marcheur de Abisse',     'Termine Abisse Eternel.',                                        'dungeon',  40,  'assets/svg/ui/trophy.svg',     90),
                ('abisse_clear_5',      'Demon Brise',            'Termine 5 fois Abisse Eternel.',                                 'dungeon',  80,  'assets/svg/ui/trophy.svg',     91),
                ('nexus_clear',         'Titan Vaincu',           'Termine le Nexus des Anciens.',                                  'dungeon', 100,  'assets/svg/quests/crown.svg',  92),
                ('nexus_clear_5',       'Fleau des Anciens',      'Termine 5 fois le Nexus des Anciens.',                           'dungeon', 200,  'assets/svg/quests/crown.svg',  93),
                ('nexus_flawless',      'Sanctuaire Profane',     'Termine le Nexus des Anciens avec plus de la moitie de tes PV.', 'dungeon', 150,  'assets/svg/quests/crown.svg',  94)
            ");
        }

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
/**
 * Reconstruit `brutes.win_streak_best` (et `win_streak` courant) en scannant
 * l'historique des combats en arène. Appelé une fois lors de la création
 * de la colonne pour ne pas pénaliser les anciens joueurs.
 */
function backfill_win_streak_best(PDO $pdo): void
{
    $rows = $pdo->query("
        SELECT brute1_id AS bid, winner_id, created_at
        FROM fights WHERE context = 'arena'
        UNION ALL
        SELECT brute2_id AS bid, winner_id, created_at
        FROM fights WHERE context = 'arena'
        ORDER BY created_at ASC
    ")->fetchAll();

    $cur = [];   // streak en cours par brute
    $best = [];  // meilleur streak par brute
    foreach ($rows as $r) {
        $bid = (int)$r['bid'];
        $won = ((int)$r['winner_id'] === $bid);
        if ($won) {
            $cur[$bid] = ($cur[$bid] ?? 0) + 1;
            $best[$bid] = max($best[$bid] ?? 0, $cur[$bid]);
        } else {
            $cur[$bid] = 0;
        }
    }

    if (empty($best)) return;
    $upd = $pdo->prepare('UPDATE brutes SET win_streak = ?, win_streak_best = ? WHERE id = ?');
    foreach ($best as $bid => $bestVal) {
        $upd->execute([(int)($cur[$bid] ?? 0), (int)$bestVal, (int)$bid]);
    }
}

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
