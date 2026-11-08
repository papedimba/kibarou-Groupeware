<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\ValidationException;
use App\Modules\Calendar\SeriesService;
use DateTimeImmutable;

final class SeriesTest extends IntegrationTestCase
{
    private function u(int $id): array
    {
        return $this->app->db()->fetch('SELECT * FROM users WHERE id = ?', [$id]);
    }

    /** Lundi prochain, 08:00. */
    private function monday(): DateTimeImmutable
    {
        return new DateTimeImmutable('monday next week 08:00');
    }

    /** Série hebdomadaire du lundi, 8 semaines, A organisateur, B invité. @return array{int,int,list<int>} */
    private function weekly(): array
    {
        $a = $this->createUser('a@kibarou.test');
        $b = $this->createUser('b@kibarou.test');
        $m = $this->monday();
        $first = $this->app->events()->create($this->u($a), ['title' => 'Réunion de chantier', 'start' => $m->format('Y-m-d H:i'),
            'end' => $m->modify('+1 hour')->format('Y-m-d H:i'), 'participants' => [(string) $b], 'reminder' => '15',
            'repeat' => 'weekly', 'weekdays' => ['1'], 'until' => $m->modify('+7 weeks')->format('Y-m-d')]);
        $sid = (int) $this->app->db()->value('SELECT series_id FROM events WHERE id = ?', [$first]);
        $ids = array_map('intval', array_column($this->app->db()->fetchAll('SELECT id FROM events WHERE series_id = ? ORDER BY start_at', [$sid]), 'id'));
        return [$a, $b, $ids];
    }

    private function event(int $id): array
    {
        return $this->app->db()->fetch('SELECT * FROM events WHERE id = ?', [$id]);
    }

    public function testWeeklyMonthlyWeekdaysGeneration(): void
    {
        $s = new SeriesService();
        $mon = new DateTimeImmutable('2027-01-04 09:00');
        $this->assertCount(8, $s->occurrences('weekly', [1, 4], $mon, new DateTimeImmutable('2027-01-31')));
        $months = array_map(static fn ($d) => $d->format('Y-m-d'), $s->occurrences('monthly', [], new DateTimeImmutable('2027-01-31 09:00'), new DateTimeImmutable('2027-05-31')));
        $this->assertSame(['2027-01-31', '2027-03-31', '2027-05-31'], $months);
        $this->assertCount(5, $s->occurrences('weekdays', [], $mon, new DateTimeImmutable('2027-01-10')));
        foreach ([['weekly', [1], '2028-02-10'], ['weekdays', [], '2028-01-03']] as [$kind, $days, $until]) {
            try {
                $s->occurrences($kind, $days, $mon, new DateTimeImmutable($until));
                $this->fail("Série trop longue acceptée : $kind");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('until', $e->errors);
            }
        }
    }

    public function testOneOccurrenceOnly(): void
    {
        [$a, , $ids] = $this->weekly();
        $this->assertCount(8, $ids);
        $e = $this->event($ids[2]);
        $this->app->events()->update($this->u($a), $ids[2], ['title' => 'Réunion déplacée', 'start' => $this->app->events()->toLocal($e['start_at'])->format('Y-m-d H:i'),
            'end' => $this->app->events()->toLocal($e['end_at'])->format('Y-m-d H:i'), 'reminder' => '15'], 'one');
        $this->assertSame('Réunion déplacée', $this->event($ids[2])['title']);
        $this->assertSame(1, (int) $this->event($ids[2])['detached']);
        $this->assertSame('Réunion de chantier', $this->event($ids[3])['title']);
    }

    public function testFollowingKeepsDetachedPastAndAnswers(): void
    {
        [$a, $b, $ids] = $this->weekly();
        $this->app->events()->respond($this->u($b), $ids[0], 'yes', 'series');
        $e5 = $this->event($ids[5]);
        $this->app->events()->update($this->u($a), $ids[5], ['title' => 'Point détaché', 'location' => 'Ailleurs', 'start' => $this->app->events()->toLocal($e5['start_at'])->format('Y-m-d H:i'),
            'end' => $this->app->events()->toLocal($e5['end_at'])->format('Y-m-d H:i'), 'reminder' => '15', 'participants' => [(string) $b]], 'one');
        $this->app->db()->run('DELETE FROM notifications');
        $e3 = $this->event($ids[3]);
        $this->app->events()->update($this->u($a), $ids[3], ['title' => 'Réunion de chantier', 'location' => 'Base vie', 'start' => $this->app->events()->toLocal($e3['start_at'])->format('Y-m-d H:i'),
            'end' => $this->app->events()->toLocal($e3['end_at'])->format('Y-m-d H:i'), 'reminder' => '15', 'participants' => [(string) $b]], 'following');
        $loc = fn (int $i) => $this->event($ids[$i])['location'];
        $this->assertSame([null, null, null, 'Base vie', 'Base vie', 'Ailleurs', 'Base vie', 'Base vie'], array_map($loc, range(0, 7)));
        $this->assertSame('yes', $this->app->db()->value('SELECT response FROM event_participants WHERE event_id = ? AND user_id = ?', [$ids[7], $b]));
        $this->assertSame(1, (int) $this->app->db()->value("SELECT COUNT(*) FROM notifications WHERE type = 'calendar.updated' AND user_id = ?", [$b]));
    }

