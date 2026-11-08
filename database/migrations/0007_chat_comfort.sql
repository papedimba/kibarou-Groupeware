-- Lot 4b : pièces jointes, réponses, modification, suppression, réactions, envoi sans doublon.
ALTER TABLE messages
    ADD COLUMN client_id CHAR(32) NULL AFTER body,
    ADD COLUMN reply_to_id BIGINT UNSIGNED NULL AFTER client_id,
    ADD COLUMN edited_at DATETIME NULL AFTER created_at,
    ADD COLUMN deleted_at DATETIME NULL AFTER edited_at,
    ADD UNIQUE KEY uq_messages_client (sender_id, client_id),
    ADD CONSTRAINT fk_messages_reply FOREIGN KEY (reply_to_id) REFERENCES messages (id) ON DELETE SET NULL;

CREATE TABLE chat_attachments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT UNSIGNED NOT NULL,
    uploader_id INT UNSIGNED NULL,
    message_id BIGINT UNSIGNED NULL,
    kind ENUM('image', 'file') NOT NULL,
    original_name VARCHAR(160) NOT NULL,
    stored_path VARCHAR(120) NOT NULL,
    thumb_path VARCHAR(120) NULL,
    mime VARCHAR(100) NOT NULL,
    size INT UNSIGNED NOT NULL,
    width SMALLINT UNSIGNED NULL,
    height SMALLINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    KEY idx_attachments_message (message_id),
    KEY idx_attachments_created (created_at),
    CONSTRAINT fk_attachments_conversation FOREIGN KEY (conversation_id) REFERENCES conversations (id) ON DELETE CASCADE,
    CONSTRAINT fk_attachments_uploader FOREIGN KEY (uploader_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_attachments_message FOREIGN KEY (message_id) REFERENCES messages (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE chat_reactions (
    message_id BIGINT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    emoji VARCHAR(16) NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (message_id, user_id, emoji),
    CONSTRAINT fk_reactions_message FOREIGN KEY (message_id) REFERENCES messages (id) ON DELETE CASCADE,
    CONSTRAINT fk_reactions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

ALTER TABLE chat_events MODIFY type ENUM('created', 'message', 'member_added', 'member_left', 'renamed', 'message_edited', 'message_deleted', 'reaction') NOT NULL;
