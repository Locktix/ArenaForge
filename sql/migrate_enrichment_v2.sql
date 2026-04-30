-- ============================================================
-- ArenaForge — Migration "Enrichment v2"
-- 8 nouvelles fonctionnalités (notifications, revanche, évolution pets,
-- chat de clan, tournoi hebdo, météo, codex, bots auto-fight)
-- ============================================================
-- Additive et REJOUABLE. Ne rien supprimer.

-- USE arenaforge; -- décommenter en local XAMPP, laisser commenté sur o2switch

-- ============================================================
-- Helper conditionnel (ré-utilisable)
-- ============================================================
DROP PROCEDURE IF EXISTS af_add_column_if_missing_v2;
DELIMITER //
CREATE PROCEDURE af_add_column_if_missing_v2(
    IN tbl VARCHAR(64),
    IN col VARCHAR(64),
    IN coldef TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = tbl
          AND COLUMN_NAME = col
    ) THEN
        SET @sql = CONCAT('ALTER TABLE `', tbl, '` ADD COLUMN `', col, '` ', coldef);
        PREPARE st FROM @sql;
        EXECUTE st;
        DEALLOCATE PREPARE st;
    END IF;
END //
DELIMITER ;

-- ============================================================
-- F3 : Évolution des pets — colonne combat_count + pets évolués
-- ============================================================
CALL af_add_column_if_missing_v2('brute_pets', 'combat_count', "INT UNSIGNED NOT NULL DEFAULT 0");
CALL af_add_column_if_missing_v2('brute_pets', 'evolved_from_pet_id', "INT UNSIGNED NULL");

-- 4 pets évolués (idempotent)
INSERT IGNORE INTO pets (name, species, hp_max, damage_min, damage_max, agility, description, icon_path) VALUES
  ('Molosse',     'dog',     32,  4,  6,  7, 'Chien aguerri par 50 combats : plus rapide, plus mordant.',          'assets/svg/pets/dog.svg'),
  ('Loup alpha',  'wolf',    48,  6, 10,  8, 'Loup chevronné, meneur de meute.',                                   'assets/svg/pets/wolf.svg'),
  ('Sphinx',      'panther', 40,  7, 12, 11, 'Panthère mythique, frappe surprise quasi infaillible.',              'assets/svg/pets/panther.svg'),
  ('Ours-roi',    'bear',    80, 10, 16,  4, 'Ours légendaire, force colossale et résistance hors normes.',        'assets/svg/pets/bear.svg');

-- ============================================================
-- F4 : Chat de clan
-- ============================================================
CREATE TABLE IF NOT EXISTS clan_messages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    clan_id INT UNSIGNED NOT NULL,
    brute_id INT UNSIGNED NOT NULL,
    body VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_cmsg_clan  FOREIGN KEY (clan_id)  REFERENCES clans(id)  ON DELETE CASCADE,
    CONSTRAINT fk_cmsg_brute FOREIGN KEY (brute_id) REFERENCES brutes(id) ON DELETE CASCADE,
    INDEX idx_clan_time (clan_id, created_at)
) ENGINE=InnoDB;

-- ============================================================
-- F5 : Tournoi hebdomadaire (réutilise la table tournaments)
-- ============================================================
-- La colonne `type` existe déjà (ENUM 'daily','weekly'). Aucune ALTER.
-- La taille est variable (8 ou 16) via `size` déjà présent.
-- On ajoute juste la trace des champions hebdo dans season_rewards-like ?
-- Non : on réutilise tournament_entries.placement.

-- ============================================================
-- F6 : Météo d'arène — pas de table. Le type est piochée à chaque combat
-- et stockée dans le log JSON. Aucune ALTER nécessaire.
-- ============================================================

-- ============================================================
-- F7 : Codex / bestiaire — usage tracking
-- ============================================================
CREATE TABLE IF NOT EXISTS brute_codex_usage (
    brute_id INT UNSIGNED NOT NULL,
    item_type ENUM('weapon','skill','pet') NOT NULL,
    item_id INT UNSIGNED NOT NULL,
    use_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_used DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (brute_id, item_type, item_id),
    CONSTRAINT fk_codex_brute FOREIGN KEY (brute_id) REFERENCES brutes(id) ON DELETE CASCADE,
    INDEX idx_brute_type (brute_id, item_type)
) ENGINE=InnoDB;

-- ============================================================
-- F8 : État système (compteur bot_auto_run, etc.)
-- ============================================================
CREATE TABLE IF NOT EXISTS system_state (
    state_key VARCHAR(40) NOT NULL PRIMARY KEY,
    state_value VARCHAR(255) NOT NULL DEFAULT '',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================================
-- Cleanup helper
-- ============================================================
DROP PROCEDURE IF EXISTS af_add_column_if_missing_v2;

-- ============================================================
-- FIN — données préservées
-- ============================================================
