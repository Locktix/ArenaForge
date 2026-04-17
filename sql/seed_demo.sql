-- ============================================================
-- Compte de démo + adversaires IA pour peupler l'arène
-- Mot de passe "demodemo" (hash bcrypt pré-calculé)
-- ============================================================
-- À importer APRÈS schema.sql, dans la même BDD sélectionnée.
-- ============================================================

-- Compte de démo
INSERT INTO users (email, password_hash)
VALUES ('demo@arenaforge.local', '$2y$10$WsspUsKPGxTJfg0y7aLW4.6WB2zy3mdsnzQ/xfX.FV.QGkSxbp4sO');

SET @demo_uid = LAST_INSERT_ID();

-- Gladiateur principal
INSERT INTO brutes (user_id, name, level, xp, hp_max, strength, agility, endurance, appearance_seed)
VALUES (@demo_uid, 'Maximus', 1, 0, 55, 6, 6, 6,
        '{"body":1,"hair":2,"skin":1,"color":210}');
SET @maximus = LAST_INSERT_ID();
INSERT INTO brute_weapons (brute_id, weapon_id) SELECT @maximus, id FROM weapons WHERE name='Poings nus';

-- Adversaires IA (pour garantir au moins un match et peupler les tournois)
INSERT INTO users (email, password_hash) VALUES
  ('bot1@arenaforge.local', '$2y$10$WsspUsKPGxTJfg0y7aLW4.6WB2zy3mdsnzQ/xfX.FV.QGkSxbp4sO'),
  ('bot2@arenaforge.local', '$2y$10$WsspUsKPGxTJfg0y7aLW4.6WB2zy3mdsnzQ/xfX.FV.QGkSxbp4sO'),
  ('bot3@arenaforge.local', '$2y$10$WsspUsKPGxTJfg0y7aLW4.6WB2zy3mdsnzQ/xfX.FV.QGkSxbp4sO'),
  ('bot4@arenaforge.local', '$2y$10$WsspUsKPGxTJfg0y7aLW4.6WB2zy3mdsnzQ/xfX.FV.QGkSxbp4sO'),
  ('bot5@arenaforge.local', '$2y$10$WsspUsKPGxTJfg0y7aLW4.6WB2zy3mdsnzQ/xfX.FV.QGkSxbp4sO'),
  ('bot6@arenaforge.local', '$2y$10$WsspUsKPGxTJfg0y7aLW4.6WB2zy3mdsnzQ/xfX.FV.QGkSxbp4sO'),
  ('bot7@arenaforge.local', '$2y$10$WsspUsKPGxTJfg0y7aLW4.6WB2zy3mdsnzQ/xfX.FV.QGkSxbp4sO');

INSERT INTO brutes (user_id, name, level, xp, hp_max, strength, agility, endurance, appearance_seed)
SELECT id, 'Brutus',  1, 0, 50, 7, 4, 5, '{"body":2,"hair":0,"skin":2,"color":20}'  FROM users WHERE email='bot1@arenaforge.local';
SET @b1 = LAST_INSERT_ID();
INSERT INTO brute_weapons (brute_id, weapon_id) SELECT @b1, id FROM weapons WHERE name IN ('Poings nus','Masse');

INSERT INTO brutes (user_id, name, level, xp, hp_max, strength, agility, endurance, appearance_seed)
SELECT id, 'Agilo',   1, 0, 45, 4, 8, 5, '{"body":0,"hair":1,"skin":0,"color":130}' FROM users WHERE email='bot2@arenaforge.local';
SET @b2 = LAST_INSERT_ID();
INSERT INTO brute_weapons (brute_id, weapon_id) SELECT @b2, id FROM weapons WHERE name IN ('Poings nus','Dague');
INSERT INTO brute_skills  (brute_id, skill_id)  SELECT @b2, id FROM skills  WHERE name='Esquive';
INSERT INTO brute_pets    (brute_id, pet_id)    SELECT @b2, id FROM pets    WHERE name='Chien';

