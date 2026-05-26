-- ArenaForge — Migration chat global
-- À exécuter une seule fois via phpMyAdmin

CREATE TABLE IF NOT EXISTS chat_messages (
    id          INT UNSIGNED     AUTO_INCREMENT PRIMARY KEY,
    brute_id    INT              NOT NULL,
    brute_name  VARCHAR(20)      NOT NULL,
    message     VARCHAR(200)     NOT NULL,
    created_at  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created (created_at),
    INDEX idx_brute  (brute_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
