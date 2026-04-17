# ArenaForge

Jeu navigateur 1v1 automatisé — inspiré de *La Brute*. Forge ton gladiateur, affronte d'autres joueurs dans des combats au tour par tour entièrement résolus côté serveur, et grimpe au classement.

## Stack

- **Serveur** : XAMPP (Apache + PHP 8+)
- **BDD** : MySQL via phpMyAdmin
- **Frontend** : HTML5 + CSS3 + JavaScript vanilla
- **Auth** : sessions PHP + `password_hash()` / `password_verify()`

## Installation sur XAMPP

1. **Cloner / copier** le dossier dans `C:\xampp\htdocs\ArenaForge\`.

2. **Démarrer XAMPP** : lancer Apache et MySQL depuis le panneau de contrôle.

3. **Créer la base de données** :
   - Ouvrir phpMyAdmin : http://localhost/phpmyadmin
   - Onglet **Importer** → sélectionner [sql/schema.sql](sql/schema.sql) → **Exécuter**
   - (Optionnel) Importer [sql/seed_demo.sql](sql/seed_demo.sql) pour créer le compte de démo et quelques adversaires IA

4. **Configurer la connexion** : par défaut, [includes/db.php](includes/db.php) se connecte en `root` sans mot de passe (configuration XAMPP standard). Modifier si besoin.

5. **Accéder au jeu** : http://localhost/ArenaForge/public/

## Compte de démonstration

Après avoir importé `seed_demo.sql` :

- **E-mail** : `demo@arenaforge.local`
- **Mot de passe** : `demodemo`
- Gladiateur pré-créé : **Maximus** (niveau 1)
- Adversaires IA disponibles : **Brutus**, **Agilo**, **Varenis**

## Structure du projet

```
/ArenaForge
├── /public              Pages accessibles (auth, dashboard, brute, fight, ranking)
├── /api                 Endpoints POST (JSON) : login, register, create_brute, start_fight, level_up
├── /includes            db.php, auth.php, combat_engine.php, brute_generator.php
├── /assets
│   ├── /css             main.css
│   ├── /js              auth.js, create.js, brute.js, fight.js
│   └── /svg             logo, gladiator, weapons, skills, ui, decor
└── /sql                 schema.sql, seed_demo.sql
```

## Boucle de jeu

1. **Inscription** → création d'un gladiateur (nom unique, apparence + stats dérivées du nom).
2. **Lancer un combat** : matchmaking automatique (niveau ± 2). Résolution serveur, log stocké en JSON.
3. **Replay animé** côté client : attaques, esquives, crits, régénération, fin de combat.
4. **XP** : +3 en cas de victoire, +1 sinon. +1 XP passive au maître pour chaque combat du pupille.
5. **Level-up** : à chaque niveau gagné, choix entre 3 bonus aléatoires (stat, arme ou compétence).
6. **Limite** : 6 combats par jour et par gladiateur.

## Moteur de combat (résumé)

- Ordre d'attaque selon agilité (+ aléa).
- Dégâts : `rand(weapon.min, weapon.max) + strength/2`, modifiés par skills (force brute, rage, crit).
- Esquive : base 5 % + écart d'agilité, plafond 75 %.
- Compétences couvertes : **Force brute, Esquive, Contre-attaque, Régénération, Coup critique, Armure, Rage, Vol de vie**.
- Armes : **Poings nus, Dague, Épée, Hache, Masse, Lance, Arc, Bouclier**.
- Log structuré : événements `start / hit / dodge / counter / regen / lifesteal / timeout / end`.

## Crédits / licence

Projet pédagogique inspiré de la mécanique libre *La Brute*. Assets SVG 100 % originaux.
