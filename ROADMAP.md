# ArenaForge — Roadmap long terme

## Priorité immédiate (en cours)

- [x] PWA — manifest + service worker, installable sur mobile
- [x] Profil public partageable — page brute accessible sans compte
- [x] Chat global — polling toutes les 5-10 sec, pas de WebSocket

---

## Contenu (rétention joueurs)

- [ ] Saisons — reset partiel tous les 3 mois, classement saisonnier, récompenses exclusives
- [x] Donjons — séquences de 3-5 boss enchaînés avec loot progressif (Crypte/Forteresse/Abîsse, HP persistants entre salles)
- [x] Arbre de compétences — vrai arbre avec branches au lieu d'1 skill random
- [ ] Équipement set — bonus si 2-3 pièces de la même famille portées
- [ ] Système de réputation — factions, récompenses selon alignement
- [ ] Quêtes narratives — histoire par arc, pas juste "gagne 5 combats"

---

## Social

- [ ] Système d'amis — suivre un joueur, voir ses combats récents
- [ ] Tournois personnalisés — tournoi privé entre amis/clan
- [x] Hall of fame — top 10 performances all-time (niveau, victoires, tournois, pic MMR, mentors)

---

## Qualité de vie technique

- [ ] Web Push notifications — alerter défi reçu, tournoi lancé (sans WebSocket)
- [ ] Mode spectateur — replay de n'importe quel combat public
- [ ] API publique JSON — /api/public/brute/nom pour outils externes

---

## Monétisation (si applicable)

- [ ] Cosmétiques — skins gladiateur, couleurs arène, effets victoire (jamais pay-to-win)
- [ ] Compte premium — combats bonus journaliers, early access tournois spéciaux
- [ ] Discord bot — stats depuis Discord, notifs combats clan

---

## Infrastructure (nécessite migration vers VPS)

- [ ] Redis — cache sessions + classements
- [ ] WebSockets — combats live, chat temps réel
- [ ] Job queue — tournois async, workers background
- [ ] CDN — assets audio/svg (Cloudflare R2 ou BunnyCDN)
- [ ] Discord OAuth — login social
- [ ] API REST séparée + frontend découplé
