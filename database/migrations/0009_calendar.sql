-- Lot 5a : calendrier (évènements, séries matérialisées, participants, rappels, abonnement iCalendar).
CREATE TABLE calendar_series (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    organizer_id INT UNSIGNED NULL,
    kind ENUM('weekdays', 'weekly', 'monthly') NOT NULL,
    weekdays SET('1', '2', '3', '4', '5', '6', '7') NULL,
    until_date DATE NOT NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_series_organizer FOREIGN KEY (organizer_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE events (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    series_id INT UNSIGNED NULL,
    organizer_id INT UNSIGNED NULL,
    title VARCHAR(150) NOT NULL,
    location VARCHAR(150) NULL,
    description TEXT NULL,
    all_day TINYINT(1) NOT NULL DEFAULT 0,
    start_at DATETIME NULL,
    end_at DATETIME NULL,
    start_date DATE NULL,
    end_date DATE NULL,
    is_private TINYINT(1) NOT NULL DEFAULT 0,
    reminder_minutes SMALLINT UNSIGNED NULL,
    status ENUM('confirmed', 'cancelled') NOT NULL DEFAULT 'confirmed',
    detached TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_events_start (start_at),
    KEY idx_events_start_date (start_date),
    KEY idx_events_series (series_id, start_at),
    CONSTRAINT fk_events_series FOREIGN KEY (series_id) REFERENCES calendar_series (id) ON DELETE CASCADE,
    CONSTRAINT fk_events_organizer FOREIGN KEY (organizer_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE event_participants (
    event_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    response ENUM('pending', 'yes', 'maybe', 'no') NOT NULL DEFAULT 'pending',
    reminder_minutes SMALLINT UNSIGNED NULL,
    reminder_custom TINYINT(1) NOT NULL DEFAULT 0,
    reminded_at DATETIME NULL,
    responded_at DATETIME NULL,
    PRIMARY KEY (event_id, user_id),
    KEY idx_participants_user (user_id, event_id),
    CONSTRAINT fk_participants_event FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE CASCADE,
    CONSTRAINT fk_participants_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE calendar_feeds (
    user_id INT UNSIGNED NOT NULL PRIMARY KEY,
    token_hash CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_feeds_token (token_hash),
    CONSTRAINT fk_feeds_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE calendar_feed_hits (
    token_hash CHAR(64) NOT NULL,
    hit_at DATETIME NOT NULL,
    KEY idx_feed_hits (token_hash, hit_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (slug, module, description) VALUES ('calendar.use', 'calendar', 'Utiliser le calendrier');
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE r.slug IN ('admin', 'directeur', 'chef_service', 'collaborateur') AND p.slug = 'calendar.use';
UPDATE modules SET is_enabled = 1 WHERE slug = 'calendar';
