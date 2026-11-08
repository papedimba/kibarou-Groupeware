-- Lot 4a : messagerie (conversations privées et de groupe, journal d'évènements).

CREATE TABLE conversations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    type ENUM('direct', 'group') NOT NULL,
    name VARCHAR(80) NULL,
    -- « idMin-idMax » : une seule conversation privée par paire.
    direct_key VARCHAR(23) NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    last_event_at DATETIME NOT NULL,
    UNIQUE KEY uq_conversations_direct (direct_key),
    CONSTRAINT fk_conversations_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Un membre ne voit que les évènements d'id > joined_event_id ; left_at non nul = ne voit plus rien.
CREATE TABLE conversation_members (
    conversation_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    joined_event_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    left_at DATETIME NULL,
    last_read_event_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    last_read_at DATETIME NULL,
    PRIMARY KEY (conversation_id, user_id),
    KEY idx_members_user (user_id, left_at),
    CONSTRAINT fk_members_conversation FOREIGN KEY (conversation_id) REFERENCES conversations (id) ON DELETE CASCADE,
    CONSTRAINT fk_members_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT UNSIGNED NOT NULL,
    sender_id INT UNSIGNED NULL,
    body TEXT NOT NULL,
    created_at DATETIME NOT NULL,
    KEY idx_messages_sender (sender_id, created_at),
    CONSTRAINT fk_messages_conversation FOREIGN KEY (conversation_id) REFERENCES conversations (id) ON DELETE CASCADE,
    CONSTRAINT fk_messages_sender FOREIGN KEY (sender_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Journal d'évènements : curseur unique de l'interrogation (et d'un futur transport temps réel).
CREATE TABLE chat_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT UNSIGNED NOT NULL,
    type ENUM('created', 'message', 'member_added', 'member_left', 'renamed') NOT NULL,
    message_id BIGINT UNSIGNED NULL,
    actor_id INT UNSIGNED NULL,
    target_user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    KEY idx_events_conversation (conversation_id, id),
    CONSTRAINT fk_events_conversation FOREIGN KEY (conversation_id) REFERENCES conversations (id) ON DELETE CASCADE,
    CONSTRAINT fk_events_message FOREIGN KEY (message_id) REFERENCES messages (id) ON DELETE CASCADE,
    CONSTRAINT fk_events_actor FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_events_target FOREIGN KEY (target_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (slug, module, description) VALUES
('chat.use', 'chat', 'Utiliser la messagerie');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE r.slug IN ('admin', 'directeur', 'chef_service', 'collaborateur') AND p.slug = 'chat.use';

UPDATE modules SET is_enabled = 1 WHERE slug = 'chat';
