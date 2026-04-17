-- ============================================================
-- ArenaForge - Schéma de base de données
-- ============================================================

CREATE DATABASE IF NOT EXISTS arenaforge
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE arenaforge;

-- ============================================================
-- Nettoyage (pour réinstallation propre)
-- ============================================================
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS pupils;
DROP TABLE IF EXISTS fights;
DROP TABLE IF EXISTS brute_skills;
DROP TABLE IF EXISTS brute_weapons;
DROP TABLE IF EXISTS skills;
DROP TABLE IF EXISTS weapons;
DROP TABLE IF EXISTS brutes;
DROP TABLE IF EXISTS users;
SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- Utilisateurs
-- ============================================================
CREATE TABLE users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================================
-- Gladiateurs (brutes)
-- ============================================================
CREATE TABLE brutes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  name VARCHAR(32) NOT NULL UNIQUE,
  level INT UNSIGNED NOT NULL DEFAULT 1,
  xp INT UNSIGNED NOT NULL DEFAULT 0,
  hp_max INT UNSIGNED NOT NULL DEFAULT 50,
  strength INT UNSIGNED NOT NULL DEFAULT 5,
  agility INT UNSIGNED NOT NULL DEFAULT 5,
  endurance INT UNSIGNED NOT NULL DEFAULT 5,
  appearance_seed VARCHAR(64) NOT NULL,
  master_id INT UNSIGNED NULL,
  fights_today INT UNSIGNED NOT NULL DEFAULT 0,
  last_fight_date DATE NULL,
  pending_levelup TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_brutes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_brutes_master FOREIGN KEY (master_id) REFERENCES brutes(id) ON DELETE SET NULL,
  INDEX idx_level (level),
  INDEX idx_user (user_id)
) ENGINE=InnoDB;

-- ============================================================
-- Armes
-- ============================================================
CREATE TABLE weapons (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(40) NOT NULL UNIQUE,
  damage_min INT UNSIGNED NOT NULL,
  damage_max INT UNSIGNED NOT NULL,
  speed INT NOT NULL DEFAULT 0,
  crit_chance INT UNSIGNED NOT NULL DEFAULT 5,
  icon_path VARCHAR(128) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE brute_weapons (
  brute_id INT UNSIGNED NOT NULL,
  weapon_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (brute_id, weapon_id),
  CONSTRAINT fk_bw_brute FOREIGN KEY (brute_id) REFERENCES brutes(id) ON DELETE CASCADE,
  CONSTRAINT fk_bw_weapon FOREIGN KEY (weapon_id) REFERENCES weapons(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- Compétences
-- ============================================================
CREATE TABLE skills (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(40) NOT NULL UNIQUE,
  description VARCHAR(255) NOT NULL,
  effect_type VARCHAR(32) NOT NULL,
  effect_value INT NOT NULL DEFAULT 0,
  icon_path VARCHAR(128) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE brute_skills (
  brute_id INT UNSIGNED NOT NULL,
  skill_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (brute_id, skill_id),
  CONSTRAINT fk_bs_brute FOREIGN KEY (brute_id) REFERENCES brutes(id) ON DELETE CASCADE,
  CONSTRAINT fk_bs_skill FOREIGN KEY (skill_id) REFERENCES skills(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- Combats
-- ============================================================
CREATE TABLE fights (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  brute1_id INT UNSIGNED NOT NULL,
  brute2_id INT UNSIGNED NOT NULL,
  winner_id INT UNSIGNED NOT NULL,
  log_json MEDIUMTEXT NOT NULL,
  xp_gained INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_f_b1 FOREIGN KEY (brute1_id) REFERENCES brutes(id) ON DELETE CASCADE,
  CONSTRAINT fk_f_b2 FOREIGN KEY (brute2_id) REFERENCES brutes(id) ON DELETE CASCADE,
  CONSTRAINT fk_f_winner FOREIGN KEY (winner_id) REFERENCES brutes(id) ON DELETE CASCADE,
  INDEX idx_created (created_at)
) ENGINE=InnoDB;

-- ============================================================
-- Pupilles (parrainage)
-- ============================================================
CREATE TABLE pupils (
  master_id INT UNSIGNED NOT NULL,
  pupil_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (master_id, pupil_id),
  CONSTRAINT fk_p_master FOREIGN KEY (master_id) REFERENCES brutes(id) ON DELETE CASCADE,
  CONSTRAINT fk_p_pupil FOREIGN KEY (pupil_id) REFERENCES brutes(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- Données de base : armes
-- ============================================================
INSERT INTO weapons (name, damage_min, damage_max, speed, crit_chance, icon_path) VALUES
  ('Poings nus',   2,  4, 3, 5,  'assets/svg/weapons/fists.svg'),
  ('Dague',        3,  6, 4, 15, 'assets/svg/weapons/dagger.svg'),
  ('Epee',         5, 10, 2, 10, 'assets/svg/weapons/sword.svg'),
  ('Hache',        7, 14, 1, 8,  'assets/svg/weapons/axe.svg'),
  ('Masse',        6, 12, 1, 6,  'assets/svg/weapons/mace.svg'),
  ('Lance',        4,  9, 3, 12, 'assets/svg/weapons/spear.svg'),
  ('Arc',          4,  8, 2, 18, 'assets/svg/weapons/bow.svg'),
  ('Bouclier',     1,  3, 0, 2,  'assets/svg/weapons/shield.svg');

-- ============================================================
-- Données de base : compétences
-- ============================================================
INSERT INTO skills (name, description, effect_type, effect_value, icon_path) VALUES
  ('Force brute',    '+15% aux degats infliges',                 'dmg_bonus_pct',  15, 'assets/svg/skills/strength.svg'),
  ('Esquive',        '+10% de chance d''esquiver une attaque',   'dodge_pct',      10, 'assets/svg/skills/dodge.svg'),
  ('Contre-attaque', '20% de chance de riposter apres esquive',  'counter_pct',    20, 'assets/svg/skills/counter.svg'),
  ('Regeneration',   'Regagne 2 PV par tour',                    'regen_flat',      2, 'assets/svg/skills/regen.svg'),
  ('Coup critique',  '+10% de chance de coup critique',          'crit_bonus_pct', 10, 'assets/svg/skills/crit.svg'),
  ('Armure',         'Reduit les degats subis de 2',             'armor_flat',      2, 'assets/svg/skills/armor.svg'),
  ('Rage',           '+20% de degats sous 30% PV',               'rage_pct',       20, 'assets/svg/skills/rage.svg'),
  ('Vol de vie',     'Recupere 25% des degats infliges',         'lifesteal_pct',  25, 'assets/svg/skills/lifesteal.svg');
