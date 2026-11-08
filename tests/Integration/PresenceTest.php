<?php

declare(strict_types=1);

namespace Tests\Integration;

final class PresenceTest extends IntegrationTestCase
{
    private function day(string $modifier): string
    {
        return (new \DateTimeImmutable($modifier, new \DateTimeZone((string) $this->app->config('app.timezone'))))->format('Y-m-d');
    }

    private function presence(int $userId, string $status, string $start, ?string $until = null, ?string $site = null): void
    {
        $this->app->db()->run('REPLACE INTO user_presence (user_id, status, site_label, start_date, until_date, updated_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())',
            [$userId, $status, $site, $start, $until]);
    }

    private function directoryCard(string $name): string
    {
        $body = $this->get('/directory')->body;
        // Chercher dans la liste, pas dans l'en-tête (qui affiche le nom de l'utilisateur connecté).
        $pos = strpos($body, $name, (int) strpos($body, 'id="kb-directory"'));
        $this->assertNotFalse($pos, "$name absent de l'annuaire");
        $end = strpos($body, '</li>', $pos);
        return substr($body, $pos, $end - $pos);
    }

    public function testSetOwnStatusShowsInDirectory(): void
    {
        $this->createUser('moi@kibarou.test', ['collaborateur'], 'Motdepasse123', ['first_name' => 'Moussa', 'last_name' => 'Traoré']);
        $this->login('moi@kibarou.test', 'Motdepasse123');
        $this->assertRedirect($this->post('/presence', ['status' => 'site', 'site_label' => 'Résidence Les Palmiers']), '/');
        $card = $this->directoryCard('Moussa Traoré');
        $this->assertStringContainsString('Sur chantier', $card);
        $this->assertStringContainsString('Résidence Les Palmiers', $card);
    }

    public function testSiteRequiresLabel(): void
    {
        $id = $this->createUser('moi@kibarou.test');
        $this->login('moi@kibarou.test', 'Motdepasse123');
        $this->post('/presence', ['status' => 'site', 'site_label' => '']);
        $this->assertNull($this->app->db()->value('SELECT status FROM user_presence WHERE user_id = ?', [$id]));
    }

    public function testUntilDateBounds(): void
    {
        $id = $this->createUser('moi@kibarou.test');
        $this->login('moi@kibarou.test', 'Motdepasse123');
        $this->post('/presence', ['status' => 'leave', 'until_date' => $this->day('-1 day')]);
        $this->post('/presence', ['status' => 'leave', 'until_date' => $this->day('+91 days')]);
        $this->assertNull($this->app->db()->value('SELECT status FROM user_presence WHERE user_id = ?', [$id]));
        $this->post('/presence', ['status' => 'leave', 'until_date' => $this->day('+90 days')]);
        $this->assertSame('leave', $this->app->db()->value('SELECT status FROM user_presence WHERE user_id = ?', [$id]));
    }

    public function testYesterdayStatusIsNotShown(): void
    {
        $id = $this->createUser('moi@kibarou.test', ['collaborateur'], 'Motdepasse123', ['first_name' => 'Hier', 'last_name' => 'Bureau']);
        $this->presence($id, 'office', $this->day('-1 day'));
        $this->login('moi@kibarou.test', 'Motdepasse123');
        $card = $this->directoryCard('Hier Bureau');
        $this->assertStringNotContainsString('Au bureau', $card);
        $this->assertStringContainsString('Statut non renseigné', $card);
    }

    public function testUntilDateKeepsStatus(): void
    {
        $id = $this->createUser('moi@kibarou.test', ['collaborateur'], 'Motdepasse123', ['first_name' => 'En', 'last_name' => 'Conge']);
        $this->presence($id, 'leave', $this->day('-1 day'), $this->day('+1 day'));
        $this->login('moi@kibarou.test', 'Motdepasse123');
        $this->assertStringContainsString('En congé', $this->directoryCard('En Conge'));
    }

    public function testOnlyAllowedPeopleCanEditOthers(): void
    {
        $head = $this->createUser('head@kibarou.test');
        $dept = $this->app->db()->insert('departments', ['name' => 'Travaux', 'manager_user_id' => $head, 'is_active' => 1, 'created_at' => now_utc(), 'updated_at' => now_utc()]);
        $target = $this->createUser('cible@kibarou.test', ['collaborateur'], 'Motdepasse123', ['department_id' => $dept]);
        $this->createUser('collegue@kibarou.test', ['collaborateur'], 'Motdepasse123', ['department_id' => $dept]);
        $this->createUser('rh@kibarou.test', ['admin']);
        $audit = fn () => (int) $this->app->db()->value("SELECT COUNT(*) FROM audit_logs WHERE action = 'presence.updated' AND entity_id = ?", [$target]);

        $this->login('collegue@kibarou.test', 'Motdepasse123');
        $this->assertSame(403, $this->post('/presence', ['status' => 'office', 'user_id' => (string) $target])->status);

        $this->cookies = [];
        $this->login('head@kibarou.test', 'Motdepasse123');
        $this->post('/presence', ['status' => 'site', 'site_label' => 'Pont', 'user_id' => (string) $target]);
        $this->assertSame('site', $this->app->db()->value('SELECT status FROM user_presence WHERE user_id = ?', [$target]));
        $this->assertSame(1, $audit());

        $this->cookies = [];
        $this->login('rh@kibarou.test', 'Motdepasse123');
        $this->post('/presence', ['status' => 'absent', 'user_id' => (string) $target]);
        $this->assertSame('absent', $this->app->db()->value('SELECT status FROM user_presence WHERE user_id = ?', [$target]));

        $this->cookies = [];
        $this->login('cible@kibarou.test', 'Motdepasse123');
        $this->post('/presence', ['status' => 'office']);
        $this->assertSame(2, $audit(), 'la personne elle-même n\'est pas journalisée');
        $this->post('/presence/clear');
        $this->assertNull($this->app->db()->value('SELECT status FROM user_presence WHERE user_id = ?', [$target]));
    }

