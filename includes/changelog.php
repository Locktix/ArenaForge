<?php
// Changelog déclaratif — éditable à la main, pas de BDD.
// Pour ajouter une nouvelle version : ajouter une entrée EN HAUT du tableau
// avec une version semver et une date ISO. Les entrées sont affichées dans
// l'ordre du tableau (les plus récentes en premier).

declare(strict_types=1);

const CHANGELOG_ENTRIES = [
    [
        'version' => '1.6.0',
        'date'    => '2026-05-28',
        'title'   => 'Le Nexus des Anciens',
        'items'   => [
            ['icon' => '⚡', 'text' => '<strong>Nexus des Anciens</strong> : nouveau donjon endgame réservé aux niveaux 20+. Six salles aux confins du monde, gardées par des Titans primordiaux d\'une puissance inégalée. Entrée : 250 or.'],
            ['icon' => '🗿', 'text' => '<strong>Titan Primordial</strong> : nouveau sprite de monstre exclusif — colosse de pierre armé de gantelets dorés, couronne runique et yeux cyan lumineux. Le gardien le plus imposant du jeu.'],
            ['icon' => '💀', 'text' => '<strong>Difficulté escaladée</strong> : les Titans atteignent 350 % de PV avec 26 en force et 9 en agilité en salle finale. La dernière salle dispose de la compétence <em>Revive</em> — le Nexus Vivant refuse de mourir.'],
            ['icon' => '💰', 'text' => '<strong>Butin endgame</strong> : jusqu\'à 368 or, 101 XP et 124 fragments pour un clear complet. Le meilleur butin par donjon du jeu.'],
            ['icon' => '🏰', 'text' => '<strong>Coûts rééquilibrés</strong> : Forteresse Maudite 25 → 50 or, Abîsse Éternel 50 → 100 or. La progression entre donjons est désormais plus marquée.'],
            ['icon' => '🐍', 'text' => '<strong>Snake HTMX fix</strong> : le mini-jeu Snake se lance désormais correctement sans rechargement manuel, y compris sur mobile avec un D-pad tactile. Les pommes n\'apparaissent plus sur les bords de la carte.'],
        ],
    ],
    [
        'version' => '1.5.0',
        'date'    => '2026-05-26',
        'title'   => 'Les Donjons',
        'items'   => [
            ['icon' => '🏰', 'text' => '<strong>Système de Donjons</strong> : trois donjons déblocables selon ton niveau — la <em>Crypte des Damnés</em> (gratuit, niv. 1), la <em>Forteresse Maudite</em> (1 combat bonus, niv. 5) et l\'<em>Abîsse Éternel</em> (2 combats bonus, niv. 10).'],
            ['icon' => '💀', 'text' => '<strong>HP persistants entre les salles</strong> : tes points de vie survivent d\'une salle à l\'autre. Chaque gardien abattu t\'affaiblit — mais te récompense en XP et en or.'],
            ['icon' => '🦴', 'text' => '<strong>Sprites monstres uniques</strong> : chaque donjon a son gardien visuel — Squelette à lueur bleue (Crypte), Chevalier Noir armé de fer et d\'or (Forteresse), Démon aux yeux violets et halo pulsant (Abîsse).'],
            ['icon' => '⚔', 'text' => '<strong>Bosses progressifs</strong> : force, agilité et compétences des gardiens escaladent salle après salle. Les dernières salles ont des compétences rares (rage, lifesteal, revive…).'],
            ['icon' => '📅', 'text' => '<strong>Une tentative par donjon par jour</strong> : victoire, défaite ou abandon — le créneau est consommé. Reviens demain pour retenter.'],
            ['icon' => '🏆', 'text' => '<strong>Leaderboard des explorateurs</strong> : classement global des runs les plus profondes, toutes brutes confondues — visible en bas de la page Donjons.'],
        ],
    ],
    [
        'version' => '1.4.0',
        'date'    => '2026-05-23',
        'title'   => 'La Gloire Éternelle',
        'items'   => [
            ['icon' => '🏆', 'text' => '<strong>Titres de Gloire</strong> : 9 titres prestigieux à débloquer par exploits (série de victoires, tournois, saisons, sacrifices…). Un seul porté à la fois — avec un <strong>bonus de stats actif</strong> pendant tous tes combats.'],
            ['icon' => '⚔', 'text' => '<strong>Raretés des armes</strong> : chaque arme arbore désormais une rareté — Commun (gris), Rare (bleu), Épique (violet). Les armes rares et épiques ont un rendu visuel spécial dans l\'arsenal, le Codex et la Forge.'],
            ['icon' => '🛡', 'text' => '<strong>Bouclier en acier</strong> : nouvelle arme épique de niveau 10 — aucun dégât infligé, mais +4 de défense passive cumulable avec n\'importe quelle arme.'],
            ['icon' => '🗡', 'text' => '<strong>Niveau minimum par arme</strong> : les armes sont désormais débloquées progressivement au level-up selon ton niveau (Dague/Arc dès le niveau 1, Lance 3, Épée/Bouclier 5, Masse 7, Hache/Bouclier en acier 10).'],
            ['icon' => '💥', 'text' => '<strong>Hache rééquilibrée</strong> : dégâts portés à 8–15 (contre 7–14). Plus puissante, plus dangereuse.'],
            ['icon' => '🔔', 'text' => '<strong>Centre de notifications</strong> : la cloche en haut à droite consigne désormais tous tes événements importants — titres débloqués, quêtes terminées, résultats de tournoi, sacrifices.'],
        ],
    ],
    [
        'version' => '1.3.0',
        'date'    => '2026-05-23',
        'title'   => 'Le Pacte du Sang',
        'items'   => [
            ['icon' => '☠', 'text' => 'Nouvel <strong>Autel des Sacrifices</strong> : échange tes ressources contre des bonus aléatoires (ou rien du tout). 5 rituels au choix, dont un Grand Sacrifice hebdomadaire qui peut tout changer.'],
            ['icon' => '🔥', 'text' => 'Leaderboard <strong>Les Plus Téméraires</strong> — classe-toi parmi les sacrificateurs les plus intrépides.'],
            ['icon' => '🛡', 'text' => 'Le <strong>Bouclier</strong> devient un vrai bouclier : -2 dégâts reçus, et son efficacité scale avec ses améliorations dans la Forge.'],
            ['icon' => '🎖', 'text' => 'Nouvelle catégorie <strong>Mini-Jeux</strong> dans les trophées : 4 trophées Snake (5, 10, 20, 50 pommes).'],
            ['icon' => '✅', 'text' => 'Fix : les trophées niveau 5 et 10 sont accordés rétroactivement si tu les avais déjà atteints.'],
        ],
    ],
    [
        'version' => '1.2.0',
        'date'    => '2026-05-22',
        'title'   => 'L\'Antre du Trône',
        'items'   => [
            ['icon' => '👑', 'text' => '<strong>Trône PvP</strong> : défie le maître régnant pour devenir le boss. Gagne 10 XP/jour tant que tu règnes, défends ton trône contre les challengers.'],
            ['icon' => '🎮', 'text' => 'Nouveau mini-jeu <strong>Snake</strong> avec leaderboard global et récompenses XP/fragments/combats bonus.'],
            ['icon' => '⚔', 'text' => 'Matchmaking amélioré : tu affrontes désormais des adversaires choisis dans le pool des 3 rangs au-dessus et 3 en-dessous du tien.'],
            ['icon' => '🎨', 'text' => 'Refonte visuelle des quêtes (parchemin sombre) et de la page de profil (cartoon coloré).'],
            ['icon' => '🤝', 'text' => 'Corrections sur les clans : navigation entre clans réparée, boutons "Rejoindre" / "Entrer" corrects selon ton appartenance.'],
        ],
    ],
];

function changelog_latest_version(): string
{
    return CHANGELOG_ENTRIES[0]['version'] ?? '0.0.0';
}

/**
 * Retourne toutes les entrées non encore vues par l'utilisateur,
 * dans l'ordre (les plus récentes en premier).
 */
function changelog_unseen_for(?string $lastSeen): array
{
    if (empty($lastSeen)) {
        // Premier login depuis l'ajout du changelog → on n'affiche que la version la plus récente
        // pour ne pas noyer l'utilisateur dans tout l'historique.
        return [CHANGELOG_ENTRIES[0]];
    }
    $unseen = [];
    foreach (CHANGELOG_ENTRIES as $entry) {
        if (version_compare($entry['version'], $lastSeen, '>')) {
            $unseen[] = $entry;
        }
    }
    return $unseen;
}
