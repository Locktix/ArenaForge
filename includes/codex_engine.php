<?php
// Codex / bestiaire — suivi d'utilisation et lore progressif
//
// Trois paliers de lore :
//   - 0  uses : titre + description courte (déjà visibles)
//   - 25 uses : lore niveau 1 (origine)
//   - 100 uses : lore niveau 2 (technique)
//   - 250 uses : lore niveau 3 (légende)

declare(strict_types=1);

require_once __DIR__ . '/db.php';

const CODEX_TIERS = [25, 100, 250];

// ============================================================
// Lore — textes hardcodés par item
// ============================================================
// Clé = nom canonique (table weapons/skills/pets)

const CODEX_LORE = [
    // ----- Armes -----
    'weapon' => [
        'Poings nus' => [
            'Méthode des origines : sans armure ni lame, juste la sueur et la rage.',
            'Les écoles de Capoue enseignent encore aujourd\'hui que la maîtrise des poings précède celle de l\'épée.',
            'On raconte qu\'un esclave nommé Spartacus se libéra à mains nues — la légende est née là.',
        ],
        'Dague' => [
            'L\'arme du voleur, du traître, du gladiateur silencieux.',
            'Sa lame courte permet de viser entre les plaques d\'une armure plutôt que de la fendre.',
            'La Dague d\'Iberia, forgée par Klaudios, aurait tué trois empereurs avant d\'être perdue.',
        ],
        'Epee' => [
            'Symbole du soldat romain, équilibrée pour le bouclier et la formation.',
            'Une épée bien aiguisée fend l\'air sans bruit ; mal aiguisée, elle annonce ton arrivée.',
            'L\'épée d\'Auguste, dit-on, ne s\'émoussait jamais — la rouille la consuma à sa mort.',
        ],
        'Hache' => [
            'Importée du Nord : moins élégante qu\'une épée mais deux fois plus brutale.',
            'Le tranchant ne fait pas tout : c\'est le poids derrière qui ouvre les casques.',
            'La Hache d\'Olaf-le-Borgne aurait abattu un ours adulte d\'un seul coup.',
        ],
        'Masse' => [
            'Quand l\'armure est trop épaisse pour la lame, on revient à l\'os qu\'on broie.',
            'Une masse bien équilibrée rebondit après l\'impact, prête pour le coup suivant.',
            'La Masse du Père-Foudre : forgée d\'un éclat de météore, elle frappa les Géants en rêve.',
        ],
        'Lance' => [
            'Allonge maximale : l\'ennemi meurt avant d\'avoir touché le porteur.',
            'Une lance brisée vaut deux dagues — l\'arme se reconfigure en plein combat.',
            'La Lance Solaire d\'Helios n\'a jamais raté sa cible, dit la légende.',
        ],
        'Arc' => [
            'L\'arme du chasseur transformée en outil d\'arène.',
            'Un archer souffle deux fois entre chaque flèche : une pour viser, une pour tuer.',
            'L\'Arc d\'Artémis tirait des flèches qui retournaient à leur lanceur.',
        ],
        'Bouclier' => [
            'L\'arme défensive par excellence : qui ne meurt pas peut frapper deux fois.',
            'Le bouclier rond favorise l\'agilité, le rectangulaire la formation.',
            'Le Scutum d\'Achille reflétait les armes ennemies qui s\'y brisaient en silence.',
        ],
    ],

    // ----- Compétences -----
    'skill' => [
        'Force brute' => [
            'Pas de technique, juste un corps qui hurle plus fort que la douleur.',
            'Les anciens disaient : "La rage qui coule dans tes muscles vaut dix années d\'entraînement."',
            'Hercule, dit la légende, ne maîtrisait que cette compétence — elle suffisait.',
        ],
        'Esquive' => [
            'L\'art de ne pas être là quand le coup arrive.',
            'Un bon esquiveur lit le combat trois temps à l\'avance.',
            'On dit que la Danseuse Phrygia esquivait les flèches en plein vol, les yeux fermés.',
        ],
        'Contre-attaque' => [
            'Transformer l\'attaque adverse en opportunité.',
            'L\'instant exact entre l\'esquive et la riposte est ce qui sépare l\'élève du maître.',
            'Le Tigre Carthaginois ripostait toujours en moins d\'un battement de cœur.',
        ],
        'Regeneration' => [
            'L\'art de respirer même quand on saigne.',
            'Les guérisseurs ioniques transmettaient cette technique de père en fils.',
            'Asclépios lui-même aurait inspiré ce souffle qui rappelle la chair à la vie.',
        ],
        'Coup critique' => [
            'Trouver le défaut dans l\'armure, l\'angle qui tue.',
            'Un coup critique parfait ne demande pas plus de force, juste plus de calme.',
            'Le légendaire Tueur-de-Rois ne portait que des coups critiques — la rumeur dit qu\'il visait l\'âme.',
        ],
        'Armure' => [
            'Encaisser comme une enclume, rendre comme un marteau.',
            'L\'armure n\'est pas qu\'un objet : c\'est une posture, une façon de respirer.',
            'L\'Inébranlable de Sparte aurait reçu mille coups sans céder un centimètre.',
        ],
        'Rage' => [
            'La douleur devient carburant. La peur devient rire.',
            'Les berserkers du Nord buvaient des bouillons d\'amanite avant le combat.',
            'Achille blessé devenait deux fois plus mortel — c\'est cette rage qu\'on invoque.',
        ],
        'Vol de vie' => [
            'Drainer la force vitale de l\'adversaire à chaque coup porté.',
            'Une technique sombre, importée d\'Égypte par les prêtres de Set.',
            'Le Vampire de Pannonie vivait, dit-on, depuis trois siècles grâce à cet art.',
        ],
        'Frappe titanesque' => [
            'Un seul coup, mais qui résume mille entraînements.',
            'Cette technique consume une vie de préparation pour un instant de gloire.',
            'Les Titans frappaient ainsi avant que Zeus ne les enchaîne.',
        ],
        'Cri de guerre' => [
            'Hurler son refus de mourir — et le corps obéit.',
            'Les centurions blessés mortellement utilisaient ce cri pour finir leur dernière charge.',
            'Le Cri du Lion de Numidie aurait fait fuir une légion entière.',
        ],
        'Seconde vie' => [
            'L\'âme refuse de partir. Elle tient un battement de cœur de trop.',
            'Cette technique se transmet mourant à mourant — un secret qu\'on ne crie pas.',
            'Le Phénix d\'Alexandrie serait revenu sept fois de la mort sur le sable de l\'arène.',
        ],
    ],

    // ----- Pets -----
    'pet' => [
        'Chien' => [
            'Compagnon des bergers, désormais des gladiateurs.',
            'Un chien d\'arène apprend à mordre les chevilles — le point faible des grandes brutes.',
            'Cerbère, dit-on, n\'était que le plus vieux des chiens d\'arène.',
        ],
        'Loup' => [
            'Le loup ne combat pas pour la gloire : il combat pour la meute.',
            'Un loup apprivoisé reste un loup. Il jugera son maître chaque jour.',
            'Fenrir-le-Premier engloutissait des dieux dans les anciens chants nordiques.',
        ],
        'Panthere' => [
            'Vitesse pure. La panthère décide quand tu meurs.',
            'Les Égyptiens vénéraient la panthère noire comme une déesse de la nuit.',
            'La Panthère de Bastet, racontent-ils, traversait les murs sans bruit.',
        ],
        'Ours' => [
            'Quand l\'ours charge, on ne court pas : on prie.',
            'Un ours dressé pèse autant que trois hommes et frappe comme dix.',
            'Le Roi-des-Forêts de Thrace aurait combattu côte à côte avec Spartacus.',
        ],
        'Molosse' => [
            'Chien aguerri. Sa morsure ne cède plus.',
            'Quand un molosse a vu cinquante combats, il ne lâche plus jamais une cheville.',
            'Le Molosse d\'Hadès, dit-on, dort encore aux portes des Enfers.',
        ],
        'Loup alpha' => [
            'Meneur de meute par droit de combat.',
            'Un loup alpha n\'attaque jamais en premier — sauf quand son maître est au sol.',
            'Skoll-l\'Affamé poursuivait le soleil dans les chants des skaldes.',
        ],
        'Sphinx' => [
            'La panthère a vu cinquante morts. Elle prédit la cinquante-et-unième.',
            'Le Sphinx ne tue plus pour manger : il tue pour la beauté du geste.',
            'Le Sphinx de Gizeh aurait été un compagnon de gladiateur, transformé en pierre par l\'orgueil.',
        ],
        'Ours-roi' => [
            'L\'ours qui a survécu cinquante combats est devenu roi de l\'arène.',
            'Un ours-roi reconnaît son maître à l\'odeur même après dix ans.',
            'Béowulf — le héros — aurait été un ours-roi déguisé en homme.',
        ],
    ],
];

