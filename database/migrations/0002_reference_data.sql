-- Données de référence nécessaires en production (pas des données de démonstration).

INSERT INTO roles (slug, name, description, is_system, created_at, updated_at) VALUES
('super_admin', 'Super administrateur', 'Accès complet, y compris la gestion des rôles et des super administrateurs.', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
('admin', 'Administrateur', 'Administration des utilisateurs et de l''organisation.', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
('directeur', 'Directeur', 'Direction : consultation élargie.', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
('chef_service', 'Chef de service', 'Responsable d''un service.', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
('collaborateur', 'Collaborateur', 'Utilisateur standard.', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP());

INSERT INTO permissions (slug, module, description) VALUES
('admin.access', 'admin', 'Accéder à l''espace d''administration'),
('users.view', 'users', 'Consulter la liste des utilisateurs'),
('users.manage', 'users', 'Créer, modifier, activer ou désactiver des utilisateurs'),
('organization.manage', 'organization', 'Gérer les services et les fonctions'),
('roles.manage', 'roles', 'Gérer les rôles et leurs permissions'),
('sessions.manage', 'users', 'Consulter et révoquer les sessions des utilisateurs'),
('audit.view', 'admin', 'Consulter le journal d''audit'),
('settings.manage', 'admin', 'Modifier les paramètres de l''application');

-- super_admin n'a pas besoin de lignes : il possède implicitement toutes les permissions.
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE r.slug = 'admin' AND p.slug IN ('admin.access', 'users.view', 'users.manage', 'organization.manage', 'sessions.manage', 'audit.view', 'settings.manage');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE r.slug IN ('directeur', 'chef_service') AND p.slug = 'users.view';

INSERT INTO modules (slug, name, is_enabled, sort_order) VALUES
('auth', 'Authentification et administration', 1, 10),
('chat', 'Messagerie', 0, 20),
('calendar', 'Calendrier', 0, 30),
('tasks', 'Tâches', 0, 40),
('projects', 'Projets', 0, 50),
('meetings', 'Réunions', 0, 60),
('documents', 'Documents', 0, 70),
('announcements', 'Annonces', 0, 80),
('workflows', 'Workflows', 0, 90),
('directory', 'Annuaire', 0, 100),
('notifications', 'Notifications', 0, 110);

INSERT INTO settings (`key`, `value`, updated_at) VALUES
('company_name', 'Kibarou Construction', UTC_TIMESTAMP());