INSERT INTO brutes (user_id, name, level, xp, hp_max, strength, agility, endurance, appearance_seed)
SELECT id, 'Varenis', 2, 50, 55, 6, 5, 7, '{"body":1,"hair":3,"skin":1,"color":300}' FROM users WHERE email='bot3@arenaforge.local';
SET @b3 = LAST_INSERT_ID();
INSERT INTO brute_weapons (brute_id, weapon_id) SELECT @b3, id FROM weapons WHERE name IN ('Poings nus','Epee');
INSERT INTO brute_skills  (brute_id, skill_id)  SELECT @b3, id FROM skills  WHERE name IN ('Armure','Force brute');

INSERT INTO brutes (user_id, name, level, xp, hp_max, strength, agility, endurance, appearance_seed)
SELECT id, 'Gromash', 2, 40, 58, 8, 3, 6, '{"body":2,"hair":2,"skin":2,"color":45}'  FROM users WHERE email='bot4@arenaforge.local';
SET @b4 = LAST_INSERT_ID();
INSERT INTO brute_weapons (brute_id, weapon_id) SELECT @b4, id FROM weapons WHERE name IN ('Poings nus','Hache');
INSERT INTO brute_skills  (brute_id, skill_id)  SELECT @b4, id FROM skills  WHERE name='Rage';

INSERT INTO brutes (user_id, name, level, xp, hp_max, strength, agility, endurance, appearance_seed)
SELECT id, 'Selene',  1, 10, 48, 5, 7, 6, '{"body":0,"hair":2,"skin":1,"color":260}' FROM users WHERE email='bot5@arenaforge.local';
SET @b5 = LAST_INSERT_ID();
INSERT INTO brute_weapons (brute_id, weapon_id) SELECT @b5, id FROM weapons WHERE name IN ('Poings nus','Arc');
INSERT INTO brute_skills  (brute_id, skill_id)  SELECT @b5, id FROM skills  WHERE name='Coup critique';

INSERT INTO brutes (user_id, name, level, xp, hp_max, strength, agility, endurance, appearance_seed)
SELECT id, 'Octavius',3, 110, 62, 7, 6, 7, '{"body":1,"hair":0,"skin":0,"color":200}' FROM users WHERE email='bot6@arenaforge.local';
SET @b6 = LAST_INSERT_ID();
INSERT INTO brute_weapons (brute_id, weapon_id) SELECT @b6, id FROM weapons WHERE name IN ('Poings nus','Epee','Bouclier');
INSERT INTO brute_skills  (brute_id, skill_id)  SELECT @b6, id FROM skills  WHERE name IN ('Armure','Contre-attaque');
INSERT INTO brute_pets    (brute_id, pet_id)    SELECT @b6, id FROM pets    WHERE name='Loup';

INSERT INTO brutes (user_id, name, level, xp, hp_max, strength, agility, endurance, appearance_seed)
SELECT id, 'Nyx',     2, 60, 52, 5, 8, 5, '{"body":0,"hair":3,"skin":2,"color":340}' FROM users WHERE email='bot7@arenaforge.local';
SET @b7 = LAST_INSERT_ID();
INSERT INTO brute_weapons (brute_id, weapon_id) SELECT @b7, id FROM weapons WHERE name IN ('Poings nus','Dague');
INSERT INTO brute_skills  (brute_id, skill_id)  SELECT @b7, id FROM skills  WHERE name IN ('Esquive','Vol de vie');
INSERT INTO brute_pets    (brute_id, pet_id)    SELECT @b7, id FROM pets    WHERE name='Panthere';

