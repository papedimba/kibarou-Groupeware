<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\HttpException;
use App\Modules\Calendar\EventRepository;
use App\Modules\Calendar\EventService;

final class EventServiceTest extends IntegrationTestCase
{
    private function u(int $id): array
    {
        return $this->app->db()->fetch('SELECT * FROM users WHERE id = ?', [$id]);
    }

    /** @return array<string,mixed> */
    private function input(array $over = []): array
    {
        $day = (new \DateTimeImmutable('+3 days'))->format('Y-m-d');
        return $over + ['title' => 'Visite chantier', 'all_day' => '0', 'start' => "$day 09:00", 'end' => "$day 10:30",
            'location' => 'Pont de Sotuba', 'description' => 'Contrôle du ferraillage (confidentiel)', 'is_private' => '0',
            'reminder' => '15', 'participants' => [], 'departments' => []];
    }

    private function notifications(string $type): array
    {
        return $this->app->db()->fetchAll('SELECT user_id, title, body FROM notifications WHERE type = ? ORDER BY user_id', [$type]);
    }

    public function testCreateInviteNotifies(): void
    {
        $dept = $this->app->db()->insert('departments', ['name' => 'Travaux', 'is_active' => 1, 'created_at' => now_utc(), 'updated_at' => now_utc()]);
        $a = $this->createUser('a@kibarou.test');
        $b = $this->createUser('b@kibarou.test');
        $c = $this->createUser('c@kibarou.test', ['collaborateur'], 'Motdepasse123', ['department_id' => $dept]);
        $d = $this->createUser('d@kibarou.test', ['collaborateur'], 'Motdepasse123', ['department_id' => $dept]);
        $id = $this->app->events()->create($this->u($a), $this->input(['participants' => [(string) $b], 'departments' => [(string) $dept]]));
        $rows = $this->app->db()->fetchAll('SELECT user_id, response FROM event_participants WHERE event_id = ? ORDER BY user_id', [$id]);
        $this->assertSame([[$a, 'yes'], [$b, 'pending'], [$c, 'pending'], [$d, 'pending']], array_map(static fn ($r) => [(int) $r['user_id'], $r['response']], $rows));
        $sent = $this->notifications('calendar.invited');
        $this->assertSame([$b, $c, $d], array_map('intval', array_column($sent, 'user_id')));
        $this->assertStringNotContainsString('confidentiel', json_encode($sent, JSON_UNESCAPED_UNICODE));
    }

    public function testOnlyOrganizerEdits(): void
    {
        $a = $this->createUser('a@kibarou.test');
        $b = $this->createUser('b@kibarou.test');
        $id = $this->app->events()->create($this->u($a), $this->input(['participants' => [(string) $b]]));
        foreach ([fn () => $this->app->events()->update($this->u($b), $id, $this->input(['title' => 'Pris'])), fn () => $this->app->events()->cancel($this->u($b), $id)] as $fn) {
            try {
                $fn();
                $this->fail('Modification acceptée à tort');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->status);
            }
        }
        $this->app->events()->respond($this->u($b), $id, 'no');
        $this->assertSame([$a], array_map('intval', array_column($this->notifications('calendar.response'), 'user_id')));
    }

    public function testTimeChangeResetsResponsesAndNotifies(): void
    {
        $a = $this->createUser('a@kibarou.test');
        $b = $this->createUser('b@kibarou.test');
        $id = $this->app->events()->create($this->u($a), $this->input(['participants' => [(string) $b]]));
        $this->app->events()->respond($this->u($b), $id, 'yes');
        $this->app->events()->update($this->u($a), $id, $this->input(['participants' => [(string) $b], 'description' => 'autre']));
        $this->assertSame([], $this->notifications('calendar.updated'));
        $day = (new \DateTimeImmutable('+4 days'))->format('Y-m-d');
        $this->app->events()->update($this->u($a), $id, $this->input(['participants' => [(string) $b], 'start' => "$day 14:00", 'end' => "$day 15:00"]));
        $this->assertSame('pending', $this->app->db()->value('SELECT response FROM event_participants WHERE event_id = ? AND user_id = ?', [$id, $b]));
        $this->assertSame([$b], array_map('intval', array_column($this->notifications('calendar.updated'), 'user_id')));
    }

    public function testLocalTimeRoundTrip(): void
    {
        $svc = new EventService($this->app->db(), new EventRepository($this->app->db()), $this->app->rbac(), $this->app->audit(), $this->app->notifier(), 'Europe/Paris');
        $this->assertSame('2026-03-30 07:00:00', $svc->toUtc('2026-03-30 09:00'));
        $this->assertSame('2026-03-27 08:00:00', $svc->toUtc('2026-03-27 09:00'));
        $this->assertSame('09:00', $svc->toLocal('2026-03-30 07:00:00')->format('H:i'));
    }
}
