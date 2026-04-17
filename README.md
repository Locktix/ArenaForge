# ArenaForge

Jeu navigateur 1v1 automatisé — inspiré de *La Brute*. Forge ton gladiateur, affronte d'autres joueurs dans des combats au tour par tour entièrement résolus côté serveur, et grimpe au classement.

## Stack

- **Serveur** : PHP 8+ (XAMPP en local, hébergement mutualisé type o2switch en prod)
- **BDD** : MySQL via phpMyAdmin
- **Frontend** : HTML5 + CSS3 + JavaScript vanilla
- **Auth** : sessions PHP + `password_hash()` / `password_verify()`

## Installation locale (XAMPP)

1. **Cloner / copier** le dossier dans `C:\xampp\htdocs\ArenaForge\`.

2. **Démarrer XAMPP** : lancer Apache et MySQL depuis le panneau de contrôle.

3. **Créer la base de données** :
   - Ouvrir phpMyAdmin : http://localhost/phpmyadmin
   - Onglet **Importer** → sélectionner [sql/schema.sql](sql/schema.sql) → **Exécuter**
   - (Optionnel) Importer [sql/seed_demo.sql](sql/seed_demo.sql) pour créer le compte de démo et des adversaires IA

4. **Configurer la connexion** : par défaut, [includes/db.php](includes/db.php) se connecte en `root` sans mot de passe (configuration XAMPP standard). Modifier si besoin.

5. **Accéder au jeu** : http://localhost/ArenaForge/

## Déploiement sur hébergement mutualisé (o2switch, etc.)

Tous les chemins sont relatifs et l'`index.php` est à la racine — le projet se déploie tel quel :

1. Uploader le contenu du dossier dans `public_html/` (racine web) via FTP / cPanel.
2. Créer une BDD MySQL via cPanel, importer `sql/schema.sql` puis `sql/seed_demo.sql`.
3. Éditer `includes/db.php` avec les identifiants fournis par l'hébergeur.
4. Accéder à `https://votre-domaine.tld/`.

Le jeu fonctionne aussi dans un sous-dossier (`public_html/jeu/`) sans modification — les chemins relatifs s'adaptent.

## Compte de démonstration

Après avoir importé `seed_demo.sql` :

- **E-mail** : `demo@arenaforge.local`
- **Mot de passe** : `demodemo`
- Gladiateur pré-créé : **Maximus** (niveau 1)
- 25 adversaires IA dont 13 recrues niveau 1 pour les débutants

## Structure du projet

```
/
├── index.php, dashboard.php, brute.php, fight.php,
│   tournament.php, quests.php, pupils.php, ranking.php,
│   logout.php, register.php   (pages web à la racine)
├── _nav.php, _gladiator.php   (partials PHP inclus par les pages)
├── /api                       Endpoints POST (JSON)
├── /includes                  Logique métier : db, auth, combat, tournoi, quêtes, pupilles
├── /assets
│   ├── /css, /js
│   └── /svg                   logos, gladiateur, armes, compétences, pets, quêtes, UI
└── /sql                       schema.sql, seed_demo.sql
```

## Boucle de jeu

1. **Inscription** → création d'un gladiateur (nom unique, apparence + stats dérivées du nom).
2. **Combat** : matchmaking automatique (niveau ± 2). Résolution serveur, log stocké en JSON, rejoué côté client.
3. **XP** : +3 en cas de victoire, +1 sinon. +1 XP passive au maître pour chaque combat du pupille.
4. **Level-up** : à chaque niveau gagné, choix entre 3 bonus aléatoires (stat / arme / compétence / compagnon animal).
5. **Limites** : 6 combats/jour + un compteur de **combats bonus** persistant alimenté par :
   - Quêtes journalières (certaines donnent +1 combat)
   - Placement au tournoi (champion +3, finaliste +1)
   - Palier de 10 combats cumulés par les pupilles → +1 combat

## Moteur de combat (résumé)

- Ordre d'attaque selon agilité (+ aléa) recalculé chaque round. Maître + pets jouent tous.
- Dégâts : `rand(weapon.min, weapon.max) + strength/2`, modifiés par skills (force brute, rage, crit).
- Esquive : base 5 % + écart d'agilité, plafond 75 %.
- Compétences : **Force brute, Esquive, Contre-attaque, Régénération, Coup critique, Armure, Rage, Vol de vie**.
- Armes : **Poings nus, Dague, Épée, Hache, Masse, Lance, Arc, Bouclier**.
- Compagnons : **Chien, Loup, Panthère, Ours** (combattent aux côtés du maître).
- Événements log : `start / hit / dodge / counter / regen / lifesteal / down / timeout / end`.

## Crédits / licence

Projet pédagogique inspiré de la mécanique libre *La Brute*. Assets SVG 100 % originaux.
