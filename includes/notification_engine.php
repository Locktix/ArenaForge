<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/quest_engine.php';
require_once __DIR__ . '/streak_engine.php';
require_once __DIR__ . '/challenge_engine.php';
require_once __DIR__ . '/boss_engine.php';
require_once __DIR__ . '/market_engine.php';

/**
 * Retourne toutes les notifications live pour une brute.
 * Catégories : urgent / action / activity / info
 * Triées par priorité décroissante (urgent en premier).
 */
function get_notifications(array $brute): array
{
    $bruteId = (int)$brute['id'];
    $userId  = (int)$brute['user_id'];
    $notifs  = [];

    // --- Level-up en attente (urgent) ---
    if ((int)$brute['pending_levelup'] === 1) {
        $notifs[] = [
            'kind'     => 'level',
            'category' => 'urgent',
            'icon'     => 'assets/svg/quests/trophy.svg',
            'title'    => 'Niveau gagné !',
            'body'     => 'Choisis ton bonus de niveau pour continuer à combattre.',
            'href'     => 'brute.php?id=' . $bruteId,
            'urgent'   => true,
        ];
    }

    // --- Défis reçus en attente (urgent) ---
    $pendingChal = pending_inbox_count($bruteId);
    if ($pendingChal > 0) {
        $notifs[] = [
            'kind'     => 'challenge',
            'category' => 'urgent',
            'icon'     => 'assets/svg/weapons/sword.svg',
            'title'    => $pendingChal . ' défi' . ($pendingChal > 1 ? 's' : '') . ' reçu' . ($pendingChal > 1 ? 's' : ''),
            'body'     => 'Un adversaire t\'a nommé. Accepte ou refuse le combat.',
            'href'     => 'challenges.php?tab=inbox',
            'urgent'   => true,
        ];
    }

    // --- Quêtes journalières prêtes à réclamer ---
    try {
        $dailyQ = get_daily_quests($bruteId);
        $dailyReady = 0;
        foreach ($dailyQ as $q) {
            if ((int)$q['claimed'] === 0 && (int)$q['progress'] >= (int)$q['target']) $dailyReady++;
        }
        if ($dailyReady > 0) {
            $notifs[] = [
                'kind'     => 'quest',
                'category' => 'action',
                'icon'     => 'assets/svg/ui/scroll.svg',
                'title'    => $dailyReady . ' quête' . ($dailyReady > 1 ? 's' : '') . ' journalière' . ($dailyReady > 1 ? 's' : '') . ' à réclamer',
                'body'     => 'XP et combats bonus t\'attendent.',
                'href'     => 'quests.php?tab=daily',
            ];
        }
    } catch (Throwable $e) { /* silent */ }

    // --- Quêtes hebdomadaires prêtes à réclamer ---
    try {
        $weeklyQ = get_weekly_quests($bruteId);
        $weeklyReady = 0;
        foreach ($weeklyQ as $q) {
            if ((int)$q['claimed'] === 0 && (int)$q['progress'] >= (int)$q['target']) $weeklyReady++;
        }
        if ($weeklyReady > 0) {
            $notifs[] = [
                'kind'     => 'quest',
                'category' => 'action',
                'icon'     => 'assets/svg/ui/scroll.svg',
                'title'    => $weeklyReady . ' quête' . ($weeklyReady > 1 ? 's' : '') . ' hebdo à réclamer',
                'body'     => 'Récompenses hebdomadaires disponibles.',
                'href'     => 'quests.php?tab=weekly',
            ];
        }
    } catch (Throwable $e) { /* silent */ }

    // --- Antre du Trône PvP ---
    try {
        $pvpBoss = get_pvp_boss();
        if ($pvpBoss && (int)$pvpBoss['brute_id'] !== $bruteId) {
            $alreadyChallenged = ($brute['boss_last_challenge_date'] ?? '') === date('Y-m-d');
            if (!$alreadyChallenged) {
                $notifs[] = [
                    'kind'     => 'boss',
                    'category' => 'action',
                    'icon'     => 'assets/svg/skills/rage.svg',
                    'title'    => $pvpBoss['name'] . ' règne sur le Trône',
                    'body'     => 'Niv. ' . (int)$pvpBoss['level'] . ' · ' . (int)$pvpBoss['defense_wins'] . ' défense(s) — Défie-le !',
                    'href'     => 'boss.php',
                ];
            }
        } elseif (!$pvpBoss) {
            $notifs[] = [
                'kind'     => 'boss',
                'category' => 'action',
                'icon'     => 'assets/svg/skills/rage.svg',
                'title'    => 'Le Trône est vacant !',
                'body'     => 'Aucun Maître en place — revendique-le maintenant.',
                'href'     => 'boss.php',
            ];
        }
    } catch (Throwable $e) { /* silent */ }

    // --- Marché du jour ---
    try {
        $offers  = list_today_market($bruteId);
        $unbought = 0;
        foreach ($offers as $o) { if ((int)$o['bought'] !== 1) $unbought++; }
        if ($unbought > 0 && $unbought === count($offers)) {
            $notifs[] = [
                'kind'     => 'market',
                'category' => 'action',
                'icon'     => 'assets/svg/quests/hammer.svg',
                'title'    => 'Marché noir — offres disponibles',
                'body'     => $unbought . ' offre' . ($unbought > 1 ? 's' : '') . ' du jour t\'attendent.',
                'href'     => 'market.php',
            ];
        }
    } catch (Throwable $e) { /* silent */ }

    // --- Combats récents (5 derniers combats d'arène) ---
    try {
        $stmt = db()->prepare('
            SELECT f.id, f.winner_id,
                   CASE WHEN f.brute1_id = ? THEN b2.name ELSE b1.name END AS opp_name,
                   CASE WHEN f.brute1_id = ? THEN b2.level ELSE b1.level END AS opp_level
            FROM fights f
            JOIN brutes b1 ON b1.id = f.brute1_id
            JOIN brutes b2 ON b2.id = f.brute2_id
            WHERE (f.brute1_id = ? OR f.brute2_id = ?) AND f.context = "arena"
            ORDER BY f.id DESC
            LIMIT 5
        ');
        $stmt->execute([$bruteId, $bruteId, $bruteId, $bruteId]);
        foreach ($stmt->fetchAll() as $rf) {
            $won = ((int)$rf['winner_id'] === $bruteId);
            $notifs[] = [
                'kind'     => 'fight',
                'category' => 'activity',
                'icon'     => $won ? 'assets/svg/ui/trophy.svg' : 'assets/svg/weapons/sword.svg',
                'title'    => $won ? 'Victoire' : 'Défaite',
                'body'     => ($won ? 'Tu as vaincu ' : 'Vaincu par ') . $rf['opp_name'] . ' (Niv. ' . (int)$rf['opp_level'] . ')',
                'href'     => 'fight.php?id=' . (int)$rf['id'],
                'win'      => $won,
            ];
        }
    } catch (Throwable $e) { /* silent */ }

    // --- Messages du clan (24 dernières heures) ---
    try {
        $stmt = db()->prepare('
            SELECT c.id AS clan_id, c.name AS clan_name,
                   COUNT(cm.id) AS msg_count
            FROM clan_members cml
            JOIN clans c ON c.id = cml.clan_id
            LEFT JOIN clan_messages cm
                ON cm.clan_id = c.id
                AND cm.brute_id != ?
                AND cm.created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            WHERE cml.brute_id = ?
            GROUP BY c.id, c.name
            LIMIT 1
        ');
        $stmt->execute([$bruteId, $bruteId]);
        if ($clanRow = $stmt->fetch()) {
            $msgCount = (int)$clanRow['msg_count'];
            $notifs[] = [
                'kind'     => 'clan',
                'category' => 'activity',
                'icon'     => 'assets/svg/ui/nav_pupils.svg',
                'title'    => '[' . $clanRow['clan_name'] . '] — Hall des Clans',
                'body'     => $msgCount > 0
                    ? $msgCount . ' nouveau' . ($msgCount > 1 ? 'x' : '') . ' message' . ($msgCount > 1 ? 's' : '') . ' de tes frères d\'armes'
                    : 'Aucun nouveau message ces 24 dernières heures',
                'href'     => 'clans.php',
            ];
        }
    } catch (Throwable $e) { /* silent */ }

    // --- Streak proche d'un palier ---
    try {
        $streak = get_user_streak($userId);
        $next   = next_streak_milestone((int)$streak['streak']);
        if ($next !== null && (int)$streak['streak'] >= $next - 1) {
            $notifs[] = [
                'kind'     => 'streak',
                'category' => 'info',
                'icon'     => 'assets/svg/quests/fire.svg',
                'title'    => 'Streak : ' . (int)$streak['streak'] . ' jours de suite',
                'body'     => 'Reviens demain pour atteindre le palier des ' . $next . ' jours.',
                'href'     => 'brute.php?id=' . $bruteId,
            ];
        }
    } catch (Throwable $e) { /* silent */ }

    // --- Animal de compagnie proche d'évoluer ---
    try {
        $stmt = db()->prepare('
            SELECT p.name, bp.combat_count
            FROM brute_pets bp
            JOIN pets p ON p.id = bp.pet_id
            WHERE bp.brute_id = ? AND bp.combat_count >= 40
              AND p.name IN ("Chien","Loup","Panthere","Ours")
            LIMIT 1
        ');
        $stmt->execute([$bruteId]);
        if ($p = $stmt->fetch()) {
            $remain = max(0, 50 - (int)$p['combat_count']);
            $notifs[] = [
                'kind'     => 'pet',
                'category' => 'info',
                'icon'     => 'assets/svg/pets/wolf.svg',
                'title'    => $p['name'] . ' va évoluer',
                'body'     => $remain > 0
                    ? 'Plus que ' . $remain . ' combat' . ($remain > 1 ? 's' : '') . ' partagés.'
                    : 'Évolution prête au prochain combat.',
                'href'     => 'brute.php?id=' . $bruteId,
            ];
        }
    } catch (Throwable $e) { /* silent */ }

    // Tri par priorité de catégorie
    $prio = ['urgent' => 0, 'action' => 1, 'activity' => 2, 'info' => 3];
    usort($notifs, fn($a, $b) =>
        ($prio[$a['category'] ?? 'info'] ?? 3) - ($prio[$b['category'] ?? 'info'] ?? 3)
    );

    return $notifs;
}