-- ============================================================
-- Recrues (niveau 1, poings nus uniquement, stats modestes).
-- Objectif : donner une large pool d'adversaires accessibles
-- aux nouveaux joueurs pour qu'ils ne tombent pas systématiquement
-- contre un bot optimisé dès leur premier combat.
-- ============================================================
INSERT INTO users (email, password_hash) VALUES
  ('bot8@arenaforge.local',  '$2y$10$WsspUsKPGxTJfg0y7aLW4.6WB2zy3mdsnzQ/xfX.FV.QGkSxbp4sO'),
  ('bot9@arenaforge.local',  '$2y$10$WsspUsKPGxTJfg0y7aLW4.6WB2zy3mdsnzQ/xfX.FV.QGkSxbp4sO'),
  ('bot10@arenaforge.local', '$2y$10$WsspUsKPGxTJfg0y7aLW4.6WB2zy3mdsnzQ/xfX.FV.QGkSxbp4sO'),
  ('bot11@arenaforge.local', '$2y$10$WsspUsKPGxTJfg0y7aLW4.6WB2zy3mdsnzQ/xfX.FV.QGkSxbp4sO'),
  ('bot12@arenaforge.local', '$2y$10$WsspUsKPGxTJfg0y7aLW4.6WB2zy3mdsnzQ/xfX.FV.QGkSxbp4sO'),
  ('bot13@arenaforge.local', '$2y$10$WsspUsKPGxTJfg0y7aLW4.6WB2zy3mdsnzQ/xfX.FV.QGkSxbp4sO'),
  ('bot14@arenaforge.local', '$2y$10$WsspUsKPGxTJfg0y7aLW4.6WB2zy3mdsnzQ/xfX.FV.QGkSxbp4sO'),
  ('bot15@arenaforge.local', '$2y$10$WsspUsKPGxTJfg0y7aLW4.6WB2zy3mdsnzQ/xfX.FV.QGkSxbp4sO'),
  ('bot16@arenaforge.local', '$2y$10$WsspUsKPGxTJfg0y7aLW4.6WB2zy3mdsnzQ/xfX.FV.QGkSxbp4sO'),
  ('bot17@arenaforge.local', '$2y$10$WsspUsKPGxTJfg0y7aLW4.6WB2zy3mdsnzQ/xfX.FV.QGkSxbp4sO'),
  ('bot18@arenaforge.local', '$2y$10$WsspUsKPGxTJfg0y7aLW4.6WB2zy3mdsnzQ/xfX.FV.QGkSxbp4sO'),
  ('bot19@arenaforge.local', '$2y$10$WsspUsKPGxTJfg0y7aLW4.6WB2zy3mdsnzQ/xfX.FV.QGkSxbp4sO'),
  ('bot20@arenaforge.local', '$2y$10$WsspUsKPGxTJfg0y7aLW4.6WB2zy3mdsnzQ/xfX.FV.QGkSxbp4sO'),
  ('bot21@arenaforge.local', '$2y$10$WsspUsKPGxTJfg0y7aLW4.6WB2zy3mdsnzQ/xfX.FV.QGkSxbp4sO'),
  ('bot22@arenaforge.local', '$2y$10$WsspUsKPGxTJfg0y7aLW4.6WB2zy3mdsnzQ/xfX.FV.QGkSxbp4sO'),
  ('bot23@arenaforge.local', '$2y$10$WsspUsKPGxTJfg0y7aLW4.6WB2zy3mdsnzQ/xfX.FV.QGkSxbp4sO'),
  ('bot24@arenaforge.local', '$2y$10$WsspUsKPGxTJfg0y7aLW4.6WB2zy3mdsnzQ/xfX.FV.QGkSxbp4sO'),
  ('bot25@arenaforge.local', '$2y$10$WsspUsKPGxTJfg0y7aLW4.6WB2zy3mdsnzQ/xfX.FV.QGkSxbp4sO');

-- Chaque recrue : niveau 1, Poings nus uniquement, pas de skill, pas de pet.
-- Stats proches de la génération par défaut (hp 45-52, stats 4-6) pour rester à portée du joueur.
INSERT INTO brutes (user_id, name, level, xp, hp_max, strength, agility, endurance, appearance_seed)
SELECT id, 'Lucius',   1, 0, 48, 5, 5, 5, '{"body":1,"hair":0,"skin":0,"color":30}'  FROM users WHERE email='bot8@arenaforge.local';
INSERT INTO brute_weapons (brute_id, weapon_id) SELECT LAST_INSERT_ID(), id FROM weapons WHERE name='Poings nus';

INSERT INTO brutes (user_id, name, level, xp, hp_max, strength, agility, endurance, appearance_seed)
SELECT id, 'Marcus',   1, 0, 50, 4, 5, 6, '{"body":1,"hair":1,"skin":1,"color":60}'  FROM users WHERE email='bot9@arenaforge.local';
INSERT INTO brute_weapons (brute_id, weapon_id) SELECT LAST_INSERT_ID(), id FROM weapons WHERE name='Poings nus';

