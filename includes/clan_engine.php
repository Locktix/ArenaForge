<?php
// Moteur clans : création, adhésion, leaderboard, gestion des membres

declare(strict_types=1);

require_once __DIR__ . '/db.php';

const CLAN_CREATE_XP_COST = 50;
const CLAN_MAX_MEMBERS    = 10;
const CLAN_NAME_REGEX     = '/^[A-Za-z0-9 _\-]{3,40}$/';
const CLAN_TAG_REGEX      = '/^[A-Z0-9]{2,5}$/';

// ============================================================
// Lecture
// ============================================================

function list_clans(int $limit = 100): array
{
    $stmt = db()->prepare('
        SELECT c.*,
               COUNT(cm.brute_id) AS member_count,
               COALESCE(SUM(b.mmr), 0) AS total_mmr,
               COALESCE(AVG(b.level), 0) AS avg_level
        FROM clans c
        LEFT JOIN clan_members cm ON cm.clan_id = c.id
        LEFT JOIN brutes b ON b.id = cm.brute_id
        GROUP BY c.id
        ORDER BY total_mmr DESC, member_count DESC
        LIMIT ?
    ');
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function get_clan(int $clanId): ?array
{
    $stmt = db()->prepare('SELECT * FROM clans WHERE id = ? LIMIT 1');
    $stmt->execute([$clanId]);
    $c = $stmt->fetch();
    return $c ?: null;
}

function get_clan_members(int $clanId): array
{
    $stmt = db()->prepare('
        SELECT b.id, b.name, b.level, b.mmr, b.appearance_seed,
               cm.role, cm.joined_at
        FROM clan_members cm
        JOIN brutes b ON b.id = cm.brute_id
        WHERE cm.clan_id = ?
        ORDER BY FIELD(cm.role, "leader", "officer", "member"), b.mmr DESC
    ');
    $stmt->execute([$clanId]);
    return $stmt->fetchAll();
}

function get_brute_clan(int $bruteId): ?array
{
    $stmt = db()->prepare('
        SELECT c.*, cm.role
        FROM clan_members cm
        JOIN clans c ON c.id = cm.clan_id
        WHERE cm.brute_id = ?
        LIMIT 1
    ');
    $stmt->execute([$bruteId]);
    $c = $stmt->fetch();
    return $c ?: null;
}

// ============================================================
// Création
// ============================================================

function create_clan(int $bruteId, string $name, string $tag, string $description): array
{
    $pdo = db();

    $name        = trim($name);
    $tag         = strtoupper(trim($tag));
    $description = trim($description);

    if (!preg_match(CLAN_NAME_REGEX, $name)) {
        return ['ok' => false, 'error' => 'Nom invalide (3-40 caractères : lettres, chiffres, espaces, _ ou -)'];
    }
    if (!preg_match(CLAN_TAG_REGEX, $tag)) {
        return ['ok' => false, 'error' => 'Tag invalide (2-5 caractères majuscules/chiffres)'];
    }
    if (mb_strlen($description) > 255) {
        return ['ok' => false, 'error' => 'Description trop longue (255 max)'];
    }

    $stmt = $pdo->prepare('SELECT xp, clan_id FROM brutes WHERE id = ? LIMIT 1');
    $stmt->execute([$bruteId]);
    $b = $stmt->fetch();
    if (!$b) return ['ok' => false, 'error' => 'Gladiateur introuvable'];
    if ($b['clan_id'] !== null) return ['ok' => false, 'error' => 'Tu es déjà dans un clan'];
    if ((int)$b['xp'] < CLAN_CREATE_XP_COST) {
        return ['ok' => false, 'error' => 'XP insuffisante (' . CLAN_CREATE_XP_COST . ' requis)'];
    }

    // Unicité name/tag
    $stmt = $pdo->prepare('SELECT id FROM clans WHERE name = ? OR tag = ? LIMIT 1');
    $stmt->execute([$name, $tag]);
    if ($stmt->fetchColumn()) return ['ok' => false, 'error' => 'Nom ou tag déjà utilisé'];

    $pdo->beginTransaction();
    try {
        $pdo->prepare('
            INSERT INTO clans (name, tag, description, leader_brute_id, max_members)
            VALUES (?, ?, ?, ?, ?)
        ')->execute([$name, $tag, $description, $bruteId, CLAN_MAX_MEMBERS]);
        $clanId = (int)$pdo->lastInsertId();

        $pdo->prepare('
            INSERT INTO clan_members (clan_id, brute_id, role)
            VALUES (?, ?, "leader")
        ')->execute([$clanId, $bruteId]);

        $pdo->prepare('UPDATE brutes SET clan_id = ?, xp = xp - ? WHERE id = ?')
            ->execute([$clanId, CLAN_CREATE_XP_COST, $bruteId]);

        $pdo->commit();
        return ['ok' => true, 'clan_id' => $clanId];
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['ok' => false, 'error' => 'Erreur : ' . $e->getMessage()];
    }
}

// ============================================================
// Adhésion
// ============================================================

function join_clan(int $bruteId, int $clanId): array
{
    $pdo = db();

    $stmt = $pdo->prepare('SELECT clan_id FROM brutes WHERE id = ? LIMIT 1');
    $stmt->execute([$bruteId]);
    $current = $stmt->fetchColumn();
    if ($current !== null && $current !== false) {
        return ['ok' => false, 'error' => 'Tu es déjà dans un clan'];
    }

    $stmt = $pdo->prepare('SELECT max_members FROM clans WHERE id = ? LIMIT 1');
    $stmt->execute([$clanId]);
    $max = (int)$stmt->fetchColumn();
    if ($max <= 0) return ['ok' => false, 'error' => 'Clan introuvable'];

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM clan_members WHERE clan_id = ?');
    $stmt->execute([$clanId]);
    $count = (int)$stmt->fetchColumn();
    if ($count >= $max) return ['ok' => false, 'error' => 'Clan complet'];

    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO clan_members (clan_id, brute_id, role) VALUES (?, ?, "member")')
            ->execute([$clanId, $bruteId]);
        $pdo->prepare('UPDATE brutes SET clan_id = ? WHERE id = ?')
            ->execute([$clanId, $bruteId]);
        $pdo->commit();
        return ['ok' => true];
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['ok' => false, 'error' => 'Erreur : ' . $e->getMessage()];
    }
}

/**
 * Quitter un clan. Si c'était le leader et qu'il reste des membres, le plus
 * ancien membre (hors leader) est promu leader. Si c'était le dernier membre,
 * le clan est supprimé.
 */
function leave_clan(int $bruteId): array
{
    $pdo = db();

    $stmt = $pdo->prepare('SELECT clan_id FROM clan_members WHERE brute_id = ? LIMIT 1');
    $stmt->execute([$bruteId]);
    $clanId = $stmt->fetchColumn();
    if (!$clanId) return ['ok' => false, 'error' => 'Tu n\'es dans aucun clan'];
    $clanId = (int)$clanId;

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM clan_members WHERE brute_id = ?')->execute([$bruteId]);
        $pdo->prepare('UPDATE brutes SET clan_id = NULL WHERE id = ?')->execute([$bruteId]);

        $stmt = $pdo->prepare('SELECT leader_brute_id FROM clans WHERE id = ? LIMIT 1');
        $stmt->execute([$clanId]);
        $leaderId = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM clan_members WHERE clan_id = ?');
        $stmt->execute([$clanId]);
        $remaining = (int)$stmt->fetchColumn();

        if ($remaining === 0) {
            $pdo->prepare('DELETE FROM clans WHERE id = ?')->execute([$clanId]);
        } elseif ($leaderId === $bruteId) {
            // Promouvoir le plus ancien
            $stmt = $pdo->prepare('
                SELECT brute_id FROM clan_members
                WHERE clan_id = ? ORDER BY joined_at ASC LIMIT 1
            ');
            $stmt->execute([$clanId]);
            $newLeader = (int)$stmt->fetchColumn();
            $pdo->prepare('UPDATE clan_members SET role = "leader" WHERE clan_id = ? AND brute_id = ?')
                ->execute([$clanId, $newLeader]);
            $pdo->prepare('UPDATE clans SET leader_brute_id = ? WHERE id = ?')
                ->execute([$newLeader, $clanId]);
        }

        $pdo->commit();
        return ['ok' => true];
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['ok' => false, 'error' => 'Erreur : ' . $e->getMessage()];
    }
}

/**
 * Renvoi d'un membre (leader uniquement).
 */
function kick_member(int $leaderBruteId, int $targetBruteId): array
{
    if ($leaderBruteId === $targetBruteId) {
        return ['ok' => false, 'error' => 'Impossible de se bannir soi-même'];
    }

    $pdo = db();

    $stmt = $pdo->prepare('
        SELECT clan_id, role FROM clan_members WHERE brute_id = ? LIMIT 1
    ');
    $stmt->execute([$leaderBruteId]);
    $leader = $stmt->fetch();
    if (!$leader || $leader['role'] !== 'leader') {
        return ['ok' => false, 'error' => 'Autorisation requise (leader)'];
    }

    $stmt = $pdo->prepare('SELECT clan_id FROM clan_members WHERE brute_id = ? LIMIT 1');
    $stmt->execute([$targetBruteId]);
    $target = $stmt->fetch();
    if (!$target || (int)$target['clan_id'] !== (int)$leader['clan_id']) {
        return ['ok' => false, 'error' => 'Membre introuvable dans ton clan'];
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM clan_members WHERE brute_id = ?')->execute([$targetBruteId]);
        $pdo->prepare('UPDATE brutes SET clan_id = NULL WHERE id = ?')->execute([$targetBruteId]);
        $pdo->commit();
        return ['ok' => true];
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['ok' => false, 'error' => 'Erreur : ' . $e->getMessage()];
    }
}
