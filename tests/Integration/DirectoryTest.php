<?php

declare(strict_types=1);

namespace Tests\Integration;

final class DirectoryTest extends IntegrationTestCase
{
    private function department(string $name, ?int $parent = null, ?int $manager = null): int
    {
        return $this->app->db()->insert('departments', [
            'name' => $name, 'parent_id' => $parent, 'manager_user_id' => $manager, 'is_active' => 1,
            'created_at' => now_utc(), 'updated_at' => now_utc(),
        ]);
    }

    public function testEveryRoleCanOpenDirectory(): void
    {
        $this->createUser('collab@kibarou.test');
        $this->createUser('fatou@kibarou.test', ['collaborateur'], 'Motdepasse123', ['first_name' => 'Fatou', 'last_name' => 'Koné']);
        $this->createUser('parti@kibarou.test', ['collaborateur'], 'Motdepasse123', ['first_name' => 'Ancien', 'last_name' => 'Parti', 'status' => 'disabled']);
        $this->login('collab@kibarou.test', 'Motdepasse123');
        $r = $this->get('/directory');
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('Koné', $r->body);
        $this->assertStringNotContainsString('Parti', $r->body);
    }

    public function testCustomRoleWithoutPermissionIsDenied(): void
    {
        $this->app->db()->insert('roles', ['slug' => 'prestataire', 'name' => 'Prestataire', 'is_system' => 0, 'created_at' => now_utc(), 'updated_at' => now_utc()]);
        $this->createUser('presta@kibarou.test', ['prestataire']);
        $this->login('presta@kibarou.test', 'Motdepasse123');
        $this->assertSame(403, $this->get('/directory')->status);
    }

    public function testServerSearchIgnoresAccents(): void
    {
        $this->createUser('collab@kibarou.test');
        $this->createUser('oumar@kibarou.test', ['collaborateur'], 'Motdepasse123', ['first_name' => 'Oumar', 'last_name' => 'Keïta']);
        $this->createUser('awa@kibarou.test', ['collaborateur'], 'Motdepasse123', ['first_name' => 'Awa', 'last_name' => 'Diallo']);
        $this->login('collab@kibarou.test', 'Motdepasse123');
        $body = $this->get('/directory', ['q' => 'keita'])->body;
        $this->assertStringContainsString('Keïta', $body);
        $this->assertStringNotContainsString('Diallo', $body);
    }

    public function testDepartmentFilter(): void
    {
        $travaux = $this->department('Travaux');
        $this->createUser('collab@kibarou.test');
        $this->createUser('moussa@kibarou.test', ['collaborateur'], 'Motdepasse123', ['first_name' => 'Moussa', 'last_name' => 'Traoré', 'department_id' => $travaux]);
        $this->createUser('mariam@kibarou.test', ['collaborateur'], 'Motdepasse123', ['first_name' => 'Mariam', 'last_name' => 'Cissé']);
        $this->login('collab@kibarou.test', 'Motdepasse123');
        $body = $this->get('/directory', ['department_id' => (string) $travaux])->body;
        $this->assertStringContainsString('Traoré', $body);
        $this->assertStringNotContainsString('Cissé', $body);
    }

    public function testNavShowsDirectory(): void
    {
        $this->createUser('collab@kibarou.test');
        $this->login('collab@kibarou.test', 'Motdepasse123');
        $this->assertStringContainsString('href="/directory"', $this->get('/')->body);
    }

    private const PERSONAL = '+223 70 00 00 01';

    /** @return array{target:int,manager:int,head:int,colleague:int,dept:int} */
    private function team(): array
    {
        $manager = $this->createUser('manager@kibarou.test');
        $head = $this->createUser('head@kibarou.test');
        $dept = $this->department('Travaux', null, $head);
        $target = $this->createUser('cible@kibarou.test', ['collaborateur'], 'Motdepasse123',
            ['first_name' => 'Cible', 'last_name' => 'Agent', 'department_id' => $dept, 'manager_id' => $manager, 'phone_personal' => self::PERSONAL]);
        $colleague = $this->createUser('collegue@kibarou.test', ['collaborateur'], 'Motdepasse123', ['department_id' => $dept]);
        return compact('target', 'manager', 'head', 'colleague', 'dept');
    }

    private function seesPersonal(string $email, int $target, array $roles = []): bool
    {
        $this->cookies = [];
        if ($roles !== []) {
            $this->createUser($email, $roles);
        }
        $this->login($email, 'Motdepasse123');
        $r = $this->get("/directory/$target");
        $this->assertSame(200, $r->status);
        return str_contains($r->body, self::PERSONAL);
    }

    public function testPersonalPhoneVisibility(): void
    {
        $t = $this->team();
        $this->assertTrue($this->seesPersonal('cible@kibarou.test', $t['target']), 'la personne elle-même');
        $this->assertTrue($this->seesPersonal('manager@kibarou.test', $t['target']), 'responsable hiérarchique');
        $this->assertTrue($this->seesPersonal('head@kibarou.test', $t['target']), 'responsable du service');
        $this->assertTrue($this->seesPersonal('rh@kibarou.test', $t['target'], ['admin']), 'administrateur');
        $this->assertFalse($this->seesPersonal('collegue@kibarou.test', $t['target']), 'collègue du même service');
        $this->assertFalse($this->seesPersonal('dir@kibarou.test', $t['target'], ['directeur']), 'directeur sans lien');
    }

    public function testResponsableRightsFollowOrganisation(): void
    {
        $t = $this->team();
        $this->assertTrue($this->seesPersonal('head@kibarou.test', $t['target']));
        $this->app->db()->update('departments', ['manager_user_id' => null], ['id' => $t['dept']]);
        $this->assertFalse($this->seesPersonal('head@kibarou.test', $t['target']));
    }

