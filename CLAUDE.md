# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Projet

ArenaForge — jeu navigateur 1v1 automatisé inspiré de *La Brute*. Stack : XAMPP (Apache + PHP 8+), MySQL, HTML/CSS/JS vanilla. Pas de framework, pas de composer, pas de build step : les fichiers PHP sont servis directement par Apache depuis `htdocs`.

## Commandes courantes

Aucun build ni bundler. Le projet vit dans `C:\xampp\htdocs\ArenaForge\` et est servi à http://localhost/ArenaForge/public/.

- **Lint PHP** (syntaxe, obligatoire après toute modif PHP) :
  ```bash
  /c/xampp/php/php.exe -l <fichier>
  # ou sur tout le projet :
  for f in includes/*.php api/*.php public/*.php; do /c/xampp/php/php.exe -l "$f"; done
  ```
- **Générer un hash bcrypt** (pour les seeds SQL) :
  ```bash
  /c/xampp/php/php.exe -r "echo password_hash('monMotDePasse', PASSWORD_BCRYPT);"
  ```
- **Installer / réinitialiser la BDD** : importer `sql/schema.sql` puis `sql/seed_demo.sql` via phpMyAdmin (http://localhost/phpmyadmin). `schema.sql` fait `DROP TABLE IF EXISTS` en tête, donc rejouable.
- **Pas de tests automatisés** à ce jour — valider manuellement via http://localhost/ArenaForge/public/ avec le compte `demo@arenaforge.local` / `demodemo`.

## Architecture

### Séparation des rôles

- `public/` : pages HTML rendues par PHP, accessibles en GET. Formulaires soumis via `fetch()` en JS vers `api/`. Pas de mélange HTML/logique lourde dans `public/` — les pages sont des vues qui appellent les helpers.
- `api/` : endpoints POST renvoyant du JSON (`{ ok, error?, redirect?, ... }`). Chaque endpoint vérifie session + CSRF + ownership. Le client suit `data.redirect` à la main.
- `includes/` : toute la logique métier réutilisable. `db.php` expose un singleton PDO via `db()`. `auth.php` gère sessions, `require_login()`, `csrf_token()` / `csrf_check()`, `current_brute()`, et l'helper `h()` pour l'échappement HTML.
- `assets/` : statiques (CSS, JS, SVG). Les chemins dans le code sont **absolus depuis la racine web** (`/ArenaForge/assets/...`, `/ArenaForge/api/...`) car Apache sert `htdocs` comme racine.

### Moteur de combat — `includes/combat_engine.php`

C'est le cœur du jeu et le fichier à comprendre en priorité. Invariants :

- **Résolution 100 % côté serveur**. Le client ne fait que rejouer un log. Ne jamais calculer les dégâts en JS.
- `run_fight($b1, $b2)` retourne `{ winner_id, log }`. Le log est stocké dans `fights.log_json` (MEDIUMTEXT) et rejoué par `assets/js/fight.js`.
- Structure du log : liste d'événements typés. Types émis : `start`, `hit` (avec `crit` bool), `dodge`, `counter`, `regen`, `lifesteal`, `timeout`, `end`. **Toute modification du moteur qui ajoute un type doit être gérée dans `fight.js` (switch dans `play()`)** sinon l'animation ignorera l'événement.
- Les compétences sont appliquées par `has_skill($fighter, $effect_type)` qui cherche un skill par son champ `effect_type` (clé stable en BDD : `dmg_bonus_pct`, `dodge_pct`, `counter_pct`, `regen_flat`, `crit_bonus_pct`, `armor_flat`, `rage_pct`, `lifesteal_pct`). **Ajouter une compétence** = insérer une ligne dans `skills` avec un nouvel `effect_type` ET brancher ce type dans `resolve_attack` / `resolve_raw_hit`.
- Ordre de frappe : agilité + `random_int(1,6)`. Plafond de 60 tours puis victoire aux PV restants (`timeout`).

### Génération déterministe — `includes/brute_generator.php`

`generate_appearance()` et `generate_stats()` utilisent un LCG seedé sur `md5(name)`. Même nom → mêmes stats/apparence. Le `appearance_seed` est stocké en JSON dans la colonne du même nom ; `public/_gladiator.php` le décode pour rendre le portrait SVG inline. Garder cette détermination : ne pas remplacer par `random_int()` sans migration.

### Flux level-up

1. `api/start_fight.php` calcule le nouveau niveau via `xp_for_level($level+1)` et positionne `brutes.pending_levelup = 1` si nécessaire.
2. Tant que `pending_levelup = 1`, `start_fight.php` refuse les combats.
3. `public/brute.php` construit un pool de 3 choix aléatoires (stat / arme non possédée / skill non possédé) et les affiche.
4. `api/level_up.php` applique le choix (format `"type:key"` — `stat:strength`, `weapon:5`, `skill:3`) et remet `pending_levelup = 0`.

Le mapping des stats autorisées est dupliqué entre `brute_generator.php` (`level_up_bonuses_pool`) et `api/level_up.php` (`$allowed`). Les garder synchronisés — la liste blanche dans `level_up.php` est la source de sécurité (évite l'injection de nom de colonne).

### Conventions

- **Sécurité SQL** : toujours PDO avec placeholders `?`. Une seule exception assumée, `level_up.php` interpole un nom de colonne — protégée par whitelist. Ne pas étendre ce pattern.
- **CSRF** : tout endpoint POST fait `csrf_check($_POST['csrf'])`. Les formulaires HTML incluent `<input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">`.
- **Échappement** : utiliser `h()` (depuis `auth.php`) pour toute sortie HTML de variables. Les `json_encode()` pour les attributs JS sont ok.
- **Noms de brute** : regex `^[A-Za-z0-9_\-]{3,20}$` imposée côté API. Unique en BDD. Ne pas autoriser d'autres caractères sans revoir `seed_from_name()` (qui `strtolower` + `trim`).
- **Limites métier** : 6 combats/jour/brute, reset basé sur `last_fight_date != CURDATE()`. Appliqué dans `api/start_fight.php`.

### Assets SVG

- Palette fixe définie dans `assets/css/main.css` (`:root`) : bruns/ocres + accents dorés (`--accent`, `--accent-2`) + rouge sang (`--danger`, `--hp`). Les nouveaux SVG doivent s'y tenir.
- `assets/svg/gladiator/gladiator_sprites.svg` contient une bibliothèque de `<symbol>` (corps/cheveux/têtes/poses) référençable via `<use href="...#id">`. Le rendu runtime du portrait se fait cependant via `public/_gladiator.php` (SVG inline paramétré par `$appearance`) — les deux systèmes coexistent, `_gladiator.php` est celui utilisé en prod.

## Points d'attention

- Les chemins PHP `require_once __DIR__ . '/...'` sont fragiles si on déplace un fichier entre `public/`, `api/` ou `includes/` — vérifier les chemins relatifs après tout déplacement.
- Le hash bcrypt dans `sql/seed_demo.sql` correspond au mot de passe `demodemo`. Si le compte de démo doit être régénéré avec un autre mot de passe, utiliser la commande `php -r` ci-dessus pour générer un nouveau hash.
- Le projet suppose `root` sans mot de passe MySQL (XAMPP par défaut). Pour un autre environnement, modifier les constantes dans `includes/db.php`.
