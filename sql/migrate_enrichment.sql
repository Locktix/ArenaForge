-- ============================================================
-- ArenaForge — Migration "Enrichment" (10 fonctionnalités majeures)
-- ============================================================
-- Cette migration est ADDITIVE et REJOUABLE :
--   - aucune colonne supprimée
--   - aucune table existante touchée en destructif
--   - tous les ALTER utilisent un test d'existence
--   - tous les INSERT sont IGNORE / ON DUPLICATE KEY UPDATE
--
-- Usage : importer ce fichier après schema.sql via phpMyAdmin (ou CLI).
-- Si un objet existe déjà, l'opération est silencieusement passée.
-- ============================================================

USE arenaforge;

-- ============================================================
-- Helper : ajout conditionnel de colonne
-- (MariaDB/MySQL ne supporte pas ADD COLUMN IF NOT EXISTS partout,
--  on utilise une procédure jetable.)
-- ============================================================

DROP PROCEDURE IF EXISTS af_add_column_if_missing;
DELIMITER //
CREATE PROCEDURE af_add_column_if_missing(
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
-- F5 : Skills — flag "ultime" + condition de déclenchement
-- ============================================================
CALL af_add_column_if_missing('skills', 'is_ultimate', "TINYINT(1) NOT NULL DEFAULT 0");
CALL af_add_column_if_missing('skills', 'trigger_condition', "VARCHAR(32) NOT NULL DEFAULT ''");

-- Trois ultimes ajoutés (idempotent)
INSERT IGNORE INTO skills (name, description, effect_type, effect_value, icon_path, is_ultimate, trigger_condition) VALUES
  ('Frappe titanesque', 'Une fois par combat sous 50% PV : double les degats du prochain coup.', 'ult_double_dmg',  100, 'assets/svg/skills/rage.svg',     1, 'low_hp_50'),
  ('Cri de guerre',     'Au premier critique subi, soigne 30% des PV max.',                       'ult_heal_pct',     30, 'assets/svg/skills/regen.svg',    1, 'on_first_crit_taken'),
  ('Seconde vie',       'Quand tu tomberais a 0 PV, restaure 25% des PV max (1/combat).',          'ult_revive_pct',   25, 'assets/svg/skills/lifesteal.svg', 1, 'on_lethal');

-- ============================================================
-- F6 : Or + marché noir
-- ============================================================
CALL af_add_column_if_missing('brutes', 'gold', "INT UNSIGNED NOT NULL DEFAULT 0");

CREATE TABLE IF NOT EXISTS black_market_offers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    offer_date DATE NOT NULL,
    slot INT UNSIGNED NOT NULL,
    item_type ENUM('fragments','xp','potion','bonus_fight','gold_bag') NOT NULL,
    item_value INT UNSIGNED NOT NULL,
    cost_gold INT UNSIGNED NOT NULL,
    label VARCHAR(80) NOT NULL,
    icon_path VARCHAR(128) NOT NULL,
    UNIQUE KEY uk_offer_slot (offer_date, slot),
    INDEX idx_date (offer_date)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS brute_market_purchases (
    brute_id INT UNSIGNED NOT NULL,
    offer_id INT UNSIGNED NOT NULL,
    bought_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (brute_id, offer_id),
    CONSTRAINT fk_bmp_brute FOREIGN KEY (brute_id) REFERENCES brutes(id) ON DELETE CASCADE,
    CONSTRAINT fk_bmp_offer FOREIGN KEY (offer_id) REFERENCES black_market_offers(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- F7 : Streak journalier sur les comptes
-- ============================================================
CALL af_add_column_if_missing('users', 'streak_days',       "INT UNSIGNED NOT NULL DEFAULT 0");
CALL af_add_column_if_missing('users', 'last_login_date',   "DATE NULL");
CALL af_add_column_if_missing('users', 'streak_claim_date', "DATE NULL COMMENT 'Dernier jour ou la recompense de streak a ete reclamee'");

-- ============================================================
-- F3 : Défis directs entre joueurs
-- ============================================================
CREATE TABLE IF NOT EXISTS challenges (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    challenger_id INT UNSIGNED NOT NULL,
    target_id     INT UNSIGNED NOT NULL,
    status ENUM('pending','accepted','declined','expired') NOT NULL DEFAULT 'pending',
    fight_id INT UNSIGNED NULL,
    message VARCHAR(140) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at DATETIME NULL,
    CONSTRAINT fk_chal_challenger FOREIGN KEY (challenger_id) REFERENCES brutes(id) ON DELETE CASCADE,
    CONSTRAINT fk_chal_target     FOREIGN KEY (target_id)     REFERENCES brutes(id) ON DELETE CASCADE,
    CONSTRAINT fk_chal_fight      FOREIGN KEY (fight_id)      REFERENCES fights(id) ON DELETE SET NULL,
    INDEX idx_target (target_id, status),
    INDEX idx_challenger (challenger_id, status),
    INDEX idx_created (created_at)
) ENGINE=InnoDB;

-- ============================================================
-- F4 : Récompenses de fin de saison + division précalculée
-- ============================================================
CREATE TABLE IF NOT EXISTS season_rewards (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    season_id INT UNSIGNED NOT NULL,
    brute_id  INT UNSIGNED NOT NULL,
    final_mmr INT NOT NULL,
    peak_mmr  INT NOT NULL,
    tier_code VARCHAR(20) NOT NULL,
    tier_division VARCHAR(8) NOT NULL DEFAULT 'I',
    title_awarded VARCHAR(80) NOT NULL DEFAULT '',
    gold_awarded  INT UNSIGNED NOT NULL DEFAULT 0,
    awarded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_season_brute (season_id, brute_id),
    INDEX idx_brute (brute_id),
    CONSTRAINT fk_sr_season FOREIGN KEY (season_id) REFERENCES seasons(id) ON DELETE CASCADE,
    CONSTRAINT fk_sr_brute  FOREIGN KEY (brute_id)  REFERENCES brutes(id)  ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- F8 : Boss PvE journalier
-- ============================================================
CREATE TABLE IF NOT EXISTS daily_bosses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    boss_date DATE NOT NULL UNIQUE,
    name VARCHAR(40) NOT NULL,
    level INT UNSIGNED NOT NULL,
    hp_max INT UNSIGNED NOT NULL,
    strength INT UNSIGNED NOT NULL,
    agility INT UNSIGNED NOT NULL,
    endurance INT UNSIGNED NOT NULL,
    appearance_seed VARCHAR(64) NOT NULL,
    weapon_id INT UNSIGNED NULL,
    skill_id  INT UNSIGNED NULL,
    icon_path VARCHAR(128) NOT NULL DEFAULT 'assets/svg/skills/rage.svg',
    description VARCHAR(255) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_date (boss_date)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS boss_attempts (
    boss_id INT UNSIGNED NOT NULL,
    brute_id INT UNSIGNED NOT NULL,
    fight_id INT UNSIGNED NOT NULL,
    damage_dealt INT UNSIGNED NOT NULL DEFAULT 0,
    won TINYINT(1) NOT NULL DEFAULT 0,
    rounds INT UNSIGNED NOT NULL DEFAULT 0,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (boss_id, brute_id),
    CONSTRAINT fk_ba_boss  FOREIGN KEY (boss_id)  REFERENCES daily_bosses(id) ON DELETE CASCADE,
    CONSTRAINT fk_ba_brute FOREIGN KEY (brute_id) REFERENCES brutes(id)        ON DELETE CASCADE,
    CONSTRAINT fk_ba_fight FOREIGN KEY (fight_id) REFERENCES fights(id)        ON DELETE CASCADE,
    INDEX idx_damage (boss_id, damage_dealt DESC)
) ENGINE=InnoDB;

-- ============================================================
-- F9 : Combat duo (2v2) — pas de nouvelle table, on étend l'enum context
-- ============================================================
-- Le contexte 'duo' est ajouté implicitement (la colonne est VARCHAR donc
-- aucun ALTER nécessaire). Un check applicatif valide la valeur.

-- ============================================================
-- F8 (suite) : winner_id NULLABLE pour gérer les défaites contre un boss
-- ============================================================
-- Lors d'une défaite contre le boss du jour, aucun maître réel n'a gagné
-- (le boss n'est pas dans `brutes`). On rend winner_id nullable pour que
-- ces lignes restent cohérentes (NULL = pas de vainqueur identifié).
-- Idempotent : on ne touche pas la FK, juste la nullabilité de la colonne.

ALTER TABLE fights MODIFY winner_id INT UNSIGNED NULL;

-- ============================================================
-- Cleanup helper
-- ============================================================
DROP PROCEDURE IF EXISTS af_add_column_if_missing;

-- ============================================================
-- FIN DE MIGRATION — données préservées
-- ============================================================