    public function testDisabledOrUnknownProfileIs404(): void
    {
        $this->createUser('collab@kibarou.test');
        $gone = $this->createUser('parti@kibarou.test', ['collaborateur'], 'Motdepasse123', ['status' => 'disabled']);
        $this->login('collab@kibarou.test', 'Motdepasse123');
        $this->assertSame(404, $this->get("/directory/$gone")->status);
        $this->assertSame(404, $this->get('/directory/999999')->status);
        $this->assertSame(200, $this->get('/directory/' . (int) $this->app->db()->value("SELECT id FROM users WHERE email = 'collab@kibarou.test'"))->status);
    }

    public function testWriteButtonHiddenWhileChatDisabled(): void
    {
        $this->app->db()->run("UPDATE modules SET is_enabled = 0 WHERE slug = 'chat'");
        $t = $this->team();
        $this->login('collegue@kibarou.test', 'Motdepasse123');
        $r = $this->get("/directory/{$t['target']}");
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('Cible Agent', $r->body);
        $this->assertStringNotContainsString('Écrire', $r->body);
    }

    public function testProfileSavesBothPhones(): void
    {
        $id = $this->createUser('moi@kibarou.test');
        $this->login('moi@kibarou.test', 'Motdepasse123');
        $this->assertRedirect($this->post('/profile', ['phone' => '+223 20 00 00 00', 'phone_personal' => '+223 76 00 00 00']), '/profile');
        $row = $this->app->db()->fetch('SELECT phone, phone_personal FROM users WHERE id = ?', [$id]);
        $this->assertSame(['phone' => '+223 20 00 00 00', 'phone_personal' => '+223 76 00 00 00'], $row);
        $this->post('/profile', ['phone' => '+223 20 00 00 00', 'phone_personal' => 'abc']);
        $this->assertStringContainsString('Téléphone personnel', $this->get('/profile')->body);
        $this->assertSame('+223 76 00 00 00', $this->app->db()->value('SELECT phone_personal FROM users WHERE id = ?', [$id]));
    }

    public function testAdminPagesHidePersonalPhoneFromViewOnlyRoles(): void
    {
        $t = $this->team();
        $this->app->db()->run("INSERT INTO role_permissions (role_id, permission_id) SELECT r.id, p.id FROM roles r JOIN permissions p WHERE r.slug = 'directeur' AND p.slug = 'admin.access'");
        $this->createUser('dir@kibarou.test', ['directeur']);
        $this->login('dir@kibarou.test', 'Motdepasse123');
        $this->assertStringNotContainsString(self::PERSONAL, $this->get("/admin/users/{$t['target']}")->body);
        $this->cookies = [];
        $this->createUser('rh@kibarou.test', ['admin']);
        $this->login('rh@kibarou.test', 'Motdepasse123');
        $this->assertStringContainsString(self::PERSONAL, $this->get("/admin/users/{$t['target']}")->body, 'visible avec users.manage');
    }

    public function testOrgChartShowsHierarchyAndHeads(): void
    {
        $head = $this->createUser('chef@kibarou.test', ['collaborateur'], 'Motdepasse123', ['first_name' => 'Chef', 'last_name' => 'Travaux']);
        $dg = $this->department('Direction générale');
        $trv = $this->department('Travaux', $dg, $head);
        $this->app->db()->update('users', ['department_id' => $trv], ['id' => $head]);
        $this->createUser('agent@kibarou.test', ['collaborateur'], 'Motdepasse123', ['first_name' => 'Agent', 'last_name' => 'Terrain', 'department_id' => $trv]);
        $this->login('agent@kibarou.test', 'Motdepasse123');
        $body = $this->get('/directory/org')->body;
        $dgPos = strpos($body, 'id="service-' . $dg . '"');
        $trvPos = strpos($body, 'id="service-' . $trv . '"');
        $this->assertNotFalse($dgPos);
        $this->assertNotFalse($trvPos);
        $this->assertGreaterThan($dgPos, $trvPos);
        // Travaux est imbriqué : il apparaît avant la fermeture du bloc de la direction.
        $this->assertLessThan(strrpos($body, '</details>'), $trvPos);
        $section = substr($body, $trvPos, strpos($body, '</details>', $trvPos) - $trvPos);
        $this->assertSame(1, substr_count($section, 'Chef Travaux'), 'responsable affiché une seule fois');
        $this->assertStringContainsString('Agent Terrain', $section);
        $this->assertMatchesRegularExpression('/<details[^>]*id="service-' . $trv . '"[^>]*data-mine/', $body);
    }

    public function testOrgChartGroupsPeopleWithoutService(): void
    {
        $this->createUser('seul@kibarou.test', ['collaborateur'], 'Motdepasse123', ['first_name' => 'Sans', 'last_name' => 'Rattachement']);
        $this->login('seul@kibarou.test', 'Motdepasse123');
        $body = $this->get('/directory/org')->body;
        $pos = strpos($body, 'Sans service');
        $this->assertNotFalse($pos);
        $this->assertNotFalse(strpos($body, 'Sans Rattachement', $pos));
    }

    public function testEmptyLeafServiceIsHidden(): void
    {
        $empty = $this->department('Service fantôme');
        $this->createUser('collab@kibarou.test');
        $this->login('collab@kibarou.test', 'Motdepasse123');
        $r = $this->get('/directory/org');
        $this->assertSame(200, $r->status);
        $this->assertStringNotContainsString('id="service-' . $empty . '"', $r->body);
    }
}
