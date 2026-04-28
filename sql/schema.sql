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
DROP TABLE IF EXISTS season_rewards;
DROP TABLE IF EXISTS brute_market_purchases;
DROP TABLE IF EXISTS black_market_offers;
DROP TABLE IF EXISTS challenges;
DROP TABLE IF EXISTS boss_attempts;
DROP TABLE IF EXISTS daily_bosses;
DROP TABLE IF EXISTS clan_members;
DROP TABLE IF EXISTS clans;
DROP TABLE IF EXISTS brute_armors;
DROP TABLE IF EXISTS armors;
DROP TABLE IF EXISTS brute_weapon_upgrades;
DROP TABLE IF EXISTS brute_achievements;
DROP TABLE IF EXISTS achievements;
DROP TABLE IF EXISTS brute_weekly_quests;
DROP TABLE IF EXISTS brute_quests;
DROP TABLE IF EXISTS quest_definitions;
DROP TABLE IF EXISTS tournament_fights;
DROP TABLE IF EXISTS tournament_entries;
DROP TABLE IF EXISTS tournaments;
DROP TABLE IF EXISTS seasons;
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
  tutorial_step INT UNSIGNED NOT NULL DEFAULT 0,
  tutorial_skipped TINYINT(1) NOT NULL DEFAULT 0,
  -- Streak de connexion journalière
  streak_days INT UNSIGNED NOT NULL DEFAULT 0,
  last_login_date DATE NULL,
  streak_claim_date DATE NULL,
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
  clan_id INT UNSIGNED NULL,
  fights_today INT UNSIGNED NOT NULL DEFAULT 0,
  last_fight_date DATE NULL,
  pending_levelup TINYINT(1) NOT NULL DEFAULT 0,
  bonus_fights_available INT UNSIGNED NOT NULL DEFAULT 0,
  pupil_bonus_progress INT UNSIGNED NOT NULL DEFAULT 0,
  -- Stats cumulées (pour achievements sans scan intégral)
  total_crits INT UNSIGNED NOT NULL DEFAULT 0,
  total_dodges INT UNSIGNED NOT NULL DEFAULT 0,
  total_flawless INT UNSIGNED NOT NULL DEFAULT 0,
  total_upsets INT UNSIGNED NOT NULL DEFAULT 0,
  -- ELO / saisons
  mmr INT NOT NULL DEFAULT 1000,
  peak_mmr INT NOT NULL DEFAULT 1000,
  -- Forge & économie
  fragments INT UNSIGNED NOT NULL DEFAULT 0,
  gold INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_brutes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_brutes_master FOREIGN KEY (master_id) REFERENCES brutes(id) ON DELETE SET NULL,
  INDEX idx_level (level),
  INDEX idx_user (user_id),
  INDEX idx_mmr (mmr),
  INDEX idx_clan (clan_id)
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
  winner_id INT UNSIGNED NULL,
  log_json MEDIUMTEXT NOT NULL,
  xp_gained INT UNSIGNED NOT NULL DEFAULT 0,
  context VARCHAR(20) NOT NULL DEFAULT 'arena',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_f_b1 FOREIGN KEY (brute1_id) REFERENCES brutes(id) ON DELETE CASCADE,
  CONSTRAINT fk_f_b2 FOREIGN KEY (brute2_id) REFERENCES brutes(id) ON DELETE CASCADE,
  CONSTRAINT fk_f_winner FOREIGN KEY (winner_id) REFERENCES brutes(id) ON DELETE SET NULL,
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
  tour_date DATE NOT NULL,
  type ENUM('daily','weekly') NOT NULL DEFAULT 'daily',
  status ENUM('open','running','finished') NOT NULL DEFAULT 'open',
  size INT UNSIGNED NOT NULL DEFAULT 8,
  winner_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at DATETIME NULL,
  UNIQUE KEY uk_tour_date_type (tour_date, type),
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
  scope ENUM('daily','weekly') NOT NULL DEFAULT 'daily',
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

