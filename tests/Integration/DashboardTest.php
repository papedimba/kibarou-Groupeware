<?php

declare(strict_types=1);

namespace Tests\Integration;

final class DashboardTest extends IntegrationTestCase
{
    public function testDashboardShowsMyInformation(): void
    {
        $db = $this->app->db();
        $dept = $db->insert('departments', ['name' => 'Travaux', 'is_active' => 1, 'created_at' => now_utc(), 'updated_at' => now_utc()]);
        $manager = $this->createUser('awa@kibarou.test', ['directeur'], 'Motdepasse123', ['first_name' => 'Awa', 'last_name' => 'Diallo']);
        $this->createUser('chef@kibarou.test', ['collaborateur'], 'Motdepasse123', ['department_id' => $dept, 'manager_id' => $manager]);
        $this->login('chef@kibarou.test', 'Motdepasse123');
        $body = $this->get('/')->body;
        $this->assertStringContainsString('Travaux', $body);
        $this->assertStringContainsString('Awa Diallo', $body);
    }

    public function testMissingInformationIsLabelled(): void
    {
        $this->createUser('seul@kibarou.test');
        $this->login('seul@kibarou.test', 'Motdepasse123');
        $this->assertStringContainsString('Non renseigné', $this->get('/')->body);
    }

    public function testDashboardShowsAdminContact(): void
    {
        $this->createUser('rh@kibarou.test', ['admin']);
        $this->createUser('collab@kibarou.test');
        $this->login('collab@kibarou.test', 'Motdepasse123');
        $this->assertStringContainsString('rh@kibarou.test', $this->get('/')->body);
    }

    public function testNavHasNoComingSoonLinks(): void
    {
        $this->createUser('collab@kibarou.test');
        $this->login('collab@kibarou.test', 'Motdepasse123');
        $body = $this->get('/')->body;
        $this->assertStringNotContainsString('aria-disabled="true"', $body);
        $this->assertSame(1, substr_count($body, 'Bientôt'));
    }

    public function testForbiddenStaysInsideApp(): void
    {
        $this->createUser('rh@kibarou.test', ['admin']);
        $this->createUser('dir@kibarou.test', ['directeur']);
        $this->login('dir@kibarou.test', 'Motdepasse123');
        $r = $this->get('/admin');
        $this->assertSame(403, $r->status);
        $this->assertStringContainsString('class="kb-sidebar"', $r->body);
        $this->assertStringContainsString('rh@kibarou.test', $r->body);
    }

    public function testAdminDashboardShortcuts(): void
    {
        $this->createUser('rh@kibarou.test', ['admin']);
        $this->login('rh@kibarou.test', 'Motdepasse123');
        $body = $this->get('/')->body;
        $this->assertStringContainsString('Nouvel utilisateur', $body);
        $this->assertStringContainsString('Journal d\'audit', $body);
    }
}
