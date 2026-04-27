<?php
// Streak journalier de connexion
//
// À chaque visite, on met à jour `users.last_login_date` et on ajuste
// `users.streak_days`. Aux paliers 3 / 7 / 14 / 30 jours consécutifs, on
// crédite la brute du joueur (or + combats bonus). Le palier d'un jour
// donné est attribué une seule fois grâce à `users.streak_claim_date`.
//
// Lazy : appelé depuis _nav.php, donc s'exécute au plus une fois par page.

declare(strict_types=1);

require_once __DIR__ . '/db.php';

const STREAK_REWARDS = [
    3  => ['gold' => 20,  'bonus_fights' => 0, 'label' => '3 jours consécutifs'],
    7  => ['gold' => 50,  'bonus_fights' => 1, 'label' => '7 jours consécutifs'],
    14 => ['gold' => 100, 'bonus_fights' => 2, 'label' => '14 jours consécutifs'],
    30 => ['gold' => 250, 'bonus_fights' => 5, 'label' => '30 jours consécutifs'],
];

/**
 * Met à jour le streak du user et applique éventuellement une récompense.
 * Retourne ['streak' => N, 'reward' => null|['gold'=>..., 'bonus_fights'=>..., 'label'=>...]].
 */
function tick_login_streak(int $userId): array
{
    $pdo = db();
    $today = date('Y-m-d');

    $stmt = $pdo->prepare('SELECT streak_days, last_login_date, streak_claim_date FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!$row) {
        return ['streak' => 0, 'reward' => null];
    }

    $lastLogin   = $row['last_login_date'];
    $lastClaim   = $row['streak_claim_date'];
    $current     = (int)$row['streak_days'];

    if ($lastLogin === $today) {
        // Déjà tagué aujourd'hui : aucun changement, aucune récompense
        return ['streak' => $current, 'reward' => null];
    }

    $yesterday = date('Y-m-d', strtotime('-1 day'));

    if ($lastLogin === $yesterday) {
        $current = $current + 1;
    } else {
        $current = 1;
    }

    $reward = null;
    if (isset(STREAK_REWARDS[$current]) && $lastClaim !== $today) {
        $reward = STREAK_REWARDS[$current];
        $newClaim = $today;
    } else {
        $newClaim = $lastClaim;
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE users SET streak_days = ?, last_login_date = ?, streak_claim_date = ? WHERE id = ?')
            ->execute([$current, $today, $newClaim, $userId]);

        if ($reward !== null) {
            // Créditer la brute principale du user (la plus ancienne)
            $stmt = $pdo->prepare('SELECT id FROM brutes WHERE user_id = ? ORDER BY id ASC LIMIT 1');
            $stmt->execute([$userId]);
            $bruteId = (int)$stmt->fetchColumn();
            if ($bruteId > 0) {
                $pdo->prepare('UPDATE brutes SET gold = gold + ?, bonus_fights_available = bonus_fights_available + ? WHERE id = ?')
                    ->execute([(int)$reward['gold'], (int)$reward['bonus_fights'], $bruteId]);
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['streak' => $current, 'reward' => null];
    }

    return ['streak' => $current, 'reward' => $reward];
}

/**
 * Lecture seule (pour affichage dashboard, etc.).
 */
function get_user_streak(int $userId): array
{
    $stmt = db()->prepare('SELECT streak_days, last_login_date FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch() ?: ['streak_days' => 0, 'last_login_date' => null];
    return [
        'streak'      => (int)$row['streak_days'],
        'last_login'  => $row['last_login_date'],
        'thresholds'  => array_keys(STREAK_REWARDS),
    ];
}

/**
 * Renvoie la prochaine étape (jour cible) pour la jauge de progression.
 */
function next_streak_milestone(int $current): ?int
{
    foreach (array_keys(STREAK_REWARDS) as $day) {
        if ($current < $day) return (int)$day;
    }
    return null;
}