    public function testOnlineBadge(): void
    {
        $this->createUser('moi@kibarou.test');
        $other = $this->createUser('autre@kibarou.test', ['collaborateur'], 'Motdepasse123', ['first_name' => 'Autre', 'last_name' => 'Personne']);
        $this->login('moi@kibarou.test', 'Motdepasse123');
        $session = ['ip_address' => '1.1.1.1', 'data' => '{}', 'created_at' => now_utc(), 'user_id' => $other];
        $this->app->db()->insert('sessions', [...$session, 'id' => str_repeat('a', 64), 'last_activity_at' => gmdate('Y-m-d H:i:s', time() - 600)]);
        $this->assertStringNotContainsString('en ligne', $this->directoryCard('Autre Personne'));
        $this->app->db()->insert('sessions', [...$session, 'id' => str_repeat('b', 64), 'last_activity_at' => now_utc()]);
        $card = $this->directoryCard('Autre Personne');
        $this->assertStringContainsString('en ligne', $card);
        $this->assertDoesNotMatchRegularExpression('/\d{1,2}[:h]\d{2}/', $card, 'aucune heure affichée');
    }

    public function testPresenceFormHasNoDuplicateIds(): void
    {
        $id = $this->createUser('moi@kibarou.test');
        $this->login('moi@kibarou.test', 'Motdepasse123');
        foreach (['/', '/profile', "/directory/$id"] as $path) {
            preg_match_all('/\sid="([^"]+)"/', $this->get($path)->body, $m);
            $dupes = array_keys(array_filter(array_count_values($m[1]), static fn ($n) => $n > 1));
            $this->assertSame([], $dupes, "ids en double sur $path");
        }
    }

    /** @return array{head:int,a:int,b:int,outsider:int} */
    private function crew(): array
    {
        $head = $this->createUser('chef@kibarou.test');
        $dept = $this->app->db()->insert('departments', ['name' => 'Travaux', 'manager_user_id' => $head, 'is_active' => 1, 'created_at' => now_utc(), 'updated_at' => now_utc()]);
        $a = $this->createUser('a@kibarou.test', ['collaborateur'], 'Motdepasse123', ['first_name' => 'Agent', 'last_name' => 'Alpha', 'department_id' => $dept]);
        $b = $this->createUser('b@kibarou.test', ['collaborateur'], 'Motdepasse123', ['first_name' => 'Agent', 'last_name' => 'Bravo', 'department_id' => $dept]);
        $outsider = $this->createUser('x@kibarou.test');
        return compact('head', 'a', 'b', 'outsider');
    }

    public function testTeamBlockListsMembers(): void
    {
        $this->crew();
        $this->login('chef@kibarou.test', 'Motdepasse123');
        $body = $this->get('/')->body;
        $this->assertStringContainsString('Mon équipe aujourd\'hui', $body);
        $this->assertStringContainsString('Agent Alpha', $body);
        $this->cookies = [];
        $this->login('x@kibarou.test', 'Motdepasse123');
        $this->assertStringNotContainsString('Mon équipe aujourd\'hui', $this->get('/')->body);
    }

    public function testMembersWithValidStatusAreUncheckedByDefault(): void
    {
        $c = $this->crew();
        $this->presence($c['a'], 'leave', $this->day('today'), $this->day('+1 day'));
        $this->login('chef@kibarou.test', 'Motdepasse123');
        $body = $this->get('/')->body;
        $this->assertMatchesRegularExpression('/name="user_ids\[\]" value="' . $c['a'] . '"(?![^>]*checked)[^>]*>/', $body);
        $this->assertMatchesRegularExpression('/name="user_ids\[\]" value="' . $c['b'] . '"[^>]*checked/', $body);
        $this->assertStringContainsString('En congé', $body);
    }

    public function testBulkUpdate(): void
    {
        $c = $this->crew();
        $this->login('chef@kibarou.test', 'Motdepasse123');
        $this->assertRedirect($this->post('/presence/team', [
            'status' => 'site', 'site_label' => 'Résidence X', 'user_ids' => [(string) $c['a'], (string) $c['b']],
        ]), '/');
        $this->assertSame(2, (int) $this->app->db()->value("SELECT COUNT(*) FROM user_presence WHERE status = 'site' AND site_label = 'Résidence X'"));
        $this->assertSame(2, (int) $this->app->db()->value("SELECT COUNT(*) FROM audit_logs WHERE action = 'presence.updated'"));
    }

    public function testBulkRejectsForeignIds(): void
    {
        $c = $this->crew();
        $this->login('chef@kibarou.test', 'Motdepasse123');
        $r = $this->post('/presence/team', ['status' => 'office', 'user_ids' => [(string) $c['a'], (string) $c['outsider']]]);
        $this->assertSame(403, $r->status);
        $this->assertSame(0, (int) $this->app->db()->value('SELECT COUNT(*) FROM user_presence'));
    }

    public function testOfficeIgnoresHiddenDetails(): void
    {
        $id = $this->createUser('moi@kibarou.test');
        $this->login('moi@kibarou.test', 'Motdepasse123');
        $this->post('/presence', ['status' => 'office', 'until_date' => $this->day('+3 days'), 'note' => 'reste du chantier']);
        $row = $this->app->db()->fetch('SELECT until_date, note FROM user_presence WHERE user_id = ?', [$id]);
        $this->assertSame(['until_date' => null, 'note' => null], $row);
    }
}