-- Quêtes hebdomadaires (progression sur la semaine entière)
CREATE TABLE brute_weekly_quests (
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

-- ============================================================
-- Achievements (trophées permanents)
-- ============================================================
CREATE TABLE achievements (
  code VARCHAR(40) NOT NULL PRIMARY KEY,
  title VARCHAR(120) NOT NULL,
  description VARCHAR(255) NOT NULL,
  category VARCHAR(20) NOT NULL DEFAULT 'combat',
  reward_xp INT UNSIGNED NOT NULL DEFAULT 0,
  icon_path VARCHAR(128) NOT NULL,
  sort_order INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE brute_achievements (
  brute_id INT UNSIGNED NOT NULL,
  achievement_code VARCHAR(40) NOT NULL,
  unlocked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (brute_id, achievement_code),
  CONSTRAINT fk_ba_brute FOREIGN KEY (brute_id) REFERENCES brutes(id) ON DELETE CASCADE,
  CONSTRAINT fk_ba_def FOREIGN KEY (achievement_code) REFERENCES achievements(code) ON DELETE CASCADE,
  INDEX idx_brute (brute_id)
) ENGINE=InnoDB;

-- ============================================================
-- Saisons ELO (remise à zéro périodique)
-- ============================================================
CREATE TABLE seasons (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  label VARCHAR(80) NOT NULL,
  started_at DATE NOT NULL,
  ended_at DATE NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uk_active_season (active, started_at)
) ENGINE=InnoDB;

-- ============================================================
-- Forge : upgrades d'armes + armures
-- ============================================================
CREATE TABLE brute_weapon_upgrades (
  brute_id INT UNSIGNED NOT NULL,
  weapon_id INT UNSIGNED NOT NULL,
  upgrade_level INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (brute_id, weapon_id),
  CONSTRAINT fk_bwu_brute FOREIGN KEY (brute_id) REFERENCES brutes(id) ON DELETE CASCADE,
  CONSTRAINT fk_bwu_weapon FOREIGN KEY (weapon_id) REFERENCES weapons(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE armors (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(40) NOT NULL UNIQUE,
  slot ENUM('head','body') NOT NULL DEFAULT 'body',
  hp_bonus INT UNSIGNED NOT NULL DEFAULT 0,
  damage_reduction INT UNSIGNED NOT NULL DEFAULT 0,
  cost_fragments INT UNSIGNED NOT NULL DEFAULT 0,
  tier INT UNSIGNED NOT NULL DEFAULT 1,
  icon_path VARCHAR(128) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE brute_armors (
  brute_id INT UNSIGNED NOT NULL,
  armor_id INT UNSIGNED NOT NULL,
  equipped TINYINT(1) NOT NULL DEFAULT 0,
  acquired_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (brute_id, armor_id),
  CONSTRAINT fk_ba_brute_armor FOREIGN KEY (brute_id) REFERENCES brutes(id) ON DELETE CASCADE,
  CONSTRAINT fk_ba_armor FOREIGN KEY (armor_id) REFERENCES armors(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- Clans (social / guildes)
-- ============================================================
CREATE TABLE clans (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(40) NOT NULL UNIQUE,
  tag VARCHAR(8) NOT NULL UNIQUE,
  description VARCHAR(255) NOT NULL DEFAULT '',
  leader_brute_id INT UNSIGNED NULL,
  max_members INT UNSIGNED NOT NULL DEFAULT 10,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_clan_leader FOREIGN KEY (leader_brute_id) REFERENCES brutes(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE clan_members (
  clan_id INT UNSIGNED NOT NULL,
  brute_id INT UNSIGNED NOT NULL,
  role ENUM('leader','officer','member') NOT NULL DEFAULT 'member',
  joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (clan_id, brute_id),
  UNIQUE KEY uk_brute (brute_id),
  CONSTRAINT fk_cm_clan FOREIGN KEY (clan_id) REFERENCES clans(id) ON DELETE CASCADE,
  CONSTRAINT fk_cm_brute FOREIGN KEY (brute_id) REFERENCES brutes(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Lien FK brutes.clan_id (après création des clans)
ALTER TABLE brutes
  ADD CONSTRAINT fk_brutes_clan FOREIGN KEY (clan_id) REFERENCES clans(id) ON DELETE SET NULL;

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
INSERT INTO quest_definitions (code, scope, label, description, target, reward_xp, reward_bonus_fights, icon_path) VALUES
  -- Quêtes journalières
  ('win_3',         'daily',  'Triple victoire',      'Gagner 3 combats dans la journee.',                  3,  6, 0, 'assets/svg/quests/sword.svg'),
  ('win_5',         'daily',  'Conquerant',           'Gagner 5 combats dans la journee.',                  5, 10, 1, 'assets/svg/quests/trophy.svg'),
  ('crit_3',        'daily',  'Coups devastateurs',   'Placer 3 coups critiques dans la journee.',          3,  5, 0, 'assets/svg/quests/crit.svg'),
  ('dodge_5',       'daily',  'Insaisissable',        'Esquiver 5 attaques dans la journee.',               5,  5, 0, 'assets/svg/quests/dodge.svg'),
  ('damage_100',    'daily',  'Broyeur',              'Infliger 100 degats cumules dans la journee.',     100,  6, 0, 'assets/svg/quests/hammer.svg'),
  ('flawless_1',    'daily',  'Invulnerable',         'Gagner 1 combat sans subir le moindre degat.',       1,  8, 1, 'assets/svg/quests/shield.svg'),
  ('upset_1',       'daily',  'Tombeur de geants',    'Battre un adversaire de niveau superieur.',          1,  7, 1, 'assets/svg/quests/crown.svg'),
  ('daily_6',       'daily',  'Marathonien',          'Consommer les 6 combats de la journee.',             6,  4, 0, 'assets/svg/quests/fire.svg'),
  -- Quêtes hebdomadaires
  ('w_win_20',      'weekly', 'Inarretable',          'Remporter 20 victoires dans la semaine.',           20, 40, 2, 'assets/svg/quests/trophy.svg'),
  ('w_crit_20',     'weekly', 'Frappe du destin',     'Placer 20 coups critiques dans la semaine.',        20, 30, 1, 'assets/svg/quests/crit.svg'),
  ('w_dodge_30',    'weekly', 'Fantome',              'Esquiver 30 attaques dans la semaine.',             30, 30, 1, 'assets/svg/quests/dodge.svg'),
  ('w_damage_1000', 'weekly', 'Demolisseur',          'Infliger 1000 degats cumules dans la semaine.',   1000, 35, 1, 'assets/svg/quests/hammer.svg'),
  ('w_flawless_3',  'weekly', 'Intouchable',          'Remporter 3 victoires sans subir de degats.',        3, 45, 2, 'assets/svg/quests/shield.svg'),
  ('w_upset_3',     'weekly', 'Pourfendeur de titans','Battre 3 adversaires de niveau superieur.',          3, 40, 2, 'assets/svg/quests/crown.svg');

-- ============================================================
-- Données de base : achievements (trophées permanents)
-- ============================================================
INSERT INTO achievements (code, title, description, category, reward_xp, icon_path, sort_order) VALUES
  -- Combat : victoires cumulées
  ('first_win',            'Premier sang',            'Remporte ta toute premiere victoire.',                    'combat',      5,   'assets/svg/quests/sword.svg',   1),
  ('wins_10',              'Habitue de l''arene',     'Cumule 10 victoires.',                                     'combat',      10,  'assets/svg/quests/sword.svg',   2),
  ('wins_50',              'Veteran',                 'Cumule 50 victoires.',                                     'combat',      25,  'assets/svg/quests/sword.svg',   3),
  ('wins_100',             'Legende vivante',         'Cumule 100 victoires.',                                    'combat',      50,  'assets/svg/quests/trophy.svg',  4),
  ('wins_500',             'Immortel',                'Cumule 500 victoires.',                                    'combat',      100, 'assets/svg/quests/trophy.svg',  5),
  -- Combat : exploits
  ('crits_50',             'Frappe chirurgicale',     'Place 50 coups critiques cumules.',                        'combat',      15,  'assets/svg/quests/crit.svg',    10),
  ('crits_200',            'Main du destin',          'Place 200 coups critiques cumules.',                       'combat',      40,  'assets/svg/quests/crit.svg',    11),
  ('dodges_100',           'Insaisissable',           'Esquive 100 attaques cumulees.',                           'combat',      20,  'assets/svg/quests/dodge.svg',   12),
  ('flawless_10',          'Intouchable',             'Remporte 10 victoires sans subir le moindre degat.',       'combat',      30,  'assets/svg/quests/shield.svg',  13),
  ('upset_10',             'Chasseur de titans',      'Bats 10 adversaires de niveau superieur.',                 'combat',      30,  'assets/svg/quests/crown.svg',   14),
  ('combo_crit_3',         'Tempete de critiques',    'Place 3 coups critiques en un seul combat.',               'combat',      15,  'assets/svg/quests/crit.svg',    15),
  ('survive_overwhelming', 'Retour des morts',        'Remporte un combat avec moins de 5 PV restants.',          'combat',      15,  'assets/svg/quests/shield.svg',  16),
  -- Progression (niveau)
  ('level_5',              'Aguerri',                 'Atteins le niveau 5.',                                     'progression', 10,  'assets/svg/ui/nav_ranking.svg', 20),
  ('level_10',             'Expert',                  'Atteins le niveau 10.',                                    'progression', 20,  'assets/svg/ui/nav_ranking.svg', 21),
  ('level_25',             'Maitre gladiateur',       'Atteins le niveau 25.',                                    'progression', 50,  'assets/svg/ui/nav_ranking.svg', 22),
  ('level_50',             'Champion',                'Atteins le niveau 50.',                                    'progression', 100, 'assets/svg/ui/trophy.svg',      23),
  -- Collection
  ('weapons_3',            'Armurier debutant',       'Possede au moins 3 armes differentes.',                    'collection',  10,  'assets/svg/weapons/sword.svg',  30),
  ('weapons_6',            'Arsenal complet',         'Possede au moins 6 armes differentes.',                    'collection',  25,  'assets/svg/weapons/axe.svg',    31),
  ('skills_3',             'Polyvalent',              'Maitrise au moins 3 competences.',                         'collection',  10,  'assets/svg/skills/strength.svg',32),
  ('skills_6',             'Maitre des arts',         'Maitrise au moins 6 competences.',                         'collection',  25,  'assets/svg/skills/rage.svg',    33),
  ('first_pet',            'Compagnon fidele',        'Acquiert ton premier animal de compagnie.',                'collection',  15,  'assets/svg/pets/wolf.svg',      34),
  -- Social (pupilles et clan)
  ('pupils_1',             'Formateur',               'Parraine ton premier pupille.',                            'social',      15,  'assets/svg/ui/nav_pupils.svg',  40),
  ('pupils_5',             'Ecole de l''arene',       'Parraine 5 pupilles.',                                     'social',      30,  'assets/svg/ui/nav_pupils.svg',  41),
  ('clan_founder',         'Fondateur',               'Cree ton propre clan.',                                    'social',      20,  'assets/svg/ui/trophy.svg',      42),
  -- Tournoi
  ('tournament_1',         'Premier pas',             'Participe a ton premier tournoi.',                         'tournament',  10,  'assets/svg/quests/trophy.svg',  50),
  ('tournament_win',       'Couronne d''or',          'Remporte un tournoi quotidien.',                           'tournament',  50,  'assets/svg/quests/trophy.svg',  51),
  ('tournament_wins_5',    'Dynastie',                'Remporte 5 tournois quotidiens.',                          'tournament',  100, 'assets/svg/quests/trophy.svg',  52),
  -- Forge
  ('forge_first',          'Premier forgeage',        'Ameliore ta premiere arme.',                               'forge',       15,  'assets/svg/weapons/axe.svg',    60),
  ('forge_master',         'Maitre forgeron',         'Porte une arme au niveau d''amelioration 5.',              'forge',       40,  'assets/svg/weapons/axe.svg',    61),
  ('armor_first',          'Premiere armure',         'Equipe-toi d''une premiere armure.',                       'forge',       15,  'assets/svg/skills/armor.svg',   62);

-- ============================================================
-- Données de base : armures (Forge)
-- ============================================================
INSERT INTO armors (name, slot, hp_bonus, damage_reduction, cost_fragments, tier, icon_path) VALUES
  -- Tier 1
  ('Tunique de cuir',    'body', 8,  0, 30,  1, 'assets/svg/skills/armor.svg'),
  ('Capuche de cuir',    'head', 4,  0, 20,  1, 'assets/svg/skills/armor.svg'),
  -- Tier 2
  ('Cuirasse cloutee',   'body', 14, 1, 80,  2, 'assets/svg/skills/armor.svg'),
  ('Casque de bronze',   'head', 8,  1, 60,  2, 'assets/svg/skills/armor.svg'),
  -- Tier 3
  ('Armure de plates',   'body', 22, 2, 200, 3, 'assets/svg/skills/armor.svg'),
  ('Heaume de chevalier','head', 14, 1, 150, 3, 'assets/svg/skills/armor.svg');

-- ============================================================
-- Saison initiale
-- ============================================================
INSERT INTO seasons (label, started_at, active) VALUES ('Saison 1', CURDATE(), 1);

-- ============================================================
-- Récompenses de saison ELO
-- ============================================================
CREATE TABLE season_rewards (
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
  CONSTRAINT fk_sr_brute FOREIGN KEY (brute_id) REFERENCES brutes(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- Boss quotidien PvE
-- ============================================================
CREATE TABLE daily_bosses (
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

CREATE TABLE boss_attempts (
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
CREATE TABLE challenges (
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
CREATE TABLE black_market_offers (
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

CREATE TABLE brute_market_purchases (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  brute_id INT UNSIGNED NOT NULL,
  offer_id INT UNSIGNED NOT NULL,
  purchased_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_brute_offer (brute_id, offer_id),
  CONSTRAINT fk_bmp_brute FOREIGN KEY (brute_id) REFERENCES brutes(id) ON DELETE CASCADE,
  CONSTRAINT fk_bmp_offer FOREIGN KEY (offer_id) REFERENCES black_market_offers(id) ON DELETE CASCADE
) ENGINE=InnoDB;
