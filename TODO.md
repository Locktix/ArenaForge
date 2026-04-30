# ArenaForge — Roadmap d'enrichissement

Suivi des 10 fonctionnalités majeures ajoutées au jeu. Chaque feature est
additive : aucune table existante n'est supprimée, aucune colonne renommée.
La migration SQL `sql/migrate_enrichment.sql` est rejouable et préserve les
comptes/brutes/historique.

## Statut

- [x] **1. Effets de statut** — saignement, poison, étourdissement appliqués
      via les compétences existantes (Coup critique → bleed, Vol de vie →
      poison, Force brute → stun chance). Nouveaux events `status_apply`,
      `status_tick`, `status_expire` dans le log + animations dédiées.
- [x] **2. Vitesse de replay + skip** — contrôles 0.5x / 1x / 2x / 4x +
      bouton "Aller au résultat" dans `public/fight.php`.
- [x] **3. Défi direct** — table `challenges`, API `api/challenge_*.php`,
      page `public/challenges.php` (boîte de réception + envoi).
- [x] **4. Divisions ELO + récompenses fin de saison** — paliers I/II/III/IV
      dérivés du MMR, table `season_rewards` pour archiver les snapshots.
      Récompense en or + titre selon le palier max atteint.
- [x] **5. Ultimes actifs** — colonne `skills.is_ultimate`, déclenchement
       1×/combat sur condition (PV bas, premier crit). Trois ultimes ajoutés.
- [x] **6. Marché noir + or** — colonne `brutes.gold`, table
      `black_market_offers` régénérée chaque jour. Page `public/market.php`.
- [x] **7. Streak journalier** — colonnes `users.streak_days`,
      `users.last_login_date`. Récompenses paliers 3/7/14/30 jours.
      Composant streak intégré au dashboard.
- [x] **8. Boss PvE du jour** — table `daily_bosses` (1/jour) + table
      `boss_attempts` (un essai par brute). Page `public/boss.php` avec
      classement par dégâts infligés.
- [x] **9. Combats en duo (2v2)** — un maître + son pupille contre un autre
      duo. Nouveau context `duo` dans `fights`. Bouton dédié sur la fiche
      pupille.
- [x] **10. Stats détaillées par brute** — page `public/stats.php?id=X`
      avec winrate, crit rate, dégâts moyens, top adversaires.

## Notes d'implémentation

- Tous les nouveaux endpoints respectent CSRF + ownership.
- Les SVG manquants utilisent des icônes existantes (réemploi de la
  bibliothèque). Aucune nouvelle dépendance.
- Le moteur de combat conserve la rétrocompatibilité : les vieux logs
  (sans event `status_*`) sont rejoués sans erreur.
- La migration utilise `IF NOT EXISTS` / `INSERT IGNORE` partout pour
  être rejouable sans corruption.

## Roadmap v2 — 2e vague (8 features)

Migration : `sql/migrate_enrichment_v2.sql`

- [x] **F1. Centre de notifications** — panneau d'actions sur la page
      brute (level-up, quêtes, défis, boss, marché, streak, pet à évoluer).
- [x] **F2. Bouton revanche** — sur fight.php après une défaite, envoie
      automatiquement un défi direct au vainqueur.
- [x] **F3. Évolution des pets** — Chien→Molosse, Loup→Loup alpha, etc.
      après 50 combats partagés. Stats améliorées.
- [x] **F4. Chat de clan** — `clan_messages` + polling 30 s + anti-spam 5 s.
- [x] **F5. Tournoi hebdomadaire 16 joueurs** — déjà en place dans le code
      (engine + UI + API). Onglet "Hebdomadaire" dans tournament.php.
- [x] **F6. Météo d'arène** — 4 types (clear/rain/sandstorm/heat) tirés
      à chaque combat, modifient crit/esquive/dégâts/PV.
- [x] **F7. Codex / bestiaire** — usage tracking + 3 paliers de lore par
      item (25/100/250 utilisations). Page `public/codex.php`.
- [x] **F8. Bots auto-fight quotidien** — quota seedé chaque jour, tick
      incrémental (max 3 combats par chargement, proba 60 %).

## Déploiement v2 (sur o2switch existant)

1. Importer `sql/migrate_enrichment_v2.sql` (commenter `USE arenaforge`)
2. Upload des fichiers PHP modifiés/nouveaux via FTP
3. Upload du CSS + JS modifiés
4. Aucune perte de données — comptes, brutes, historique, codex préservés