    public function testFollowingTimeChangeResetsAnswers(): void
    {
        [$a, $b, $ids] = $this->weekly();
        $this->app->events()->respond($this->u($b), $ids[0], 'yes', 'series');
        $e = $this->event($ids[1]);
        $start = $this->app->events()->toLocal($e['start_at'])->setTime(10, 0);
        $this->app->events()->update($this->u($a), $ids[1], ['title' => 'Réunion de chantier', 'start' => $start->format('Y-m-d H:i'),
            'end' => $start->modify('+1 hour')->format('Y-m-d H:i'), 'reminder' => '15', 'participants' => [(string) $b]], 'following');
        $resp = fn (int $i) => $this->app->db()->value('SELECT response FROM event_participants WHERE event_id = ? AND user_id = ?', [$ids[$i], $b]);
        $this->assertSame('yes', $resp(0));
        $this->assertSame('pending', $resp(1));
        $this->assertSame('pending', $resp(7));
        $this->assertSame('10:00', $this->app->events()->toLocal($this->event($ids[7])['start_at'])->format('H:i'));
        $this->assertSame('08:00', $this->app->events()->toLocal($this->event($ids[0])['start_at'])->format('H:i'));
    }

    public function testCancelFollowingIncludesDetached(): void
    {
        [$a, , $ids] = $this->weekly();
        $e5 = $this->event($ids[5]);
        $this->app->events()->update($this->u($a), $ids[5], ['title' => 'Détaché', 'start' => $this->app->events()->toLocal($e5['start_at'])->format('Y-m-d H:i'),
            'end' => $this->app->events()->toLocal($e5['end_at'])->format('Y-m-d H:i'), 'reminder' => '15'], 'one');
        $this->app->events()->cancel($this->u($a), $ids[3], 'following');
        $status = fn (int $i) => $this->event($ids[$i])['status'];
        $this->assertSame(['confirmed', 'confirmed', 'confirmed', 'cancelled', 'cancelled', 'cancelled', 'cancelled', 'cancelled'], array_map($status, range(0, 7)));
    }

    public function testRespondWholeSeriesOrOne(): void
    {
        [, $b, $ids] = $this->weekly();
        $this->app->events()->respond($this->u($b), $ids[0], 'maybe', 'series');
        $this->app->events()->respond($this->u($b), $ids[4], 'no', 'one');
        $resp = fn (int $i) => $this->app->db()->value('SELECT response FROM event_participants WHERE event_id = ? AND user_id = ?', [$ids[$i], $b]);
        $this->assertSame(['maybe', 'maybe', 'maybe', 'maybe', 'no', 'maybe', 'maybe', 'maybe'], array_map($resp, range(0, 7)));
    }

    public function testExtendAndShortenSeries(): void
    {
        [$a, $b, $ids] = $this->weekly();
        $this->app->events()->respond($this->u($b), $ids[0], 'yes', 'series');
        $e = $this->event($ids[6]);
        $local = fn (string $utc) => $this->app->events()->toLocal($utc)->format('Y-m-d H:i');
        $input = ['title' => 'Réunion de chantier', 'start' => $local($e['start_at']), 'end' => $local($e['end_at']), 'reminder' => '15', 'participants' => [(string) $b]];
        $sid = (int) $e['series_id'];
        $count = fn () => (int) $this->app->db()->value("SELECT COUNT(*) FROM events WHERE series_id = ? AND status = 'confirmed'", [$sid]);
        $this->app->events()->update($this->u($a), $ids[6], $input + ['until' => $this->monday()->modify('+11 weeks')->format('Y-m-d')], 'following');
        $this->assertSame(12, $count());
        $last = (int) $this->app->db()->value('SELECT id FROM events WHERE series_id = ? ORDER BY start_at DESC LIMIT 1', [$sid]);
        $this->assertSame('yes', $this->app->db()->value('SELECT response FROM event_participants WHERE event_id = ? AND user_id = ?', [$last, $b]));
        $this->app->events()->update($this->u($a), $ids[6], $input + ['until' => $this->monday()->modify('+6 weeks')->format('Y-m-d')], 'following');
        $this->assertSame(7, $count());
        $this->assertSame($this->monday()->modify('+6 weeks')->format('Y-m-d'), $this->app->db()->value('SELECT until_date FROM calendar_series WHERE id = ?', [$sid]));
    }
}