INSERT INTO brutes (user_id, name, level, xp, hp_max, strength, agility, endurance, appearance_seed)
SELECT id, 'Decimus',  1, 0, 46, 6, 4, 5, '{"body":2,"hair":0,"skin":2,"color":90}'  FROM users WHERE email='bot10@arenaforge.local';
INSERT INTO brute_weapons (brute_id, weapon_id) SELECT LAST_INSERT_ID(), id FROM weapons WHERE name='Poings nus';

INSERT INTO brutes (user_id, name, level, xp, hp_max, strength, agility, endurance, appearance_seed)
SELECT id, 'Cassius',  1, 0, 49, 5, 6, 4, '{"body":0,"hair":3,"skin":0,"color":120}' FROM users WHERE email='bot11@arenaforge.local';
INSERT INTO brute_weapons (brute_id, weapon_id) SELECT LAST_INSERT_ID(), id FROM weapons WHERE name='Poings nus';

INSERT INTO brutes (user_id, name, level, xp, hp_max, strength, agility, endurance, appearance_seed)
SELECT id, 'Flavius',  1, 0, 52, 4, 4, 6, '{"body":1,"hair":2,"skin":1,"color":150}' FROM users WHERE email='bot12@arenaforge.local';
INSERT INTO brute_weapons (brute_id, weapon_id) SELECT LAST_INSERT_ID(), id FROM weapons WHERE name='Poings nus';

INSERT INTO brutes (user_id, name, level, xp, hp_max, strength, agility, endurance, appearance_seed)
SELECT id, 'Drusus',   1, 0, 47, 6, 5, 5, '{"body":2,"hair":1,"skin":2,"color":180}' FROM users WHERE email='bot13@arenaforge.local';
INSERT INTO brute_weapons (brute_id, weapon_id) SELECT LAST_INSERT_ID(), id FROM weapons WHERE name='Poings nus';

INSERT INTO brutes (user_id, name, level, xp, hp_max, strength, agility, endurance, appearance_seed)
SELECT id, 'Gaius',    1, 0, 51, 5, 4, 6, '{"body":0,"hair":2,"skin":1,"color":220}' FROM users WHERE email='bot14@arenaforge.local';
INSERT INTO brute_weapons (brute_id, weapon_id) SELECT LAST_INSERT_ID(), id FROM weapons WHERE name='Poings nus';

INSERT INTO brutes (user_id, name, level, xp, hp_max, strength, agility, endurance, appearance_seed)
SELECT id, 'Titus',    1, 0, 45, 4, 6, 5, '{"body":0,"hair":0,"skin":0,"color":0}'   FROM users WHERE email='bot15@arenaforge.local';
INSERT INTO brute_weapons (brute_id, weapon_id) SELECT LAST_INSERT_ID(), id FROM weapons WHERE name='Poings nus';

INSERT INTO brutes (user_id, name, level, xp, hp_max, strength, agility, endurance, appearance_seed)
SELECT id, 'Quintus',  1, 0, 50, 6, 5, 4, '{"body":1,"hair":3,"skin":2,"color":280}' FROM users WHERE email='bot16@arenaforge.local';
INSERT INTO brute_weapons (brute_id, weapon_id) SELECT LAST_INSERT_ID(), id FROM weapons WHERE name='Poings nus';

INSERT INTO brutes (user_id, name, level, xp, hp_max, strength, agility, endurance, appearance_seed)
SELECT id, 'Aurelius', 1, 0, 48, 5, 5, 6, '{"body":1,"hair":1,"skin":0,"color":320}' FROM users WHERE email='bot17@arenaforge.local';
INSERT INTO brute_weapons (brute_id, weapon_id) SELECT LAST_INSERT_ID(), id FROM weapons WHERE name='Poings nus';

