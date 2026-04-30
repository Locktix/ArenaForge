<?php
// Centre de notifications — agrège tous les actionnables du joueur
//
// Retourne un tableau de notifications, chacune sous la forme :
//   ['icon' => path, 'title' => '...', 'body' => '...', 'href' => '...', 'kind' => 'level|quest|...']
// Les notifications urgentes (level-up, défis) sortent en premier.

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/quest_engine.php';
require_once __DIR__ . '/streak_engine.php';
require_once __DIR__ . '/challenge_engine.php';
require_once __DIR__ . '/boss_engine.php';
require_once __DIR__ . '/market_engine.php';

function get_notifications(array $brute): array
{
    $bruteId = (int)$brute['id'];
    $userId  = (int)$brute['user_id'];
    $notifs  = [];

    // --- Level-up ---
    if ((int)$brute['pending_levelup'] === 1) {
        $notifs[] = [
            'kind'  => 'level',
            'icon'  => 'assets/svg/quests/trophy.svg',
            'title' => 'Niveau gagné !',
            'body'  => 'Choisis ton bonus de niveau.',
            'href'  => 'brute.php?id=' . $bruteId,
            'urgent'=> true,
        ];
    }

    // --- Quêtes journalières prêtes ---
    $dailyQ = get_daily_quests($bruteId);
    $dailyReady = 0;
    foreach ($dailyQ as $q) {
        if ((int)$q['claimed'] === 0 && (int)$q['progress'] >= (int)$q['target']) {
            $dailyReady++;
        }
    }
    if ($dailyReady > 0) {
        $notifs[] = [
            'kind'  => 'quest',
            'icon'  => 'assets/svg/ui/scroll.svg',
            'title' => $dailyReady . ' quête(s) journalière(s) à réclamer',
            'body'  => 'XP et combats bonus t\'attendent.',
            'href'  => 'quests.php?tab=daily',
        ];
    }

    // --- Quêtes hebdo prêtes ---
    $weeklyQ = get_weekly_quests($bruteId);
    $weeklyReady = 0;
    foreach ($weeklyQ as $q) {
        if ((int)$q['claimed'] === 0 && (int)$q['progress'] >= (int)$q['target']) {
            $weeklyReady++;
        }
    }
    if ($weeklyReady > 0) {
        $notifs[] = [
            'kind'  => 'quest',
            'icon'  => 'assets/svg/ui/scroll.svg',
            'title' => $weeklyReady . ' quête(s) hebdo à réclamer',
            'body'  => 'Récompenses plus généreuses que les journalières.',
            'href'  => 'quests.php?tab=weekly',
        ];
    }

    // --- Défis reçus en attente ---
    $pendingChal = pending_inbox_count($bruteId);
    if ($pendingChal > 0) {
        $notifs[] = [
            'kind'  => 'challenge',
            'icon'  => 'assets/svg/weapons/sword.svg',
            'title' => $pendingChal . ' défi(s) reçu(s)',
            'body'  => 'Un adversaire t\'a nommé. Accepter ou refuser ?',
            'href'  => 'challenges.php?tab=inbox',
            'urgent'=> true,
        ];
    }

    // --- Boss du jour pas tenté ---
    $boss = get_today_boss();
    if ($boss) {
        $attempt = get_boss_attempt((int)$boss['id'], $bruteId);
        if (!$attempt) {
            $notifs[] = [
                'kind'  => 'boss',
                'icon'  => 'assets/svg/skills/rage.svg',
                'title' => 'Boss du jour : ' . (string)$boss['name'],
                'body'  => 'Niveau ' . (int)$boss['level'] . ' — 1 essai par jour.',
                'href'  => 'boss.php',
            ];
        }
    }

    // --- Marché du jour ---
    $offers = list_today_market($bruteId);
    $unbought = 0;
    foreach ($offers as $o) {
        if ((int)$o['bought'] !== 1) $unbought++;
    }
    if ($unbought === count($offers)) {
        $notifs[] = [
            'kind'  => 'market',
            'icon'  => 'assets/svg/quests/hammer.svg',
            'title' => 'Marché noir',
            'body'  => $unbought . ' offre(s) du jour disponibles.',
            'href'  => 'market.php',
        ];
    }

    // --- Streak ---
    $streak = get_user_streak($userId);
    $next   = next_streak_milestone((int)$streak['streak']);
    if ($next !== null && (int)$streak['streak'] >= $next - 1) {
        $notifs[] = [
            'kind'  => 'streak',
            'icon'  => 'assets/svg/quests/fire.svg',
            'title' => 'Streak en feu : ' . (int)$streak['streak'] . ' jours',
            'body'  => 'Reviens demain pour franchir le palier de ' . $next . ' jours.',
            'href'  => 'brute.php?id=' . $bruteId,
        ];
    }

    // --- Pet proche d'évoluer ---
    $stmt = db()->prepare('
        SELECT p.name, bp.combat_count
        FROM brute_pets bp
        JOIN pets p ON p.id = bp.pet_id
        WHERE bp.brute_id = ?
          AND bp.combat_count >= 40
          AND p.name IN ("Chien", "Loup", "Panthere", "Ours")
        LIMIT 1
    ');
    $stmt->execute([$bruteId]);
    if ($p = $stmt->fetch()) {
        $remain = max(0, 50 - (int)$p['combat_count']);
        $notifs[] = [
            'kind'  => 'pet',
            'icon'  => 'assets/svg/pets/wolf.svg',
            'title' => $p['name'] . ' va évoluer',
            'body'  => $remain > 0 ? ('Plus que ' . $remain . ' combats partagés.') : ('Évolution prête au prochain combat.'),
            'href'  => 'brute.php?id=' . $bruteId,
        ];
    }

    // Tri : urgents d'abord
    usort($notifs, function ($a, $b) {
        return (int)(!empty($b['urgent'])) - (int)(!empty($a['urgent']));
    });

    return $notifs;
}
