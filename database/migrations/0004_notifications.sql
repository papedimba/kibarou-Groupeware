-- Lot 3 : notifications (en base, dans l'application, navigateur, email immédiat ou récapitulatif).

CREATE TABLE notifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    type VARCHAR(50) NOT NULL,
    title VARCHAR(190) NOT NULL,
    body VARCHAR(500) NULL,
    url VARCHAR(255) NULL,
    actor_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    read_at DATETIME NULL,
    email_status ENUM('none', 'queued', 'digest_pending', 'digested') NOT NULL DEFAULT 'none',
    KEY idx_notifications_user (user_id, read_at, id),
    KEY idx_notifications_email (email_status, user_id),
    KEY idx_notifications_created (created_at),
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_notifications_actor FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Absence de ligne = valeurs par défaut du catalogue (NotificationTypes).
CREATE TABLE notification_preferences (
    user_id INT UNSIGNED NOT NULL,
    type VARCHAR(50) NOT NULL,
    email ENUM('immediate', 'digest', 'off') NOT NULL,
    browser TINYINT(1) NOT NULL,
    PRIMARY KEY (user_id, type),
    CONSTRAINT fk_notification_prefs_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE modules SET is_enabled = 1 WHERE slug = 'notifications';
