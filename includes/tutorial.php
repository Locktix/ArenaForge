<?php
// Tutoriel guidé — définitions des étapes + helpers
//
// Chaque étape décrit :
//  - code           : identifiant unique
//  - page           : fichier PHP concerné (dashboard.php, brute.php, …) ou '*' pour flexible
//  - target         : selector CSS de l'élément à mettre en avant (null = modale centrée)
//  - title          : titre de la bulle
//  - text           : contenu (peut contenir une ligne par phrase)
//  - position       : 'top' | 'bottom' | 'left' | 'right' | 'center' (placement de la bulle)
//  - next_label     : libellé du bouton "Suivant" (défaut "Suivant")
//  - action_hint    : si défini, la bulle demande une action utilisateur (ex: "Clique le bouton") et ne montre pas le bouton Suivant
//  - next_url       : URL à rejoindre après le Suivant (si vide, reste sur la page)

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function tutorial_steps(): array
{
    return [
        [
            'code'       => 'welcome',
            'page'       => '*',
            'target'     => null,
            'title'      => 'Bienvenue dans l\'arène',
            'text'       => "Tu es sur le point de forger ton premier gladiateur et d'entrer dans l'arène. Je vais te montrer les bases en quelques étapes. Tu peux passer ce tutoriel à tout moment.",
            'position'   => 'center',
            'next_label' => "C'est parti",
            'next_url'   => 'dashboard.php',
        ],
        [
            'code'       => 'create_brute',
            'page'       => 'dashboard.php',
            'target'     => 'input[name="name"]',
            'title'      => 'Crée ton gladiateur',
            'text'       => "Choisis un nom unique (3 à 20 caractères). Son apparence, ses stats de départ et son arme de base sont générées à partir de ce nom : c'est déterministe.",
            'position'   => 'bottom',
            'action_hint'=> 'Remplis le formulaire et crée ton gladiateur pour continuer.',
        ],
        [
            'code'       => 'brute_portrait',
            'page'       => 'brute.php',
            'target'     => '.brute-portrait',
            'title'      => 'Ton gladiateur',
            'text'       => "Voici ton portrait. Il est unique et dérivé du nom que tu as choisi. Le même nom donnera toujours la même apparence.",
            'position'   => 'right',
        ],
        [
            'code'       => 'brute_stats',
            'page'       => 'brute.php',
            'target'     => '.stats',
            'title'      => 'Les trois stats',
            'text'       => "Force augmente tes dégâts. Agilité améliore l'initiative et l'esquive. Endurance représente ton HP max indirectement. À chaque niveau tu choisis quoi améliorer.",
            'position'   => 'top',
        ],
        [
            'code'       => 'brute_bars',
            'page'       => 'brute.php',
            'target'     => '.bars',
            'title'      => 'PV et XP',
            'text'       => "Barre rouge : tes points de vie. Barre dorée : ton XP jusqu'au prochain niveau. Tu gagnes 3 XP en victoire, 1 en défaite, + bonus quêtes/tournois/trophées.",
            'position'   => 'top',
        ],
        [
            'code'       => 'fights_today',
            'page'       => 'brute.php',
            'target'     => '.fights-left',
            'title'      => 'Combats du jour',
            'text'       => "Tu as 6 combats d'arène par jour, remis à zéro à minuit. Quêtes journalières et tournois peuvent t'en donner en bonus, consommés automatiquement une fois les 6 épuisés.",
            'position'   => 'bottom',
        ],
        [
            'code'       => 'brute_inventory',
            'page'       => 'brute.php',
            'target'     => '.icon-grid',
            'title'      => 'Armes et compétences',
            'text'       => "Tes possessions actuelles. À chaque niveau gagné, tu choisis parmi 3 bonus aléatoires : +stat, nouvelle arme, nouvelle compétence ou compagnon.",
            'position'   => 'top',
        ],
        [
            'code'       => 'launch_fight',
            'page'       => 'brute.php',
            'target'     => '#fight-form .btn',
            'title'      => 'Ton premier combat',
            'text'       => "Lance-toi ! Le moteur de combat résout tout côté serveur (attaques, esquives, crits, contre-attaques). Tu regardes ensuite le replay animé.",
            'position'   => 'bottom',
            'action_hint'=> 'Clique sur « Lancer un combat » pour déclencher le duel.',
        ],
        [
            'code'       => 'arena_replay',
            'page'       => 'fight.php',
            'target'     => '.arena',
            'title'      => 'L\'arène',
            'text'       => "Ton gladiateur à gauche, l'adversaire à droite. Chaque hit, crit, esquive et KO déclenche une animation et un son. Les pets (si présents) combattent à tes côtés.",
            'position'   => 'bottom',
        ],
        [
            'code'       => 'combat_log',
            'page'       => 'fight.php',
            'target'     => '.combat-log',
            'title'      => 'Journal de combat',
            'text'       => "Chaque action est décrite tour par tour. Les critiques sont en or, les esquives en bleu, les soins en vert. Le bouton « Rejouer » relance l'animation à tout moment.",
            'position'   => 'top',
        ],
        [
            'code'       => 'quests',
            'page'       => 'quests.php',
            'target'     => '.quest-list',
            'title'      => 'Quêtes journalières',
            'text'       => "Trois défis aléatoires par jour. Ils progressent automatiquement au fil des combats. Une fois terminés, clique « Réclamer » pour empocher l'XP bonus (et parfois un combat bonus).",
            'position'   => 'top',
            'next_url'   => 'forge.php',
        ],
        [
            'code'       => 'forge_intro',
            'page'       => 'forge.php',
            'target'     => '.fragment-count',
            'title'      => 'La forge',
            'text'       => "Les fragments sont gagnés à chaque combat (3 en victoire, 1 en défaite). Utilise-les pour améliorer tes armes (+10 % dégâts par niveau, max 5) ou acheter des armures (PV bonus + réduction de dégâts).",
            'position'   => 'bottom',
            'next_url'   => 'achievements.php',
        ],
        [
            'code'       => 'achievements',
            'page'       => 'achievements.php',
            'target'     => '.achievement-summary',
            'title'      => 'Trophées',
            'text'       => "Objectifs permanents qui couronnent tes faits d'armes. Chaque trophée débloqué donne de l'XP bonus immédiatement. Idéal pour les objectifs à long terme.",
            'position'   => 'bottom',
            'next_url'   => 'ranking.php',
        ],
        [
            'code'       => 'ranking',
            'page'       => 'ranking.php',
            'target'     => '.ranking',
            'title'      => 'Classement & MMR',
            'text'       => "Chaque victoire te fait monter en MMR, chaque défaite te fait chuter (selon l'écart avec l'adversaire). Les paliers Bronze → Légende reflètent ton niveau compétitif.",
            'position'   => 'top',
            'next_url'   => 'tournament.php',
        ],
        [
            'code'       => 'tournament',
            'page'       => 'tournament.php',
            'target'     => '.tournament-header',
            'title'      => 'Tournoi quotidien',
            'text'       => "Un bracket à élimination directe de 8 gladiateurs, renouvelé chaque jour. Champion = 20 XP + 3 combats bonus. Les combats de tournoi ne consomment pas tes 6 combats journaliers.",
            'position'   => 'bottom',
            'next_url'   => 'clans.php',
        ],
        [
            'code'       => 'clans',
            'page'       => 'clans.php',
            'target'     => '.clan-create-form',
            'title'      => 'Clans',
            'text'       => "Crée ou rejoins un clan pour grimper au leaderboard en équipe. Les clans sont classés par MMR cumulé de leurs membres. Créer un clan coûte 50 XP.",
            'position'   => 'top',
        ],
        [
            'code'       => 'end',
            'page'       => '*',
            'target'     => null,
            'title'      => "Tu es prêt",
            'text'       => "Tu connais l'essentiel. Combats, forge, progresse, domine l'arène. Tu peux relancer ce tutoriel à tout moment depuis la navigation.",
            'position'   => 'center',
            'next_label' => "À l'arène !",
        ],
    ];
}

