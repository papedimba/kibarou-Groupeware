<?php

declare(strict_types=1);

namespace Tests\Integration;

final class AdminTest extends IntegrationTestCase
{
    private function roleId(string $slug): int
    {
        return (int) $this->app->db()->value('SELECT id FROM roles WHERE slug = ?', [$slug]);
    }

    public function testCollaboratorCannotAccessAdministration(): void
    {
        $this->createUser('collab@kibarou.test');
        $this->login('collab@kibarou.test', 'Motdepasse123');
        foreach (['/admin', '/admin/users', '/admin/roles', '/admin/audit', '/admin/departments'] as $path) {
            $this->assertSame(403, $this->get($path)->status, $path);
        }
        $this->assertSame(403, $this->post('/admin/users', ['email' => 'x@y.z'])->status);
    }

    public function testDirectorWithoutAdminAccessIsDenied(): void
    {
        $this->createUser('dir@kibarou.test', ['directeur']);
        $this->login('dir@kibarou.test', 'Motdepasse123');
        $this->assertSame(403, $this->get('/admin/users')->status, 'users.view seul ne suffit pas sans admin.access');
    }

    public function testAdminCanCreateUserWithInvitationEmailQueued(): void
    {
        $this->createUser('admin@kibarou.test', ['admin']);
        $this->login('admin@kibarou.test', 'Motdepasse123');
        $r = $this->post('/admin/users', [
            'first_name' => 'Awa', 'last_name' => 'Diallo', 'email' => 'Awa@Kibarou.test',
            'roles' => [(string) $this->roleId('collaborateur')],
        ]);
        $id = (int) $this->app->db()->value("SELECT id FROM users WHERE email = 'awa@kibarou.test'");
        $this->assertGreaterThan(0, $id);
        $this->assertRedirect($r, "/admin/users/$id");
        $this->assertSame(1, (int) $this->app->db()->value("SELECT COUNT(*) FROM mail_queue WHERE to_email = 'awa@kibarou.test' AND status = 'pending'"));
        $this->assertSame(1, (int) $this->app->db()->value("SELECT COUNT(*) FROM audit_logs WHERE action = 'user.created' AND entity_id = ?", [$id]));
        $this->assertSame(200, $this->get("/admin/users/$id")->status);
    }

    public function testCreateWithTemporaryPasswordForcesChange(): void
    {
        $this->createUser('admin@kibarou.test', ['admin']);
        $this->login('admin@kibarou.test', 'Motdepasse123');
        $this->post('/admin/users', [
            'first_name' => 'Tmp', 'last_name' => 'User', 'email' => 'tmp@kibarou.test', 'temp_password' => 'Provisoire2026',
            'roles' => [(string) $this->roleId('collaborateur')],
        ]);
        $this->assertSame(1, (int) $this->app->db()->value("SELECT must_change_password FROM users WHERE email = 'tmp@kibarou.test'"));
        $this->assertSame(0, (int) $this->app->db()->value("SELECT COUNT(*) FROM mail_queue WHERE to_email = 'tmp@kibarou.test'"));
    }

    public function testValidationErrorsAreShownAndEscaped(): void
    {
        $this->createUser('admin@kibarou.test', ['admin']);
        $this->login('admin@kibarou.test', 'Motdepasse123');
        $r = $this->post('/admin/users', ['first_name' => '<script>x</script>', 'last_name' => '', 'email' => 'pas-un-email', 'roles' => []]);
        $this->assertRedirect($r, '/admin/users/create');
        $body = $this->get('/admin/users/create')->body;
        $this->assertStringContainsString('doit être une adresse email valide', $body);
        $this->assertStringContainsString('&lt;script&gt;x&lt;/script&gt;', $body);
        $this->assertStringNotContainsString('<script>x</script>', $body);
    }

