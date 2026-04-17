-- ============================================================
-- Compte de démo + adversaires IA pour peupler l'arène
-- Mot de passe "demodemo" (hash bcrypt pré-calculé)
-- ============================================================
USE arenaforge;

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

-- Adversaires IA (pour garantir au moins un match)
INSERT INTO users (email, password_hash) VALUES
  ('bot1@arenaforge.local', '$2y$10$WsspUsKPGxTJfg0y7aLW4.6WB2zy3mdsnzQ/xfX.FV.QGkSxbp4sO'),
  ('bot2@arenaforge.local', '$2y$10$WsspUsKPGxTJfg0y7aLW4.6WB2zy3mdsnzQ/xfX.FV.QGkSxbp4sO'),
  ('bot3@arenaforge.local', '$2y$10$WsspUsKPGxTJfg0y7aLW4.6WB2zy3mdsnzQ/xfX.FV.QGkSxbp4sO');

INSERT INTO brutes (user_id, name, level, xp, hp_max, strength, agility, endurance, appearance_seed)
SELECT id, 'Brutus',  1, 0, 50, 7, 4, 5, '{"body":2,"hair":0,"skin":2,"color":20}'  FROM users WHERE email='bot1@arenaforge.local';
SET @b1 = LAST_INSERT_ID();
INSERT INTO brute_weapons (brute_id, weapon_id) SELECT @b1, id FROM weapons WHERE name IN ('Poings nus','Masse');

INSERT INTO brutes (user_id, name, level, xp, hp_max, strength, agility, endurance, appearance_seed)
SELECT id, 'Agilo',   1, 0, 45, 4, 8, 5, '{"body":0,"hair":1,"skin":0,"color":130}' FROM users WHERE email='bot2@arenaforge.local';
SET @b2 = LAST_INSERT_ID();
INSERT INTO brute_weapons (brute_id, weapon_id) SELECT @b2, id FROM weapons WHERE name IN ('Poings nus','Dague');
INSERT INTO brute_skills  (brute_id, skill_id)  SELECT @b2, id FROM skills  WHERE name='Esquive';

INSERT INTO brutes (user_id, name, level, xp, hp_max, strength, agility, endurance, appearance_seed)
SELECT id, 'Varenis', 2, 50, 55, 6, 5, 7, '{"body":1,"hair":3,"skin":1,"color":300}' FROM users WHERE email='bot3@arenaforge.local';
SET @b3 = LAST_INSERT_ID();
INSERT INTO brute_weapons (brute_id, weapon_id) SELECT @b3, id FROM weapons WHERE name IN ('Poings nus','Epee');
INSERT INTO brute_skills  (brute_id, skill_id)  SELECT @b3, id FROM skills  WHERE name IN ('Armure','Force brute');
