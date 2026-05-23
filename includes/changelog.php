<?php
// Changelog déclaratif — éditable à la main, pas de BDD.
// Pour ajouter une nouvelle version : ajouter une entrée EN HAUT du tableau
// avec une version semver et une date ISO. Les entrées sont affichées dans
// l'ordre du tableau (les plus récentes en premier).

declare(strict_types=1);

const CHANGELOG_ENTRIES = [
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