function tutorial_step_count(): int
{
    return count(tutorial_steps());
}

function tutorial_state_for_user(int $userId): array
{
    $stmt = db()->prepare('SELECT tutorial_step, tutorial_skipped FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return [
        'step'    => (int)($row['tutorial_step'] ?? 0),
        'skipped' => (int)($row['tutorial_skipped'] ?? 0) === 1,
    ];
}

/**
 * Retourne true si le step doit être sauté automatiquement pour cet utilisateur
 * (ex: l'étape "crée ton gladiateur" n'a pas de sens si l'utilisateur en a déjà un).
 */
function tutorial_should_auto_skip(array $step, int $userId): bool
{
    if ($step['code'] === 'create_brute') {
        $stmt = db()->prepare('SELECT 1 FROM brutes WHERE user_id = ? LIMIT 1');
        $stmt->execute([$userId]);
        return (bool)$stmt->fetchColumn();
    }
    return false;
}

/**
 * Récupère le step pour la page courante, ou null si tutoriel terminé/skippé
 * ou si le step courant n'est pas sur cette page. Auto-avance les steps qui
 * ne sont plus pertinents pour l'utilisateur (ex: création de gladiateur déjà faite).
 */
function tutorial_step_for_page(int $userId, string $currentPage): ?array
{
    $state = tutorial_state_for_user($userId);
    if ($state['skipped']) return null;

    $steps = tutorial_steps();
    $idx   = $state['step'];

    // Auto-skip des étapes obsolètes (max 5 passes pour éviter les boucles)
    $passes = 0;
    while ($idx < count($steps) && $passes < 5) {
        if (!tutorial_should_auto_skip($steps[$idx], $userId)) break;
        $idx = tutorial_advance($userId);
        $passes++;
    }

    if ($idx >= count($steps)) return null;

    $step = $steps[$idx];
    if ($step['page'] !== '*' && $step['page'] !== $currentPage) {
        return null;
    }

    return [
        'index'     => $idx,
        'total'     => count($steps),
        'code'      => $step['code'],
        'target'    => $step['target'],
        'title'     => $step['title'],
        'text'      => $step['text'],
        'position'  => $step['position'],
        'next_label'=> $step['next_label']  ?? 'Suivant',
        'action_hint'=> $step['action_hint']?? null,
        'next_url'  => $step['next_url']    ?? null,
        'is_last'   => $idx === count($steps) - 1,
    ];
}

function tutorial_advance(int $userId): int
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT tutorial_step FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $cur = (int)$stmt->fetchColumn();

    $total = tutorial_step_count();
    $next  = min($total, $cur + 1);

    $pdo->prepare('UPDATE users SET tutorial_step = ? WHERE id = ?')
        ->execute([$next, $userId]);
    return $next;
}

function tutorial_skip(int $userId): void
{
    db()->prepare('UPDATE users SET tutorial_skipped = 1 WHERE id = ?')
        ->execute([$userId]);
}

function tutorial_restart(int $userId): void
{
    db()->prepare('UPDATE users SET tutorial_step = 0, tutorial_skipped = 0 WHERE id = ?')
        ->execute([$userId]);
}
