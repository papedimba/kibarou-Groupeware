<?php

declare(strict_types=1);

namespace Tests\Integration;

final class AvailabilityTest extends IntegrationTestCase
{
    private function u(int $id): array
    {
        return $this->app->db()->fetch('SELECT * FROM users WHERE id = ?', [$id]);
    }

    private function day(int $offset): string
    {
        return (new \DateTimeImmutable("today +$offset days"))->format('Y-m-d');
    }

    private function create(int $organizer, string $title, string $start, string $end, array $extra = []): int
    {
        return $this->app->events()->create($this->u($organizer), $extra + ['title' => $title, 'start' => $start, 'end' => $end, 'location' => 'Lieu secret', 'reminder' => '']);
    }

    public function testBusyWithoutDetails(): void
    {
        $this->createUser('a@kibarou.test');
        $b = $this->createUser('b@kibarou.test');
        $c = $this->createUser('c@kibarou.test');
        $d = $this->day(1);
        $this->create($b, 'Rendez-vous banque', "$d 09:00", "$d 10:00");
        $this->create($b, 'Médecin', "$d 14:00", "$d 15:00", ['is_private' => '1']);
        $declined = $this->create($c, 'Réunion refusée', "$d 11:00", "$d 12:00", ['participants' => [(string) $b]]);
        $this->app->events()->respond($this->u($b), $declined, 'no');
        $cancelled = $this->create($b, 'Annulé', "$d 16:00", "$d 17:00");
        $this->app->events()->cancel($this->u($b), $cancelled);
        $this->login('a@kibarou.test', 'Motdepasse123');
        $body = $this->get('/calendar/availability', ['users' => [(string) $b], 'date' => $d])->body;
        $this->assertStringContainsString('Occupé 09:00–10:00', $body);
        $this->assertSame(1, substr_count($body, 'Occupé '));
        foreach (['Rendez-vous banque', 'Lieu secret', 'Médecin', 'Réunion refusée', 'Annulé'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $body);
        }
    }

    public function testPresenceShown(): void
    {
        $this->createUser('a@kibarou.test');
        $b = $this->createUser('b@kibarou.test');
        $c = $this->createUser('c@kibarou.test');
        $db = $this->app->db();
        $db->insert('user_presence', ['user_id' => $b, 'status' => 'leave', 'start_date' => $this->day(0), 'until_date' => $this->day(2), 'set_by' => $b, 'updated_at' => now_utc()]);
        $db->insert('user_presence', ['user_id' => $c, 'status' => 'site', 'site_label' => 'Pont de Sotuba', 'start_date' => $this->day(0), 'until_date' => null, 'set_by' => $c, 'updated_at' => now_utc()]);
        $this->login('a@kibarou.test', 'Motdepasse123');
        $body = $this->get('/calendar/availability', ['users' => [(string) $b, (string) $c], 'date' => $this->day(0)])->body;
        $this->assertSame(3, substr_count($body, 'kb-avail-off'), 'congé du jour et des deux suivants');
        $this->assertStringContainsString('Sur chantier', $body);
    }

    public function testAtMostTenPeople(): void
    {
        $this->createUser('a@kibarou.test');
        $ids = [];
        for ($i = 0; $i < 11; $i++) {
            $ids[] = (string) $this->createUser("p$i@kibarou.test");
        }
        $this->login('a@kibarou.test', 'Motdepasse123');
        $this->assertStringContainsString('Dix personnes au plus.', $this->get('/calendar/availability', ['users' => $ids])->body);
    }
}
