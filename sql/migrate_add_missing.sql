-- ============================================================
-- ArenaForge — Migration : ajout des tables/colonnes manquantes
-- Sûr à rejouer (IF NOT EXISTS / IF column doesn't exist)
-- Ne supprime aucune donnée existante.
-- ============================================================
USE arenaforge;

-- ============================================================
-- users : streak de connexion
-- ============================================================
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS streak_days       INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS last_login_date   DATE NULL,
  ADD COLUMN IF NOT EXISTS streak_claim_date DATE NULL;

-- ============================================================
-- brutes : or (économie)
-- ============================================================
ALTER TABLE brutes
  ADD COLUMN IF NOT EXISTS gold INT UNSIGNED NOT NULL DEFAULT 0;

-- ============================================================
-- fights : winner_id devient nullable (boss fight : boss gagne → NULL)
-- ============================================================
ALTER TABLE fights
  MODIFY COLUMN winner_id INT UNSIGNED NULL,
  DROP FOREIGN KEY IF EXISTS fk_f_winner;

ALTER TABLE fights
  ADD CONSTRAINT fk_f_winner
    FOREIGN KEY (winner_id) REFERENCES brutes(id) ON DELETE SET NULL;

-- ============================================================
-- Récompenses de saison ELO
-- ============================================================
CREATE TABLE IF NOT EXISTS season_rewards (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  season_id INT UNSIGNED NOT NULL,
  brute_id INT UNSIGNED NOT NULL,
  final_mmr INT NOT NULL DEFAULT 1000,
  peak_mmr INT NOT NULL DEFAULT 1000,
  tier_code VARCHAR(20) NOT NULL,
  tier_division VARCHAR(4) NOT NULL DEFAULT 'I',
  title_awarded VARCHAR(80) NULL,
  gold_awarded INT UNSIGNED NOT NULL DEFAULT 0,
  awarded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_season_brute (season_id, brute_id),
  CONSTRAINT fk_sr_season FOREIGN KEY (season_id) REFERENCES seasons(id) ON DELETE CASCADE,
  CONSTRAINT fk_sr_brute  FOREIGN KEY (brute_id)  REFERENCES brutes(id)  ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- Boss quotidien PvE
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
  appearance_seed TEXT NOT NULL,
  weapon_id INT UNSIGNED NULL,
  skill_id INT UNSIGNED NULL,
  icon_path VARCHAR(128) NOT NULL DEFAULT 'assets/svg/skills/rage.svg',
  description VARCHAR(255) NOT NULL DEFAULT '',
  CONSTRAINT fk_db_weapon FOREIGN KEY (weapon_id) REFERENCES weapons(id) ON DELETE SET NULL,
  CONSTRAINT fk_db_skill  FOREIGN KEY (skill_id)  REFERENCES skills(id)  ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS boss_attempts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  boss_id INT UNSIGNED NOT NULL,
  brute_id INT UNSIGNED NOT NULL,
  fight_id INT UNSIGNED NULL,
  damage_dealt INT UNSIGNED NOT NULL DEFAULT 0,
  won TINYINT(1) NOT NULL DEFAULT 0,
  rounds INT UNSIGNED NOT NULL DEFAULT 0,
  attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_boss_brute (boss_id, brute_id),
  CONSTRAINT fk_ba_boss   FOREIGN KEY (boss_id)  REFERENCES daily_bosses(id) ON DELETE CASCADE,
  CONSTRAINT fk_ba_brute2 FOREIGN KEY (brute_id) REFERENCES brutes(id) ON DELETE CASCADE,
  CONSTRAINT fk_ba_fight  FOREIGN KEY (fight_id) REFERENCES fights(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
-- Défis PvP directs
-- ============================================================
CREATE TABLE IF NOT EXISTS challenges (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  challenger_id INT UNSIGNED NOT NULL,
  target_id INT UNSIGNED NOT NULL,
  message VARCHAR(140) NOT NULL DEFAULT '',
  status ENUM('pending','accepted','declined','expired') NOT NULL DEFAULT 'pending',
  fight_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at DATETIME NULL,
  CONSTRAINT fk_ch_challenger FOREIGN KEY (challenger_id) REFERENCES brutes(id) ON DELETE CASCADE,
  CONSTRAINT fk_ch_target     FOREIGN KEY (target_id)     REFERENCES brutes(id) ON DELETE CASCADE,
  CONSTRAINT fk_ch_fight      FOREIGN KEY (fight_id)      REFERENCES fights(id) ON DELETE SET NULL,
  INDEX idx_ch_challenger (challenger_id),
  INDEX idx_ch_target (target_id),
  INDEX idx_ch_status (status)
) ENGINE=InnoDB;

-- ============================================================
-- Marché noir journalier
-- ============================================================
CREATE TABLE IF NOT EXISTS black_market_offers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  offer_date DATE NOT NULL,
  slot INT UNSIGNED NOT NULL,
  item_type VARCHAR(20) NOT NULL,
  item_value INT UNSIGNED NOT NULL,
  cost_gold INT UNSIGNED NOT NULL,
  label VARCHAR(80) NOT NULL,
  icon_path VARCHAR(128) NOT NULL,
  UNIQUE KEY uk_offer_slot (offer_date, slot)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS brute_market_purchases (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  brute_id INT UNSIGNED NOT NULL,
  offer_id INT UNSIGNED NOT NULL,
  purchased_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_brute_offer (brute_id, offer_id),
  CONSTRAINT fk_bmp_brute FOREIGN KEY (brute_id) REFERENCES brutes(id) ON DELETE CASCADE,
  CONSTRAINT fk_bmp_offer FOREIGN KEY (offer_id) REFERENCES black_market_offers(id) ON DELETE CASCADE
) ENGINE=InnoDB;