    public function testAdminCannotGrantSuperAdmin(): void
    {
        $this->createUser('admin@kibarou.test', ['admin']);
        $this->login('admin@kibarou.test', 'Motdepasse123');
        $r = $this->post('/admin/users', [
            'first_name' => 'Evil', 'last_name' => 'Twin', 'email' => 'evil@kibarou.test',
            'roles' => [(string) $this->roleId('super_admin')],
        ]);
        $this->assertSame(403, $r->status);
        $this->assertNull($this->app->db()->value("SELECT id FROM users WHERE email = 'evil@kibarou.test'"));
    }

    public function testAdminCannotModifySuperAdmin(): void
    {
        $superId = $this->createUser('super@kibarou.test', ['super_admin']);
        $this->createUser('admin@kibarou.test', ['admin']);
        $this->login('admin@kibarou.test', 'Motdepasse123');
        $this->assertSame(403, $this->post("/admin/users/$superId/status", ['status' => 'disabled'])->status);
        $this->assertSame('active', $this->app->db()->value('SELECT status FROM users WHERE id = ?', [$superId]));
    }

    public function testCannotDisableSelfOrLastSuperAdmin(): void
    {
        $superId = $this->createUser('super@kibarou.test', ['super_admin']);
        $this->login('super@kibarou.test', 'Motdepasse123');
        $this->post("/admin/users/$superId/status", ['status' => 'disabled']);
        $this->assertSame('active', $this->app->db()->value('SELECT status FROM users WHERE id = ?', [$superId]));
        // Retrait du rôle super_admin au dernier super administrateur refusé
        $r = $this->post("/admin/users/$superId", [
            'first_name' => 'S', 'last_name' => 'A', 'email' => 'super@kibarou.test', 'roles' => [(string) $this->roleId('admin')],
        ]);
        $this->assertRedirect($r, "/admin/users/$superId/edit");
        $this->assertContains('super_admin', array_column($this->app->db()->fetchAll(
            'SELECT r.slug FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ?', [$superId]), 'slug'));
    }

    public function testDisablingUserKillsTheirSessions(): void
    {
        $victim = $this->createUser('victime@kibarou.test');
        $this->login('victime@kibarou.test', 'Motdepasse123');
        $victimCookies = $this->cookies;
        $this->cookies = [];
        $this->createUser('admin@kibarou.test', ['admin']);
        $this->login('admin@kibarou.test', 'Motdepasse123');
        $this->assertRedirect($this->post("/admin/users/$victim/status", ['status' => 'disabled']), "/admin/users/$victim");
        $this->assertSame(0, (int) $this->app->db()->value('SELECT COUNT(*) FROM sessions WHERE user_id = ?', [$victim]));
        $this->cookies = $victimCookies;
        $this->assertRedirect($this->get('/'), '/login');
    }

    public function testUserListSearchAndPagination(): void
    {
        $this->createUser('admin@kibarou.test', ['admin']);
        $this->createUser('zorro@kibarou.test');
        $this->login('admin@kibarou.test', 'Motdepasse123');
        $body = $this->get('/admin/users', ['q' => 'zorro'])->body;
        $this->assertStringContainsString('zorro@kibarou.test', $body);
        $this->assertStringNotContainsString('admin@kibarou.test</div>', $body);
        $this->assertSame(200, $this->get('/admin/users', ['q' => "%' OR 1=1 --"])->status);
    }

    public function testDepartmentCycleIsRejected(): void
    {
        $this->createUser('admin@kibarou.test', ['admin']);
        $this->login('admin@kibarou.test', 'Motdepasse123');
        $this->post('/admin/departments', ['name' => 'Travaux', 'is_active' => '1']);
        $parent = (int) $this->app->db()->value("SELECT id FROM departments WHERE name = 'Travaux'");
        $this->post('/admin/departments', ['name' => 'Gros œuvre', 'parent_id' => (string) $parent, 'is_active' => '1']);
        $child = (int) $this->app->db()->value("SELECT id FROM departments WHERE name = 'Gros œuvre'");
        $r = $this->post("/admin/departments/$parent", ['name' => 'Travaux', 'parent_id' => (string) $child, 'is_active' => '1']);
        $this->assertRedirect($r, "/admin/departments/$parent/edit");
        $this->assertNull($this->app->db()->value('SELECT parent_id FROM departments WHERE id = ?', [$parent]));
        $this->assertSame(200, $this->get('/admin/departments')->status);
    }

