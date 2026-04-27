<?php
// Combat duo (2v2) — un maître et son pupille contre un autre duo
//
// Règles métier :
//   - Le partenaire DOIT être un pupille direct du maître (lien `pupils`)
//   - L'adversaire est un maître + son plus ancien pupille (auto-sélection)
//   - Le combat consomme 1 combat journalier au maître joueur (pas au pupille)
//   - Tous les 4 gagnent de l'XP, mais MMR appliqué uniquement aux maîtres
//   - Le combat se termine quand l'un des deux maîtres tombe (le pupille est
//     un support, comme un pet). Cohérent avec la logique existante.

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/combat_engine.php';
require_once __DIR__ . '/elo_engine.php';
require_once __DIR__ . '/brute_generator.php';

function find_duo_opponent(int $masterId, int $partnerId, int $level): ?array
{
    $pdo = db();

    // Cherche un maître ennemi (autre user) ayant au moins 1 pupille,
    // proche en niveau. On prend son plus ancien pupille comme partenaire.
    $stmt = $pdo->prepare('
        SELECT b.id AS master_id, b.level, b.user_id,
               (SELECT pupil_id FROM pupils p WHERE p.master_id = b.id ORDER BY p.created_at ASC LIMIT 1) AS partner_id
        FROM brutes b
        WHERE b.id NOT IN (?, ?)
          AND b.user_id != (SELECT user_id FROM brutes WHERE id = ?)
          AND b.level BETWEEN ? AND ?
          AND EXISTS (SELECT 1 FROM pupils p2 WHERE p2.master_id = b.id)
        ORDER BY ABS(b.level - ?) ASC, RAND()
        LIMIT 1
    ');
    $stmt->execute([
        $masterId, $partnerId, $masterId,
        max(1, $level - 5), $level + 5, $level,
    ]);
    $row = $stmt->fetch();
    if (!$row) return null;

    return [
        'master_id'  => (int)$row['master_id'],
        'partner_id' => (int)$row['partner_id'],
    ];
}

/**
 * Lance un combat duo. Retourne le résultat ou un tableau d'erreur.
 */
function start_duo_fight(int $masterId, int $partnerId): array
{
    $pdo = db();

    // Vérifier le lien pupille
    $stmt = $pdo->prepare('SELECT 1 FROM pupils WHERE master_id = ? AND pupil_id = ? LIMIT 1');
    $stmt->execute([$masterId, $partnerId]);
    if (!$stmt->fetchColumn()) {
        return ['ok' => false, 'error' => 'Ce gladiateur n\'est pas un de tes pupilles'];
    }

    $stmt = $pdo->prepare('SELECT id, level, fights_today, last_fight_date, bonus_fights_available, pending_levelup FROM brutes WHERE id = ? LIMIT 1');
    $stmt->execute([$masterId]);
    $master = $stmt->fetch();
    if (!$master) return ['ok' => false, 'error' => 'Maître introuvable'];
    if ((int)$master['pending_levelup'] === 1) {
        return ['ok' => false, 'error' => 'Choisis ton bonus de niveau avant le duo'];
    }

    $stmt = $pdo->prepare('SELECT id, pending_levelup FROM brutes WHERE id = ? LIMIT 1');
    $stmt->execute([$partnerId]);
    $partner = $stmt->fetch();
    if (!$partner) return ['ok' => false, 'error' => 'Pupille introuvable'];
    if ((int)$partner['pending_levelup'] === 1) {
        return ['ok' => false, 'error' => 'Le pupille doit choisir son bonus de niveau avant'];
    }

    // Reset compteur si jour différent
    $today = date('Y-m-d');
    if ($master['last_fight_date'] !== $today) {
        $pdo->prepare('UPDATE brutes SET fights_today = 0, last_fight_date = ? WHERE id = ?')
            ->execute([$today, $masterId]);
        $master['fights_today'] = 0;
    }

    $baseLeft  = max(0, 6 - (int)$master['fights_today']);
    $bonusLeft = (int)$master['bonus_fights_available'];
    if ($baseLeft + $bonusLeft <= 0) {
        return ['ok' => false, 'error' => 'Plus aucun combat disponible'];
    }
    $useBonus = ($baseLeft === 0);

    // Trouver l'adversaire
    $opp = find_duo_opponent($masterId, $partnerId, (int)$master['level']);
    if (!$opp) {
        return ['ok' => false, 'error' => 'Aucun duo adverse disponible (besoin d\'un maître avec au moins 1 pupille)'];
    }

    // Construction des combattants
    $left  = build_duo_team($masterId, $partnerId, 'L');
    $right = build_duo_team($opp['master_id'], $opp['partner_id'], 'R');
    $combatants = array_merge($left, $right);

    $result = run_combat_loop($combatants);

    $isWinner = ($result['winner_id'] === $masterId || $result['winner_id'] === $partnerId);
    // En réalité winner_id sera celui dont le L0/R0 est en vie, donc soit
    // master player, soit master adverse. On normalise.
    $isWinner = ($result['winner_id'] === $masterId);

    // Insertion fight (context = duo) : brute1 = master joueur, brute2 = master adverse
    $stmt = $pdo->prepare("
        INSERT INTO fights (brute1_id, brute2_id, winner_id, log_json, xp_gained, context)
        VALUES (?, ?, ?, ?, ?, 'duo')
    ");
    $xpMaster   = $isWinner ? 4 : 1;
    $xpPartner  = $isWinner ? 2 : 1;
    $stmt->execute([
        $masterId, $opp['master_id'],
        $isWinner ? $masterId : $opp['master_id'],
        json_encode($result['log'], JSON_UNESCAPED_UNICODE),
        $xpMaster,
    ]);
    $fightId = (int)$pdo->lastInsertId();

    // Distribuer XP aux 4 brutes
    $awards = [
        $masterId         => $xpMaster,
        $partnerId        => $xpPartner,
        $opp['master_id'] => $isWinner ? 1 : 4,
        $opp['partner_id']=> $isWinner ? 1 : 2,
    ];
    foreach ($awards as $bid => $gain) {
        $stmt = $pdo->prepare('SELECT xp, level, pending_levelup FROM brutes WHERE id = ? LIMIT 1');
        $stmt->execute([$bid]);
        $b = $stmt->fetch();
        if (!$b) continue;
        $newXp    = (int)$b['xp'] + $gain;
        $newLevel = (int)$b['level'];
        $levelUp  = (int)$b['pending_levelup'] === 1;
        while ($newXp >= xp_for_level($newLevel + 1)) {
            $newLevel++;
            $levelUp = true;
        }
        $pdo->prepare('UPDATE brutes SET xp = ?, level = ?, pending_levelup = ? WHERE id = ?')
            ->execute([$newXp, $newLevel, $levelUp ? 1 : 0, $bid]);
    }

    // Fragments + or pour les deux camps
    $pdo->prepare('UPDATE brutes SET fragments = fragments + 2, gold = gold + 2 WHERE id IN (?, ?, ?, ?)')
        ->execute([$masterId, $partnerId, $opp['master_id'], $opp['partner_id']]);

    // Consommation d'un combat journalier (master uniquement)
    if ($useBonus) {
        $pdo->prepare('UPDATE brutes SET bonus_fights_available = bonus_fights_available - 1 WHERE id = ?')
            ->execute([$masterId]);
    } else {
        $pdo->prepare('UPDATE brutes SET fights_today = fights_today + 1 WHERE id = ?')
            ->execute([$masterId]);
    }

    // ELO entre les deux maîtres
    $winnerForElo = $isWinner ? $masterId : (int)$opp['master_id'];
    $loserForElo  = $isWinner ? (int)$opp['master_id'] : $masterId;
    elo_apply_fight($winnerForElo, $loserForElo);

    return [
        'ok'        => true,
        'fight_id'  => $fightId,
        'won'       => $isWinner,
        'redirect'  => 'fight.php?id=' . $fightId,
    ];
}
