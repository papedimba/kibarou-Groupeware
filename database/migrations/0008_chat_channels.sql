-- Lot 4c : salons, salons de service, alertes par conversation, position d'arrivée (accusés de lecture).
ALTER TABLE conversations
    MODIFY type ENUM('direct', 'group', 'channel', 'department') NOT NULL,
    ADD COLUMN department_id INT UNSIGNED NULL AFTER direct_key,
    ADD COLUMN description VARCHAR(200) NULL AFTER name,
    ADD COLUMN archived_at DATETIME NULL AFTER last_event_at,
    ADD UNIQUE KEY uq_conversations_department (department_id),
    ADD CONSTRAINT fk_conversations_department FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE SET NULL;

ALTER TABLE conversation_members
    ADD COLUMN notify TINYINT(1) NOT NULL DEFAULT 1 AFTER left_at,
    ADD COLUMN entered_event_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER joined_event_id;
UPDATE conversation_members SET entered_event_id = joined_event_id;

ALTER TABLE chat_events MODIFY type ENUM('created', 'message', 'member_added', 'member_left', 'renamed', 'message_edited', 'message_deleted', 'reaction', 'archived', 'unarchived') NOT NULL;

INSERT INTO permissions (slug, module, description) VALUES ('chat.channels.manage', 'chat', 'Gérer les salons');
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE r.slug IN ('admin', 'directeur', 'chef_service') AND p.slug = 'chat.channels.manage';