    public function testRolesManagementIsSuperAdminOnlyByDefault(): void
    {
        $this->createUser('admin@kibarou.test', ['admin']);
        $this->login('admin@kibarou.test', 'Motdepasse123');
        $this->assertSame(403, $this->get('/admin/roles')->status);
    }

    public function testSuperAdminCreatesCustomRoleThatGrantsAccess(): void
    {
        $this->createUser('super@kibarou.test', ['super_admin']);
        $this->login('super@kibarou.test', 'Motdepasse123');
        $perms = array_map('strval', array_column($this->app->db()->fetchAll(
            "SELECT id FROM permissions WHERE slug IN ('admin.access', 'audit.view')"), 'id'));
        $this->assertRedirect($this->post('/admin/roles', ['slug' => 'auditeur', 'name' => 'Auditeur', 'permissions' => $perms]), '/admin/roles');
        $this->assertSame(403, $this->post('/admin/roles/' . $this->roleId('super_admin'), ['name' => 'x'])->status);

        $this->cookies = [];
        $this->createUser('audit@kibarou.test', ['auditeur']);
        $this->login('audit@kibarou.test', 'Motdepasse123');
        $this->assertSame(200, $this->get('/admin/audit')->status);
        $this->assertSame(403, $this->get('/admin/users')->status);
    }

    public function testAdminDashboardRenders(): void
    {
        $this->createUser('admin@kibarou.test', ['admin']);
        $this->login('admin@kibarou.test', 'Motdepasse123');
        $body = $this->get('/admin')->body;
        $this->assertStringContainsString('Utilisateurs actifs', $body);
        $this->assertStringContainsString('CRON', $body);
    }

    public function testAllAdminPagesRenderForSuperAdmin(): void
    {
        $id = $this->createUser('super@kibarou.test', ['super_admin']);
        $this->login('super@kibarou.test', 'Motdepasse123');
        foreach (['/', '/profile', '/profile/password', '/admin', '/admin/users', '/admin/users/create', "/admin/users/$id",
            "/admin/users/$id/edit", '/admin/departments', '/admin/departments/create', '/admin/positions', '/admin/positions/create',
            '/admin/roles', '/admin/roles/create', '/admin/roles/' . $this->roleId('admin') . '/edit', '/admin/audit'] as $path) {
            $this->assertSame(200, $this->get($path)->status, $path);
        }
        $this->assertSame(404, $this->get('/admin/users/999999')->status);
    }

    public function testAuditLogIsReadable(): void
    {
        $this->createUser('lecteur@kibarou.test', ['admin']);
        $this->login('lecteur@kibarou.test', 'Motdepasse123');
        $body = $this->get('/admin/audit')->body;
        $this->assertStringContainsString('Connexion', $body);
        $this->assertStringContainsString('Test Lecteur', $body);
        $this->assertStringNotContainsString('auth.login</code>', $body);
        $this->assertStringNotContainsString('{&quot;', $body);
        $this->assertSame(200, $this->get('/admin/audit', ['action' => 'auth.login'])->status);
    }

    public function testFailedLoginIsNotAttributedToTheAccountOwner(): void
    {
        $this->createUser('cible@kibarou.test');
        $this->login('cible@kibarou.test', 'mauvais');
        $this->cookies = [];
        $this->createUser('admin2@kibarou.test', ['admin']);
        $this->login('admin2@kibarou.test', 'Motdepasse123');
        $body = $this->get('/admin/audit', ['action' => 'auth.login_failed'])->body;
        $this->assertStringContainsString('<td data-label="Par">Inconnu</td>', $body);
    }

