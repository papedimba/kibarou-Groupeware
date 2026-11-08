-- Lot 2 : annuaire, organigramme et présence.

ALTER TABLE users ADD COLUMN phone_personal VARCHAR(30) NULL AFTER phone;

-- Une ligne au plus par personne. start_date = jour local (APP_TIMEZONE) de la déclaration ;
-- le statut vaut jusqu'à COALESCE(until_date, start_date) inclus.
CREATE TABLE user_presence (
    user_id INT UNSIGNED NOT NULL PRIMARY KEY,
    status ENUM('office', 'site', 'travel', 'leave', 'absent') NOT NULL,
    site_label VARCHAR(120) NULL,
    note VARCHAR(160) NULL,
    start_date DATE NOT NULL,
    until_date DATE NULL,
    set_by INT UNSIGNED NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_presence_until (until_date),
    KEY idx_presence_start (start_date),
    CONSTRAINT fk_presence_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_presence_set_by FOREIGN KEY (set_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (slug, module, description) VALUES
('directory.view', 'directory', 'Consulter l''annuaire');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE r.slug IN ('admin', 'directeur', 'chef_service', 'collaborateur') AND p.slug = 'directory.view';

UPDATE modules SET is_enabled = 1 WHERE slug = 'directory';
