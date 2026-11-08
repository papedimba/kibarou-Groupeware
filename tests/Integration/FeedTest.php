<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Modules\Calendar\IcsWriter;

final class FeedTest extends IntegrationTestCase
{
    private function u(int $id): array
    {
        return $this->app->db()->fetch('SELECT * FROM users WHERE id = ?', [$id]);
    }

    private function make(int $user, string $title, string $startOffset, array $extra = []): int
    {
        $d = new \DateTimeImmutable($startOffset);
        return $this->app->events()->create($this->u($user), $extra + ['title' => $title, 'start' => $d->format('Y-m-d 09:00'), 'end' => $d->format('Y-m-d 10:00'), 'reminder' => '']);
    }

    /** Génère le lien et renvoie le chemin du flux (montré une seule fois). */
    private function feedPath(): string
    {
        $this->post('/profile/calendar/feed');
        $page = $this->get('/profile/calendar')->body;
        $this->assertMatchesRegularExpression('#/calendar/feed/([a-f0-9]{64})\.ics#', $page);
        preg_match('#/calendar/feed/([a-f0-9]{64})\.ics#', $page, $m);
        $this->assertDoesNotMatchRegularExpression('#/calendar/feed/[a-f0-9]{64}\.ics#', $this->get('/profile/calendar')->body, 'montré une seule fois');
        return '/calendar/feed/' . $m[1] . '.ics';
    }

    public function testFeedContainsOnlyMyEvents(): void
    {
        $a = $this->createUser('a@kibarou.test');
        $b = $this->createUser('b@kibarou.test');
        $this->make($a, 'Mon chantier', '+2 days');
        $this->make($b, 'Agenda de B', '+2 days');
        $declined = $this->make($b, 'Refusé par A', '+3 days', ['participants' => [(string) $a]]);
        $this->app->events()->respond($this->u($a), $declined, 'no');
        $this->make($a, 'Trop ancien', '-40 days');
        $this->login('a@kibarou.test', 'Motdepasse123');
        $path = $this->feedPath();
        $this->cookies = [];
        $r = $this->get($path);
        $this->assertSame(200, $r->status);
        $this->assertStringStartsWith('text/calendar', $r->headers['Content-Type']);
        $this->assertStringContainsString('SUMMARY:Mon chantier', $r->body);
        foreach (['Agenda de B', 'Refusé par A', 'Trop ancien'] as $absent) {
            $this->assertStringNotContainsString($absent, $r->body);
        }
    }

    public function testBadAndOldTokens(): void
    {
        $this->createUser('a@kibarou.test');
        $this->login('a@kibarou.test', 'Motdepasse123');
        $old = $this->feedPath();
        $new = $this->feedPath();
        $this->cookies = [];
        $this->assertSame(404, $this->get('/calendar/feed/' . str_repeat('a', 64) . '.ics')->status);
        $this->assertSame(404, $this->get($old)->status);
        $this->assertSame(200, $this->get($new)->status);
        $this->assertSame(2, (int) $this->app->db()->value("SELECT COUNT(*) FROM audit_logs WHERE action = 'calendar.feed_generated'"));
    }

    public function testRateLimit(): void
    {
        $this->createUser('a@kibarou.test');
        $this->login('a@kibarou.test', 'Motdepasse123');
        $path = $this->feedPath();
        preg_match('#([a-f0-9]{64})#', $path, $m);
        for ($i = 0; $i < 60; $i++) {
            $this->app->db()->insert('calendar_feed_hits', ['token_hash' => hash('sha256', $m[1]), 'hit_at' => now_utc()]);
        }
        $this->assertSame(429, $this->get($path)->status);
    }

    public function testIcsFormatAndEscaping(): void
    {
        $ics = (new IcsWriter())->calendar([
            ['id' => 7, 'title' => 'Visite ; chantier, lot 3', 'location' => null, 'description' => "Ligne 1\nLigne 2", 'all_day' => 0,
                'start_at' => '2030-06-10 07:00:00', 'end_at' => '2030-06-10 08:00:00', 'start_date' => null, 'end_date' => null, 'status' => 'confirmed', 'updated_at' => '2030-01-01 00:00:00'],
            ['id' => 8, 'title' => str_repeat('Très long titre ', 10), 'location' => 'Dépôt', 'description' => null, 'all_day' => 1,
                'start_at' => null, 'end_at' => null, 'start_date' => '2030-06-12', 'end_date' => '2030-06-12', 'status' => 'cancelled', 'updated_at' => '2030-01-01 00:00:00'],
        ], 'gw.kibarouconstruction.com');
        $this->assertStringContainsString("SUMMARY:Visite \; chantier\\, lot 3\r\n", $ics);
        $this->assertStringContainsString("DESCRIPTION:Ligne 1\\nLigne 2\r\n", $ics);
        $this->assertStringContainsString("DTSTART:20300610T070000Z\r\n", $ics);
        $this->assertStringContainsString("DTSTART;VALUE=DATE:20300612\r\nDTEND;VALUE=DATE:20300613\r\n", $ics);
        $this->assertStringContainsString("UID:event-7@gw.kibarouconstruction.com\r\n", $ics);
        $this->assertStringContainsString("STATUS:CANCELLED\r\n", $ics);
        foreach (explode("\r\n", $ics) as $line) {
            $this->assertLessThanOrEqual(75, strlen($line), $line);
        }
    }

    public function testEventIcsForParticipantsOnly(): void
    {
        $a = $this->createUser('a@kibarou.test');
        $this->createUser('c@kibarou.test');
        $id = $this->make($a, 'Privé à A', '+1 day');
        $this->login('a@kibarou.test', 'Motdepasse123');
        $this->assertStringContainsString('SUMMARY:Privé à A', $this->get("/calendar/events/$id.ics")->body);
        $this->cookies = [];
        $this->login('c@kibarou.test', 'Motdepasse123');
        $this->assertSame(404, $this->get("/calendar/events/$id.ics")->status);
    }

    public function testDisabledUserFeedStops(): void
    {
        $a = $this->createUser('a@kibarou.test');
        $this->login('a@kibarou.test', 'Motdepasse123');
        $path = $this->feedPath();
        $this->app->db()->update('users', ['status' => 'disabled'], ['id' => $a]);
        $this->cookies = [];
        $this->assertSame(404, $this->get($path)->status);
    }
}
