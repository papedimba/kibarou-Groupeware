<?php

declare(strict_types=1);

namespace App\Modules\Calendar;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use DateTimeImmutable;

/** Agenda personnel : semaine, mois, liste (seulement mes évènements). */
final class CalendarController extends Controller
{
    private const FIRST_HOUR = 7;
    private const LAST_HOUR = 20;

    public function index(Request $request): Response
    {
        $view = in_array($request->str('view'), ['week', 'month', 'list'], true) ? $request->str('view') : 'week';
        $tz = $this->app->events()->timezone();
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $request->str('date'), $tz) ?: new DateTimeImmutable('today', $tz);
        [$from, $to] = match ($view) {
            'week' => [$date->modify('monday this week'), $date->modify('monday this week')->modify('+7 days')],
            'month' => [$date->modify('first day of this month')->modify('monday this week'),
                $date->modify('last day of this month')->modify('sunday this week')->modify('+1 day')],
            default => [$date, $date->modify('+60 days')],
        };
        $events = (new EventRepository($this->app->db()))->forUser(
            (int) $this->user()['id'],
            $this->app->events()->toUtc($from->format('Y-m-d 00:00')),
            $this->app->events()->toUtc($to->format('Y-m-d 00:00')),
            $from->format('Y-m-d'),
            $to->modify('-1 day')->format('Y-m-d'),
        );
        $days = [];
        for ($d = $from; $d < $to; $d = $d->modify('+1 day')) {
            $days[$d->format('Y-m-d')] = ['date' => $d, 'allday' => [], 'timed' => []];
        }
        foreach ($events as $e) {
            $this->place($e, $days);
        }
        foreach ($days as &$day) {
            $day['timed'] = $this->lanes($day['timed']);
        }
        unset($day);
        $step = ['week' => '+1 week', 'month' => '+1 month', 'list' => '+60 days'][$view];
        $back = ['week' => '-1 week', 'month' => '-1 month', 'list' => '-60 days'][$view];
        return $this->view('calendar/index', [
            'currentPath' => '/calendar', 'view' => $view, 'date' => $date, 'days' => $days,
            'prev' => $date->modify($back)->format('Y-m-d'), 'next' => $date->modify($step)->format('Y-m-d'),
            'firstHour' => self::FIRST_HOUR, 'lastHour' => self::LAST_HOUR,
        ]);
    }

    /** Range l'évènement dans chacun des jours qu'il touche. @param array<string,mixed> $e @param array<string,array<string,mixed>> $days */
    private function place(array $e, array &$days): void
    {
        $svc = $this->app->events();
        if ((int) $e['all_day'] === 1) {
            foreach ($days as $key => &$day) {
                if ($key >= $e['start_date'] && $key <= $e['end_date']) {
                    $day['allday'][] = $e;
                }
            }
            return;
        }
        $start = $svc->toLocal((string) $e['start_at']);
        $end = $svc->toLocal((string) $e['end_at']);
        foreach ($days as $key => &$day) {
            $dayStart = $day['date'];
            $dayEnd = $dayStart->modify('+1 day');
            if ($start >= $dayEnd || $end <= $dayStart) {
                continue;
            }
            // Position dans la grille 7 h – 20 h, par quarts d'heure (classes CSS, pas de style en ligne).
            $from = max($start, $dayStart->setTime(self::FIRST_HOUR, 0));
            $to = min($end, $dayStart->setTime(self::LAST_HOUR, 0));
            $top = max(0, intdiv(($from->getTimestamp() - $dayStart->setTime(self::FIRST_HOUR, 0)->getTimestamp()) / 60, 15));
            $height = max(1, intdiv(max(0, $to->getTimestamp() - $from->getTimestamp()) / 60 + 14, 15));
            $max = (self::LAST_HOUR - self::FIRST_HOUR) * 4;
            $day['timed'][] = $e + ['local_start' => $start, 'local_end' => $end, 'top' => min($top, $max - 1), 'height' => min($height, $max - min($top, $max - 1))];
        }
    }

    /**
     * Colonnes côte à côte pour les évènements qui se chevauchent (4 au plus).
     * @param list<array<string,mixed>> $events @return list<array<string,mixed>>
     */
    private function lanes(array $events): array
    {
        usort($events, static fn ($a, $b) => [$a['top'], -$a['height']] <=> [$b['top'], -$b['height']]);
        $ends = [];
        foreach ($events as &$e) {
            $lane = 0;
            while (isset($ends[$lane]) && $ends[$lane] > $e['top']) {
                $lane++;
            }
            $ends[$lane] = $e['top'] + $e['height'];
            $e['lane'] = min($lane, 3);
        }
        unset($e);
        $count = min(max(1, count($ends)), 4);
        return array_map(static fn ($e) => $e + ['lanes' => $count], $events);
    }
}