// ============================================================
// Tracking
// ============================================================

/**
 * Incrémente l'usage des items utilisés ou possédés par la brute lors
 * d'un combat. Appelé après chaque résolution.
 *
 * - Armes : compte chaque arme effectivement utilisée (lue dans le log)
 * - Skills : +1 pour chaque skill possédé
 * - Pets   : +1 pour chaque pet possédé
 */
function track_codex_usage(int $bruteId, array $log): void
{
    $pdo = db();

    // ----- Armes utilisées (lecture du log) -----
    $weaponNames = [];
    $stmt = $pdo->prepare('SELECT name FROM brutes WHERE id = ? LIMIT 1');
    $stmt->execute([$bruteId]);
    $bruteName = (string)$stmt->fetchColumn();

    foreach ($log as $ev) {
        if (($ev['event'] ?? '') !== 'hit') continue;
        if (($ev['attacker'] ?? '') !== $bruteName) continue;
        $attSlot = (string)($ev['attacker_slot'] ?? '');
        if (substr($attSlot, 1) !== '0') continue; // ignore pet attacks
        $w = (string)($ev['weapon'] ?? '');
        if ($w !== '') $weaponNames[$w] = true;
    }
    if (!empty($weaponNames)) {
        $stmt = $pdo->prepare('SELECT id, name FROM weapons WHERE name IN (' . implode(',', array_fill(0, count($weaponNames), '?')) . ')');
        $stmt->execute(array_keys($weaponNames));
        foreach ($stmt->fetchAll() as $w) {
            bump_codex($bruteId, 'weapon', (int)$w['id']);
        }
    }

    // ----- Skills possédés -----
    $stmt = $pdo->prepare('SELECT skill_id FROM brute_skills WHERE brute_id = ?');
    $stmt->execute([$bruteId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $sid) {
        bump_codex($bruteId, 'skill', (int)$sid);
    }

    // ----- Pets possédés -----
    $stmt = $pdo->prepare('SELECT pet_id FROM brute_pets WHERE brute_id = ?');
    $stmt->execute([$bruteId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $pid) {
        bump_codex($bruteId, 'pet', (int)$pid);
    }
}

function bump_codex(int $bruteId, string $type, int $itemId): void
{
    $pdo = db();
    $pdo->prepare('
        INSERT INTO brute_codex_usage (brute_id, item_type, item_id, use_count, last_used)
        VALUES (?, ?, ?, 1, NOW())
        ON DUPLICATE KEY UPDATE use_count = use_count + 1, last_used = NOW()
    ')->execute([$bruteId, $type, $itemId]);
}

// ============================================================
// Lecture pour la page codex
// ============================================================

function get_codex_for_brute(int $bruteId): array
{
    $pdo = db();

    $usage = ['weapon' => [], 'skill' => [], 'pet' => []];
    $stmt = $pdo->prepare('SELECT item_type, item_id, use_count FROM brute_codex_usage WHERE brute_id = ?');
    $stmt->execute([$bruteId]);
    foreach ($stmt->fetchAll() as $row) {
        $usage[$row['item_type']][(int)$row['item_id']] = (int)$row['use_count'];
    }

    $weapons = $pdo->query('SELECT id, name, icon_path FROM weapons ORDER BY id')->fetchAll();
    $skills  = $pdo->query('SELECT id, name, description, icon_path FROM skills ORDER BY id')->fetchAll();
    $pets    = $pdo->query('SELECT id, name, description, icon_path FROM pets ORDER BY id')->fetchAll();

    return [
        'weapons' => array_map(fn($w) => array_merge($w, [
            'count'     => $usage['weapon'][(int)$w['id']] ?? 0,
            'lore'      => CODEX_LORE['weapon'][$w['name']] ?? [],
            'tier_cur'  => codex_tier_for($usage['weapon'][(int)$w['id']] ?? 0),
        ]), $weapons),
        'skills' => array_map(fn($s) => array_merge($s, [
            'count'     => $usage['skill'][(int)$s['id']] ?? 0,
            'lore'      => CODEX_LORE['skill'][$s['name']] ?? [],
            'tier_cur'  => codex_tier_for($usage['skill'][(int)$s['id']] ?? 0),
        ]), $skills),
        'pets' => array_map(fn($p) => array_merge($p, [
            'count'     => $usage['pet'][(int)$p['id']] ?? 0,
            'lore'      => CODEX_LORE['pet'][$p['name']] ?? [],
            'tier_cur'  => codex_tier_for($usage['pet'][(int)$p['id']] ?? 0),
        ]), $pets),
    ];
}

function codex_tier_for(int $count): int
{
    $tier = 0;
    foreach (CODEX_TIERS as $i => $threshold) {
        if ($count >= $threshold) $tier = $i + 1;
    }
    return $tier;
}