    public function testTempPasswordHandoverSurvivesRefreshUntilDone(): void
    {
        $this->createUser('admin3@kibarou.test', ['admin']);
        $this->login('admin3@kibarou.test', 'Motdepasse123');
        $r = $this->post('/admin/users', [
            'first_name' => 'Remise', 'last_name' => 'Compte', 'email' => 'remise@kibarou.test', 'temp_password' => 'Chantier2026xy',
            'roles' => [(string) $this->roleId('collaborateur')],
        ]);
        $id = (int) $this->app->db()->value("SELECT id FROM users WHERE email = 'remise@kibarou.test'");
        $this->assertRedirect($r, "/admin/users/$id/handover");
        // Chiffré en session : jamais en clair dans la base.
        $this->assertSame(0, (int) $this->app->db()->value('SELECT COUNT(*) FROM sessions WHERE data LIKE ?', ['%Chantier2026xy%']));
        foreach ([1, 2] as $attempt) {
            $body = $this->get("/admin/users/$id/handover")->body;
            $this->assertStringContainsString('Chantier2026xy', $body, "affichage $attempt (actualisation)");
            $this->assertStringContainsString('https://gw.test/login', $body);
        }
        $this->assertRedirect($this->post("/admin/users/$id/handover/done"), "/admin/users/$id");
        $this->assertRedirect($this->get("/admin/users/$id/handover"), "/admin/users/$id");
        $this->assertStringNotContainsString('Chantier2026xy', $this->get("/admin/users/$id")->body);
    }

    public function testHandoverExpiresAndIsBoundToItsUser(): void
    {
        $this->createUser('admin6@kibarou.test', ['admin']);
        $other = $this->createUser('autre@kibarou.test');
        $this->login('admin6@kibarou.test', 'Motdepasse123');
        $this->post('/admin/users', [
            'first_name' => 'Expire', 'last_name' => 'Bientot', 'email' => 'expire@kibarou.test', 'temp_password' => 'Chantier2026zz',
            'roles' => [(string) $this->roleId('collaborateur')],
        ]);
        $id = (int) $this->app->db()->value("SELECT id FROM users WHERE email = 'expire@kibarou.test'");
        $this->assertRedirect($this->get("/admin/users/$other/handover"), "/admin/users/$other");
        $sid = hash('sha256', $this->cookies['kb_session']);
        $data = json_decode((string) $this->app->db()->value('SELECT data FROM sessions WHERE id = ?', [$sid]), true);
        $data['handover']['expires'] = time() - 1;
        $this->app->db()->update('sessions', ['data' => json_encode($data)], ['id' => $sid]);
        $this->assertRedirect($this->get("/admin/users/$id/handover"), "/admin/users/$id");
    }

    public function testCreateFormDefaultsToCollaborateur(): void
    {
        $this->createUser('admin4@kibarou.test', ['admin']);
        $this->login('admin4@kibarou.test', 'Motdepasse123');
        $body = $this->get('/admin/users/create')->body;
        $this->assertMatchesRegularExpression('/value="' . $this->roleId('collaborateur') . '" id="role\d+"\s*checked/', $body);
    }

    public function testAuditNamesNonUserObjects(): void
    {
        $this->createUser('admin5@kibarou.test', ['admin']);
        $this->login('admin5@kibarou.test', 'Motdepasse123');
        $this->post('/admin/departments', ['name' => 'Gros œuvre', 'is_active' => '1']);
        $body = $this->get('/admin/audit', ['action' => 'department.created'])->body;
        $this->assertStringContainsString('<td data-label="Concerne">', $body);
        $this->assertMatchesRegularExpression('/data-label="Concerne">\s*Gros œuvre/', $body);
    }
}
