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
}
