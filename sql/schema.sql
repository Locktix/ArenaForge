-- ============================================================
-- ArenaForge - Schéma de base de données (XAMPP local)
-- ============================================================
-- Import direct via phpMyAdmin : la base 'arenaforge' est créée
-- et sélectionnée automatiquement par ce script.
-- ============================================================

CREATE DATABASE IF NOT EXISTS arenaforge
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
USE arenaforge;

-- ============================================================
-- Nettoyage (pour réinstallation propre)
-- ============================================================
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS brute_quests;
DROP TABLE IF EXISTS quest_definitions;
DROP TABLE IF EXISTS tournament_fights;
DROP TABLE IF EXISTS tournament_entries;
DROP TABLE IF EXISTS tournaments;
DROP TABLE IF EXISTS brute_pets;
DROP TABLE IF EXISTS pets;
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
  bonus_fights_available INT UNSIGNED NOT NULL DEFAULT 0,
  pupil_bonus_progress INT UNSIGNED NOT NULL DEFAULT 0,
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
  context VARCHAR(20) NOT NULL DEFAULT 'arena',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_f_b1 FOREIGN KEY (brute1_id) REFERENCES brutes(id) ON DELETE CASCADE,
  CONSTRAINT fk_f_b2 FOREIGN KEY (brute2_id) REFERENCES brutes(id) ON DELETE CASCADE,
  CONSTRAINT fk_f_winner FOREIGN KEY (winner_id) REFERENCES brutes(id) ON DELETE CASCADE,
  INDEX idx_created (created_at),
  INDEX idx_context (context)
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
-- Animaux de compagnie
-- ============================================================
CREATE TABLE pets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(40) NOT NULL UNIQUE,
  species VARCHAR(20) NOT NULL,
  hp_max INT UNSIGNED NOT NULL,
  damage_min INT UNSIGNED NOT NULL,
  damage_max INT UNSIGNED NOT NULL,
  agility INT UNSIGNED NOT NULL,
  description VARCHAR(255) NOT NULL,
  icon_path VARCHAR(128) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE brute_pets (
  brute_id INT UNSIGNED NOT NULL,
  pet_id INT UNSIGNED NOT NULL,
  acquired_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (brute_id, pet_id),
  CONSTRAINT fk_bp_brute FOREIGN KEY (brute_id) REFERENCES brutes(id) ON DELETE CASCADE,
  CONSTRAINT fk_bp_pet FOREIGN KEY (pet_id) REFERENCES pets(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- Tournois
-- ============================================================
CREATE TABLE tournaments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tour_date DATE NOT NULL UNIQUE,
  status ENUM('open','running','finished') NOT NULL DEFAULT 'open',
  size INT UNSIGNED NOT NULL DEFAULT 8,
  winner_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at DATETIME NULL,
  CONSTRAINT fk_t_winner FOREIGN KEY (winner_id) REFERENCES brutes(id) ON DELETE SET NULL,
  INDEX idx_status (status)
) ENGINE=InnoDB;

CREATE TABLE tournament_entries (
  tournament_id INT UNSIGNED NOT NULL,
  brute_id INT UNSIGNED NOT NULL,
  slot INT UNSIGNED NOT NULL,
  placement INT UNSIGNED NULL,
  xp_earned INT UNSIGNED NOT NULL DEFAULT 0,
  is_ai TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (tournament_id, brute_id),
  UNIQUE KEY uk_slot (tournament_id, slot),
  CONSTRAINT fk_te_tour FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE CASCADE,
  CONSTRAINT fk_te_brute FOREIGN KEY (brute_id) REFERENCES brutes(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE tournament_fights (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tournament_id INT UNSIGNED NOT NULL,
  round INT UNSIGNED NOT NULL,
  match_idx INT UNSIGNED NOT NULL,
  fight_id INT UNSIGNED NOT NULL,
  b1_id INT UNSIGNED NOT NULL,
  b2_id INT UNSIGNED NOT NULL,
  winner_id INT UNSIGNED NOT NULL,
  UNIQUE KEY uk_match (tournament_id, round, match_idx),
  CONSTRAINT fk_tf_tour FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE CASCADE,
  CONSTRAINT fk_tf_fight FOREIGN KEY (fight_id) REFERENCES fights(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- Quêtes journalières
-- ============================================================
CREATE TABLE quest_definitions (
  code VARCHAR(40) NOT NULL PRIMARY KEY,
  label VARCHAR(120) NOT NULL,
  description VARCHAR(255) NOT NULL,
  target INT UNSIGNED NOT NULL,
  reward_xp INT UNSIGNED NOT NULL,
  reward_bonus_fights INT UNSIGNED NOT NULL DEFAULT 0,
  icon_path VARCHAR(128) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE brute_quests (
  brute_id INT UNSIGNED NOT NULL,
  quest_code VARCHAR(40) NOT NULL,
  quest_date DATE NOT NULL,
  progress INT UNSIGNED NOT NULL DEFAULT 0,
  claimed TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (brute_id, quest_code, quest_date),
  CONSTRAINT fk_bq_brute FOREIGN KEY (brute_id) REFERENCES brutes(id) ON DELETE CASCADE,
  CONSTRAINT fk_bq_quest FOREIGN KEY (quest_code) REFERENCES quest_definitions(code) ON DELETE CASCADE,
  INDEX idx_date (quest_date)
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

-- ============================================================
-- Données de base : animaux
-- ============================================================
INSERT INTO pets (name, species, hp_max, damage_min, damage_max, agility, description, icon_path) VALUES
  ('Chien',    'dog',     20,  2,  4, 6, 'Compagnon fidele, rapide mais fragile.',          'assets/svg/pets/dog.svg'),
  ('Loup',     'wolf',    32,  4,  7, 7, 'Predateur agile, parfait equilibre.',             'assets/svg/pets/wolf.svg'),
  ('Panthere', 'panther', 26,  5,  9, 9, 'Tres rapide, attaques surprises frequentes.',     'assets/svg/pets/panther.svg'),
  ('Ours',     'bear',    55,  7, 12, 3, 'Lent mais terriblement resistant et puissant.',   'assets/svg/pets/bear.svg');

-- ============================================================
-- Données de base : quêtes
-- ============================================================
INSERT INTO quest_definitions (code, label, description, target, reward_xp, reward_bonus_fights, icon_path) VALUES
  ('win_3',         'Triple victoire',    'Gagner 3 combats dans la journee.',                  3,  6, 0, 'assets/svg/quests/sword.svg'),
  ('win_5',         'Conquerant',         'Gagner 5 combats dans la journee.',                  5, 10, 1, 'assets/svg/quests/trophy.svg'),
  ('crit_3',        'Coups devastateurs', 'Placer 3 coups critiques dans la journee.',          3,  5, 0, 'assets/svg/quests/crit.svg'),
  ('dodge_5',       'Insaisissable',      'Esquiver 5 attaques dans la journee.',               5,  5, 0, 'assets/svg/quests/dodge.svg'),
  ('damage_100',    'Broyeur',            'Infliger 100 degats cumules dans la journee.',     100,  6, 0, 'assets/svg/quests/hammer.svg'),
  ('flawless_1',    'Invulnerable',       'Gagner 1 combat sans subir le moindre degat.',       1,  8, 1, 'assets/svg/quests/shield.svg'),
  ('upset_1',       'Tombeur de geants',  'Battre un adversaire de niveau superieur.',          1,  7, 1, 'assets/svg/quests/crown.svg'),
  ('daily_6',       'Marathonien',        'Consommer les 6 combats de la journee.',             6,  4, 0, 'assets/svg/quests/fire.svg');
