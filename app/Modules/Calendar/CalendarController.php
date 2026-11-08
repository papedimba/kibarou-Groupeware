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
        foreach ($days as $key => &$day) {
            $day['timed'] = $this->lanes($day['timed']);
            $day['presence'] = $this->presenceOn((int) $this->user()['id'], $key);
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

    /**
     * Disponibilités : jusqu'à 10 personnes sur 7 jours à partir de la date ; « Occupé » et statut de présence, sans détail.
     */
    public function availability(Request $request): Response
    {
        $svc = $this->app->events();
        $tz = $svc->timezone();
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $request->str('date'), $tz) ?: new DateTimeImmutable('today', $tz);
        $ids = array_values(array_unique($request->intList('users')));
        $error = count($ids) > 10 ? 'Dix personnes au plus.' : null;
        $ids = $ids === [] ? [(int) $this->user()['id']] : array_slice($ids, 0, 10);
        $end = $start->modify('+7 days');
        $busy = (new EventRepository($this->app->db()))->busy($ids, $svc->toUtc($start->format('Y-m-d 00:00')), $svc->toUtc($end->format('Y-m-d 00:00')),
            $start->format('Y-m-d'), $end->modify('-1 day')->format('Y-m-d'));
        $grid = [];
        foreach ($ids as $uid) {
            for ($d = $start; $d < $end; $d = $d->modify('+1 day')) {
                $key = $d->format('Y-m-d');
                $cell = ['busy' => [], 'presence' => $this->presenceOn($uid, $key, true)];
                foreach ($busy[$uid] ?? [] as $b) {
                    if ((int) $b['all_day'] === 1) {
                        if ($key >= $b['start_date'] && $key <= $b['end_date']) {
                            $cell['busy'][] = 'Occupé toute la journée';
                        }
                        continue;
                    }
                    $s = $svc->toLocal((string) $b['start_at']);
                    $e = $svc->toLocal((string) $b['end_at']);
                    if ($s < $d->modify('+1 day') && $e > $d) {
                        $cell['busy'][] = 'Occupé ' . max($s, $d)->format('H:i') . '–' . ($e >= $d->modify('+1 day') ? '24:00' : $e->format('H:i'));
                    }
                }
                $grid[$uid][$key] = $cell;
            }
        }
        $people = (new \App\Modules\Users\UserRepository($this->app->db()))->options();
        return $this->view('calendar/availability', [
            'currentPath' => '/calendar', 'start' => $start, 'grid' => $grid, 'selected' => $ids, 'people' => $people, 'error' => $error,
            'names' => array_column($people, 'name', 'id') + [(int) $this->user()['id'] => 'Moi'],
        ]);
    }

    /** @var array<int,array<string,mixed>|null> */
    private array $presence = [];

    /**
     * Statut de présence en cours, prolongé jusqu'à sa date de fin (pas de statut déclaré à l'avance, décision du lot 2).
     * « Au bureau » n'est pas affiché ; $flag ajoute l'indisponibilité (congé, absence).
     * @return array{label:string,off:bool}|null
     */
    private function presenceOn(int $userId, string $day, bool $flag = false): ?array
    {
        if (!array_key_exists($userId, $this->presence)) {
            $this->presence[$userId] = $this->app->presence()->current([$userId])[$userId] ?? null;
        }
        $p = $this->presence[$userId];
        $today = $this->app->presence()->today();
        if ($p === null || $p['status'] === 'office' || $day < $today || $day > ($p['until_date'] ?? $today)) {
            return null;
        }
        $label = $p['label'] . (!empty($p['site_label']) ? ' · ' . $p['site_label'] : '');
        return ['label' => $label, 'off' => in_array($p['status'], ['leave', 'absent'], true)];
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
