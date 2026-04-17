# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Projet

ArenaForge — jeu navigateur 1v1 automatisé inspiré de *La Brute*. Stack : XAMPP (Apache + PHP 8+), MySQL, HTML/CSS/JS vanilla. Pas de framework, pas de composer, pas de build step : les fichiers PHP sont servis directement par Apache depuis `htdocs`.

## Commandes courantes

Aucun build ni bundler. Le projet vit dans `C:\xampp\htdocs\ArenaForge\` et est servi à http://localhost/ArenaForge/. Les pages HTML PHP sont à la racine (déploiement o2switch-friendly), l'`index.php` est donc le point d'entrée direct.

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
- **Pas de tests automatisés** à ce jour — valider manuellement via http://localhost/ArenaForge/ avec le compte `demo@arenaforge.local` / `demodemo`.

## Architecture

### Séparation des rôles

- **Racine** : toutes les pages HTML PHP (`index.php`, `dashboard.php`, `brute.php`, `fight.php`, `tournament.php`, `quests.php`, `pupils.php`, `ranking.php`, `logout.php`, `register.php`) + partials (`_nav.php`, `_gladiator.php`). Formulaires soumis via `fetch()` en JS vers `api/`.
- `api/` : endpoints POST renvoyant du JSON (`{ ok, error?, redirect?, ... }`). Chaque endpoint vérifie session + CSRF + ownership. Le client suit `data.redirect` à la main (URLs relatives).
- `includes/` : toute la logique métier réutilisable. `db.php` expose un singleton PDO via `db()`. `auth.php` gère sessions, `require_login()`, `csrf_token()` / `csrf_check()`, `current_brute()`, et l'helper `h()` pour l'échappement HTML.
- `assets/` : statiques (CSS, JS, SVG). **Tous les chemins sont relatifs** (ex. `assets/css/main.css`, `api/start_fight.php`) pour que le projet fonctionne déployé à la racine d'un domaine ou dans un sous-dossier, sans configuration.

### Moteur de combat — `includes/combat_engine.php`

C'est le cœur du jeu et le fichier à comprendre en priorité. Invariants :

- **Résolution 100 % côté serveur**. Le client ne fait que rejouer un log. Ne jamais calculer les dégâts en JS.
- `run_fight($b1, $b2)` retourne `{ winner_id, log }`. Le log est stocké dans `fights.log_json` (MEDIUMTEXT) et rejoué par `assets/js/fight.js`.
- Structure du log : liste d'événements typés. Types émis : `start` (avec `teams`), `hit` (avec `crit` bool), `dodge`, `counter`, `regen`, `lifesteal`, `down` (pet KO), `timeout`, `end`. **Toute modification du moteur qui ajoute un type doit être gérée dans `fight.js` (switch dans `play()`)** sinon l'animation ignorera l'événement.
- **Chaque combattant a un `slot`** : `L0` / `R0` pour les maîtres, `L1+` / `R1+` pour les pets (1 pet max actuellement, mais la structure supporte `L1`, `L2`…). Les events `hit` / `dodge` / `regen` / `lifesteal` / `down` portent `attacker_slot`/`defender_slot`/`actor_slot`. Le client `fight.js` utilise le slot pour cibler le bon sprite DOM, avec fallback sur le nom pour les vieux logs.
- Les compétences sont appliquées par `has_skill($fighter, $effect_type)` qui cherche un skill par son champ `effect_type` (clé stable en BDD : `dmg_bonus_pct`, `dodge_pct`, `counter_pct`, `regen_flat`, `crit_bonus_pct`, `armor_flat`, `rage_pct`, `lifesteal_pct`). **Ajouter une compétence** = insérer une ligne dans `skills` avec un nouvel `effect_type` ET brancher ce type dans `resolve_attack` / `resolve_raw_hit`. Note : les pets n'appliquent ni skills ni armes (leurs `skills`/`weapons` sont vides) — tout effet doit tester `$f['role'] === 'master'`.
- **Cible d'attaque** : le maître adverse a 60 % de chance d'être ciblé en priorité, sinon un pet vivant aléatoire. Le combat se termine quand un maître tombe à 0 PV (la perte d'un pet génère un event `down` mais le combat continue).
- Ordre de frappe : agilité + `random_int(1,6)` recalculé à chaque round, tous combattants confondus. Plafond de 60 rounds puis victoire aux PV restants du maître (`timeout`).

### Tournoi quotidien — `includes/tournament_engine.php`

- 1 tournoi par jour (table `tournaments`, `tour_date` unique). Bracket de 8 participants à élimination directe. Les combats de tournoi (`fights.context = 'tournament'`) ne consomment **pas** les 6 combats journaliers et n'apparaissent pas dans le compteur `fights_today`.
- `ensure_today_tournament()` crée ou récupère le tournoi du jour. `join_tournament($bruteId)` inscrit un joueur, `run_tournament($id)` remplit les slots manquants avec des IA (`bot%@arenaforge.local`) puis résout l'intégralité du bracket en une passe. Les récompenses XP par placement sont dans `TOURNAMENT_XP`.
- **Les IA ne gagnent pas d'XP** (flag `tournament_entries.is_ai`). Les brutes humaines gagnent leur XP via `assign_tournament_placement` qui applique aussi les level-ups en cascade.

### Quêtes journalières — `includes/quest_engine.php`

- 3 quêtes aléatoires par brute/jour (table `brute_quests`, PK `brute_id, quest_code, quest_date`). Les définitions sont dans `quest_definitions` (rechargeable via `schema.sql`).
- `ensure_daily_quests()` tire les 3 quêtes au premier appel de la journée. `update_quests_after_fight()` est appelée par `api/start_fight.php` après chaque combat — elle scanne le log pour compter crits, esquives, dégâts, etc. **La logique de chaque code-quête est hardcodée dans un `switch`** ; ajouter un code = ajouter l'entrée SQL ET le cas dans le switch.
- `claim_quest()` applique l'XP de récompense + déclenche un éventuel level-up. Côté client, `assets/js/quests.js` réclame via `api/quest_claim.php`.

### Pupilles — `includes/pupil_helper.php`

- Relations dans `pupils` (master_id → pupil_id) + `brutes.master_id` (cache direct). Créées par `create_brute()` quand le formulaire d'inscription reçoit un nom de maître valide.
- Chaque combat d'arène (pas tournoi) d'un pupille rapporte 1 XP passif au maître direct (appliqué dans `api/start_fight.php`).
- `public/pupils.php` expose un lien de parrainage `dashboard.php?master=<nom>` qui pré-remplit le champ maître à l'inscription.

### Animaux de compagnie

- Table `pets` (bestiaire global), table `brute_pets` (appartenance). **1 pet max par brute** pour l'instant : le pool de level-up n'offre pas de pet si la brute en a déjà un, et `api/level_up.php` refuse une deuxième acquisition.
- Un pet combat aux côtés du maître via `build_team()` qui ajoute les combattants pet aux slots `L1` / `R1`. Pas de skills ni d'arme : damage piochée sur `pets.damage_min`/`damage_max`, `pets.agility` pour l'ordre de frappe et l'esquive.
- Les icônes sont dans `assets/svg/pets/*.svg`. Le sprite d'un pet dans l'arène est un simple `<img>` avec `icon_path`, pas un SVG paramétré comme le gladiateur.

### Génération déterministe — `includes/brute_generator.php`

`generate_appearance()` et `generate_stats()` utilisent un LCG seedé sur `md5(name)`. Même nom → mêmes stats/apparence. Le `appearance_seed` est stocké en JSON dans la colonne du même nom ; `public/_gladiator.php` le décode pour rendre le portrait SVG inline. Garder cette détermination : ne pas remplacer par `random_int()` sans migration.

### Flux level-up

1. `api/start_fight.php`, `api/quest_claim.php` et `tournament_engine.php::assign_tournament_placement` calculent le nouveau niveau via `xp_for_level($level+1)` et positionnent `brutes.pending_levelup = 1` si nécessaire.
2. Tant que `pending_levelup = 1`, `start_fight.php` et `tournament_join.php` refusent leur action.
3. `public/brute.php` construit un pool de 3 choix aléatoires (stat / arme non possédée / skill non possédé / pet si la brute n'en a pas) et les affiche.
4. `api/level_up.php` applique le choix (format `"type:key"` — `stat:strength`, `weapon:5`, `skill:3`, `pet:2`) et remet `pending_levelup = 0`.

Le mapping des stats autorisées est dupliqué entre `brute_generator.php` (`level_up_bonuses_pool`) et `api/level_up.php` (`$allowed`). Les garder synchronisés — la liste blanche dans `level_up.php` est la source de sécurité (évite l'injection de nom de colonne).

### Conventions

- **Sécurité SQL** : toujours PDO avec placeholders `?`. Une seule exception assumée, `level_up.php` interpole un nom de colonne — protégée par whitelist. Ne pas étendre ce pattern.
- **CSRF** : tout endpoint POST fait `csrf_check($_POST['csrf'])`. Les formulaires HTML incluent `<input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">`.
- **Échappement** : utiliser `h()` (depuis `auth.php`) pour toute sortie HTML de variables. Les `json_encode()` pour les attributs JS sont ok.
- **Noms de brute** : regex `^[A-Za-z0-9_\-]{3,20}$` imposée côté API. Unique en BDD. Ne pas autoriser d'autres caractères sans revoir `seed_from_name()` (qui `strtolower` + `trim`).
- **Limites métier** : 6 combats/jour/brute, reset basé sur `last_fight_date != CURDATE()`. Appliqué dans `api/start_fight.php`.
- **Combats bonus** : colonne `brutes.bonus_fights_available` — compteur **persistant** (non-remis à zéro chaque jour) alimenté par trois sources :
  - `quest_definitions.reward_bonus_fights` (certaines quêtes en donnent, ex. `win_5`, `flawless_1`, `upset_1`)
  - `TOURNAMENT_BONUS_FIGHTS` dans `tournament_engine.php` (champion +3, finaliste +1)
  - Palier pupille : chaque tranche de 10 combats cumulés par les pupilles (compteur `brutes.pupil_bonus_progress`) → +1 combat bonus au maître
  - `api/start_fight.php` consomme un bonus **uniquement** si les 6 combats journaliers sont épuisés (ne touche pas à `fights_today`)

### Assets SVG

- Palette fixe définie dans `assets/css/main.css` (`:root`) : bruns/ocres + accents dorés (`--accent`, `--accent-2`) + rouge sang (`--danger`, `--hp`). Les nouveaux SVG doivent s'y tenir.
- `assets/svg/gladiator/gladiator_sprites.svg` contient une bibliothèque de `<symbol>` (corps/cheveux/têtes/poses) référençable via `<use href="...#id">`. Le rendu runtime du portrait se fait cependant via `public/_gladiator.php` (SVG inline paramétré par `$appearance`) — les deux systèmes coexistent, `_gladiator.php` est celui utilisé en prod.

## Points d'attention

- Les chemins PHP `require_once __DIR__ . '/...'` sont fragiles si on déplace un fichier entre `public/`, `api/` ou `includes/` — vérifier les chemins relatifs après tout déplacement.
- Le hash bcrypt dans `sql/seed_demo.sql` correspond au mot de passe `demodemo`. Si le compte de démo doit être régénéré avec un autre mot de passe, utiliser la commande `php -r` ci-dessus pour générer un nouveau hash.
- Le projet suppose `root` sans mot de passe MySQL (XAMPP par défaut). Pour un autre environnement, modifier les constantes dans `includes/db.php`.
