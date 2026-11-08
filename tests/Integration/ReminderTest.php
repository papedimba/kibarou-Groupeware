<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Modules\Calendar\ReminderService;
use DateTimeImmutable;
use DateTimeZone;

final class ReminderTest extends IntegrationTestCase
{
    private function u(int $id): array
    {
        return $this->app->db()->fetch('SELECT * FROM users WHERE id = ?', [$id]);
    }

    private function reminders(): ReminderService
    {
        return new ReminderService($this->app->db(), $this->app->notifier(), 'UTC');
    }

    private function utc(string $s): DateTimeImmutable
    {
        return new DateTimeImmutable($s, new DateTimeZone('UTC'));
    }

    /** @return list<int> destinataires des rappels */
    private function sentTo(): array
    {
        return array_map('intval', array_column($this->app->db()->fetchAll("SELECT user_id FROM notifications WHERE type = 'calendar.reminder' ORDER BY user_id"), 'user_id'));
    }

    /** Évènement de A (participants $others) le 2030-06-10 de 10:00 à 11:00 UTC. */
    private function event(int $a, array $others, string $reminder = '15'): int
    {
        return $this->app->events()->create($this->u($a), ['title' => 'Coulage dalle', 'start' => '2030-06-10 10:00', 'end' => '2030-06-10 11:00',
            'reminder' => $reminder, 'participants' => array_map('strval', $others)]);
    }

    public function testSendsOnceInWindow(): void
    {
        $a = $this->createUser('a@kibarou.test');
        $b = $this->createUser('b@kibarou.test');
        $c = $this->createUser('c@kibarou.test');
        $id = $this->event($a, [$b, $c]);
        $this->app->events()->respond($this->u($c), $id, 'no');
        $this->assertSame(0, $this->reminders()->run($this->utc('2030-06-10 09:44')));
        $this->assertSame(2, $this->reminders()->run($this->utc('2030-06-10 09:50')));
        $this->assertSame([$a, $b], $this->sentTo());
        $this->assertSame(0, $this->reminders()->run($this->utc('2030-06-10 09:55')));
        $this->assertStringContainsString('Rappel : Coulage dalle à 10:00', (string) $this->app->db()->value("SELECT title FROM notifications WHERE type = 'calendar.reminder' LIMIT 1"));
        $silent = $this->event($a, [$b], '');
        $this->assertSame(0, $this->reminders()->run($this->utc('2030-06-10 09:58')) - 0);
        $this->assertSame(0, (int) $this->app->db()->value('SELECT COUNT(*) FROM event_participants WHERE event_id = ? AND reminded_at IS NOT NULL', [$silent]));
    }

    public function testNeverAfterStart(): void
    {
        $a = $this->createUser('a@kibarou.test');
        $this->event($a, []);
        $this->assertSame(0, $this->reminders()->run($this->utc('2030-06-10 10:01')));
    }

    public function testAllDayTimes(): void
    {
        $a = $this->createUser('a@kibarou.test');
        $b = $this->createUser('b@kibarou.test');
        $id = $this->app->events()->create($this->u($a), ['title' => 'Inventaire', 'all_day' => '1', 'start' => '2030-06-12', 'end' => '2030-06-12',
            'reminder' => '1440', 'participants' => [(string) $b]]);
        $this->app->events()->setReminder($this->u($b), $id, 15);
        $this->assertSame(0, $this->reminders()->run($this->utc('2030-06-11 17:55')));
        $this->assertSame(1, $this->reminders()->run($this->utc('2030-06-11 18:00')));
        $this->assertSame([$a], $this->sentTo());
        $this->assertSame(0, $this->reminders()->run($this->utc('2030-06-12 06:55')));
        $this->assertSame(1, $this->reminders()->run($this->utc('2030-06-12 07:00')));
        $this->assertSame([$a, $b], $this->sentTo());
        $this->assertStringContainsString('demain', (string) $this->app->db()->value("SELECT title FROM notifications WHERE type = 'calendar.reminder' AND user_id = ?", [$a]));
    }

    public function testPersonalReminderSurvivesDefaultChange(): void
    {
        $a = $this->createUser('a@kibarou.test');
        $b = $this->createUser('b@kibarou.test');
        $c = $this->createUser('c@kibarou.test');
        $id = $this->event($a, [$b, $c]);
        $this->app->events()->setReminder($this->u($b), $id, 60);
        $this->app->events()->update($this->u($a), $id, ['title' => 'Coulage dalle', 'start' => '2030-06-10 10:00', 'end' => '2030-06-10 11:00',
            'reminder' => '1440', 'participants' => [(string) $b, (string) $c]]);
        $minutes = fn (int $u) => (int) $this->app->db()->value('SELECT reminder_minutes FROM event_participants WHERE event_id = ? AND user_id = ?', [$id, $u]);
        $this->assertSame(60, $minutes($b));
        $this->assertSame(1440, $minutes($c));
    }

    public function testTimeChangeReArms(): void
    {
        $a = $this->createUser('a@kibarou.test');
        $id = $this->event($a, []);
        $this->assertSame(1, $this->reminders()->run($this->utc('2030-06-10 09:50')));
        $this->app->events()->update($this->u($a), $id, ['title' => 'Coulage dalle', 'start' => '2030-06-11 10:00', 'end' => '2030-06-11 11:00', 'reminder' => '15']);
        $this->assertSame(1, $this->reminders()->run($this->utc('2030-06-11 09:50')));
    }
}