-- ============================================================
-- Recrues femmes (niveau 1, légèrement différenciées pour variété)
-- ============================================================
INSERT INTO brutes (user_id, name, level, xp, hp_max, strength, agility, endurance, appearance_seed)
SELECT id, 'Livia',    1, 0, 47, 4, 7, 4, '{"body":0,"hair":2,"skin":1,"color":10}'  FROM users WHERE email='bot18@arenaforge.local';
INSERT INTO brute_weapons (brute_id, weapon_id) SELECT LAST_INSERT_ID(), id FROM weapons WHERE name='Poings nus';

INSERT INTO brutes (user_id, name, level, xp, hp_max, strength, agility, endurance, appearance_seed)
SELECT id, 'Octavia',  1, 0, 50, 5, 5, 5, '{"body":0,"hair":3,"skin":0,"color":50}'  FROM users WHERE email='bot19@arenaforge.local';
INSERT INTO brute_weapons (brute_id, weapon_id) SELECT LAST_INSERT_ID(), id FROM weapons WHERE name='Poings nus';

INSERT INTO brutes (user_id, name, level, xp, hp_max, strength, agility, endurance, appearance_seed)
SELECT id, 'Claudia',  1, 0, 49, 6, 4, 5, '{"body":0,"hair":1,"skin":2,"color":100}' FROM users WHERE email='bot20@arenaforge.local';
INSERT INTO brute_weapons (brute_id, weapon_id) SELECT LAST_INSERT_ID(), id FROM weapons WHERE name='Poings nus';

-- ============================================================
-- Niveau 2 légers : poings nus + UNE arme basique, pas de skill.
-- Donnent un vrai palier au joueur qui monte de niveau.
-- ============================================================
INSERT INTO brutes (user_id, name, level, xp, hp_max, strength, agility, endurance, appearance_seed)
SELECT id, 'Galba',    2, 30, 53, 6, 5, 5, '{"body":1,"hair":0,"skin":1,"color":200}' FROM users WHERE email='bot21@arenaforge.local';
SET @bg = LAST_INSERT_ID();
INSERT INTO brute_weapons (brute_id, weapon_id) SELECT @bg, id FROM weapons WHERE name IN ('Poings nus','Dague');

INSERT INTO brutes (user_id, name, level, xp, hp_max, strength, agility, endurance, appearance_seed)
SELECT id, 'Crassus',  2, 25, 55, 7, 4, 5, '{"body":2,"hair":1,"skin":0,"color":40}'  FROM users WHERE email='bot22@arenaforge.local';
SET @bc = LAST_INSERT_ID();
INSERT INTO brute_weapons (brute_id, weapon_id) SELECT @bc, id FROM weapons WHERE name IN ('Poings nus','Lance');

INSERT INTO brutes (user_id, name, level, xp, hp_max, strength, agility, endurance, appearance_seed)
SELECT id, 'Tacitus',  2, 35, 51, 5, 6, 6, '{"body":1,"hair":2,"skin":2,"color":250}' FROM users WHERE email='bot23@arenaforge.local';
SET @bt = LAST_INSERT_ID();
INSERT INTO brute_weapons (brute_id, weapon_id) SELECT @bt, id FROM weapons WHERE name IN ('Poings nus','Arc');

INSERT INTO brutes (user_id, name, level, xp, hp_max, strength, agility, endurance, appearance_seed)
SELECT id, 'Valeria',  2, 20, 52, 5, 7, 5, '{"body":0,"hair":2,"skin":1,"color":300}' FROM users WHERE email='bot24@arenaforge.local';
SET @bv = LAST_INSERT_ID();
INSERT INTO brute_weapons (brute_id, weapon_id) SELECT @bv, id FROM weapons WHERE name IN ('Poings nus','Dague');

INSERT INTO brutes (user_id, name, level, xp, hp_max, strength, agility, endurance, appearance_seed)
SELECT id, 'Sabina',   2, 40, 54, 6, 5, 6, '{"body":0,"hair":3,"skin":0,"color":160}' FROM users WHERE email='bot25@arenaforge.local';
SET @bs = LAST_INSERT_ID();
INSERT INTO brute_weapons (brute_id, weapon_id) SELECT @bs, id FROM weapons WHERE name IN ('Poings nus','Masse');
