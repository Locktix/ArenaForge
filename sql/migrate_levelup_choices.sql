-- ============================================================
-- ArenaForge — Migration : Ajout de la persistance des choix de level-up
-- ============================================================

USE arenaforge;

-- Ajout de la colonne levelup_choices pour éviter de perdre les choix au rafraîchissement
-- et corriger l'erreur 500 sur le profil lors d'un level-up en attente.

DROP PROCEDURE IF EXISTS af_add_column_levelup;
DELIMITER //
CREATE PROCEDURE af_add_column_levelup()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'brutes'
          AND COLUMN_NAME = 'levelup_choices'
    ) THEN
        ALTER TABLE `brutes` ADD COLUMN `levelup_choices` TEXT NULL AFTER `pending_levelup`;
    END IF;
END //
DELIMITER ;

CALL af_add_column_levelup();
DROP PROCEDURE IF EXISTS af_add_column_levelup;
