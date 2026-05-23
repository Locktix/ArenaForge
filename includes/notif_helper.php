<?php
/**
 * Helpers légers pour les notifications persistantes.
 * Intentionnellement minimaliste — ne dépend que de db.php.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Insère une notification et incrémente le compteur non-lu de la brute.
 */
function push_notif(
    int    $bruteId,
    string $kind,
    string $title,
    string $body  = '',
    string $href  = '',
    string $icon  = 'assets/svg/ui/scroll.svg'
): void {
    try {
        db()->prepare('
            INSERT INTO notifications (brute_id, kind, title, body, href, icon)
            VALUES (?, ?, ?, ?, ?, ?)
        ')->execute([$bruteId, $kind, $title, $body, $href, $icon]);

        db()->prepare('
            UPDATE brutes SET notifs_unread_count = notifs_unread_count + 1 WHERE id = ?
        ')->execute([$bruteId]);
    } catch (Throwable $e) { /* silent — ne jamais casser le flux principal */ }
}

/**
 * Retourne les N dernières notifications d'une brute, les non-lues en premier.
 */
function get_persistent_notifs(int $bruteId, int $limit = 25): array
{
    try {
        $stmt = db()->prepare('
            SELECT id, kind, title, body, href, icon,
                   (read_at IS NULL) AS unread,
                   created_at
            FROM notifications
            WHERE brute_id = ?
            ORDER BY (read_at IS NULL) DESC, created_at DESC
            LIMIT ?
        ');
        $stmt->bindValue(1, $bruteId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit,   PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Marque toutes les notifications comme lues et remet le compteur à 0.
 */
function mark_notifs_read(int $bruteId): void
{
    try {
        db()->prepare('
            UPDATE notifications SET read_at = NOW()
            WHERE brute_id = ? AND read_at IS NULL
        ')->execute([$bruteId]);

        db()->prepare('
            UPDATE brutes SET notifs_unread_count = 0 WHERE id = ?
        ')->execute([$bruteId]);
    } catch (Throwable $e) { /* silent */ }
}
