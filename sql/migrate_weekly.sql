-- ============================================================
-- Migration : tournoi hebdomadaire + quêtes hebdomadaires
-- À importer via phpMyAdmin sur la base arenaforge existante.
-- Rejouable (IF NOT EXISTS / IGNORE / ALTER IGNORE).
-- ============================================================

-- 1. Table tournaments : ajout colonne type + changement clé unique
ALTER TABLE tournaments
  ADD COLUMN IF NOT EXISTS type ENUM('daily','weekly') NOT NULL DEFAULT 'daily' AFTER tour_date;

-- Recréer la clé unique en composite (tour_date + type)
-- On supprime l'ancienne clé unique simple si elle existe encore
SET @idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tournaments'
    AND INDEX_NAME = 'tour_date'
);
SET @sql := IF(@idx > 0, 'ALTER TABLE tournaments DROP INDEX tour_date', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Ajouter la clé composite (idempotent)
SET @idx2 := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tournaments'
    AND INDEX_NAME = 'uk_tour_date_type'
);
SET @sql2 := IF(@idx2 = 0, 'ALTER TABLE tournaments ADD UNIQUE KEY uk_tour_date_type (tour_date, type)', 'SELECT 1');
PREPARE stmt2 FROM @sql2; EXECUTE stmt2; DEALLOCATE PREPARE stmt2;

-- 2. Table quest_definitions : ajout colonne scope
ALTER TABLE quest_definitions
  ADD COLUMN IF NOT EXISTS scope ENUM('daily','weekly') NOT NULL DEFAULT 'daily' AFTER code;

-- 3. Nouvelles quêtes hebdomadaires (INSERT IGNORE si déjà présentes)
INSERT IGNORE INTO quest_definitions (code, scope, label, description, target, reward_xp, reward_bonus_fights, icon_path) VALUES
  ('w_win_20',      'weekly', 'Inarretable',           'Remporter 20 victoires dans la semaine.',          20, 40, 2, 'assets/svg/quests/trophy.svg'),
  ('w_crit_20',     'weekly', 'Frappe du destin',      'Placer 20 coups critiques dans la semaine.',       20, 30, 1, 'assets/svg/quests/crit.svg'),
  ('w_dodge_30',    'weekly', 'Fantome',               'Esquiver 30 attaques dans la semaine.',            30, 30, 1, 'assets/svg/quests/dodge.svg'),
  ('w_damage_1000', 'weekly', 'Demolisseur',           'Infliger 1000 degats cumules dans la semaine.',  1000, 35, 1, 'assets/svg/quests/hammer.svg'),
  ('w_flawless_3',  'weekly', 'Intouchable',           'Remporter 3 victoires sans subir de degats.',       3, 45, 2, 'assets/svg/quests/shield.svg'),
  ('w_upset_3',     'weekly', 'Pourfendeur de titans', 'Battre 3 adversaires de niveau superieur.',         3, 40, 2, 'assets/svg/quests/crown.svg');

-- 4. Nouvelle table de suivi des quêtes hebdomadaires
CREATE TABLE IF NOT EXISTS brute_weekly_quests (
  brute_id INT UNSIGNED NOT NULL,
  quest_code VARCHAR(40) NOT NULL,
  quest_week DATE NOT NULL COMMENT 'Lundi de la semaine ISO',
  progress INT UNSIGNED NOT NULL DEFAULT 0,
  claimed TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (brute_id, quest_code, quest_week),
  CONSTRAINT fk_bwq_brute FOREIGN KEY (brute_id) REFERENCES brutes(id) ON DELETE CASCADE,
  CONSTRAINT fk_bwq_quest FOREIGN KEY (quest_code) REFERENCES quest_definitions(code) ON DELETE CASCADE,
  INDEX idx_week (quest_week)
) ENGINE=InnoDB;
