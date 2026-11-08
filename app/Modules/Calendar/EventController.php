<?php

declare(strict_types=1);

namespace App\Modules\Calendar;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\HttpException;
use App\Core\ValidationException;
use App\Modules\Users\UserRepository;

/** Un évènement : création, détail, modification, annulation, réponse, rappel personnel. */
final class EventController extends Controller
{
    public function newForm(Request $request): Response
    {
        $start = $request->str('date') !== '' ? $request->str('date') . 'T09:00' : (new \DateTimeImmutable('tomorrow 09:00', $this->app->events()->timezone()))->format('Y-m-d\TH:i');
        return $this->form(null, ['start' => $start, 'end' => substr($start, 0, 11) . '10:00', 'reminder' => '15']);
    }

    public function create(Request $request): Response
    {
        try {
            $id = $this->app->events()->create($this->user(), $request->post);
        } catch (ValidationException $e) {
            return $this->back('/calendar/events/new', $e->errors, $request->post);
        }
        return $this->redirect('/calendar/events/' . $id, 'Évènement enregistré.');
    }

    public function show(Request $request): Response
    {
        $id = $this->id($request);
        $repo = new EventRepository($this->app->db());
        $event = $repo->visibleEvent((int) $this->user()['id'], $id) ?? throw new HttpException(404);
        return $this->view('calendar/event', [
            'currentPath' => '/calendar', 'event' => $event, 'participants' => $repo->participants($id),
            'when' => $this->app->events()->when($event), 'mine' => (int) $event['organizer_id'] === (int) $this->user()['id'],
            'endLabel' => (int) $event['all_day'] === 1 ? null : $this->app->events()->toLocal((string) $event['end_at'])->format('H:i'),
        ]);
    }

    public function editForm(Request $request): Response
    {
        $event = $this->app->events()->requireOrganizer($this->user(), $this->id($request));
        $svc = $this->app->events();
        $values = $event + [
            'start' => (int) $event['all_day'] === 1 ? $event['start_date'] . 'T09:00' : $svc->toLocal((string) $event['start_at'])->format('Y-m-d\TH:i'),
            'end' => (int) $event['all_day'] === 1 ? $event['end_date'] . 'T10:00' : $svc->toLocal((string) $event['end_at'])->format('Y-m-d\TH:i'),
            'reminder' => (string) ($event['reminder_minutes'] ?? ''),
            'participants' => array_column($this->app->db()->fetchAll('SELECT user_id FROM event_participants WHERE event_id = ?', [$event['id']]), 'user_id'),
            'until' => $event['series_id'] !== null ? (string) $this->app->db()->value('SELECT until_date FROM calendar_series WHERE id = ?', [$event['series_id']]) : '',
        ];
        return $this->form($event, $values);
    }

    public function update(Request $request): Response
    {
        $id = $this->id($request);
        try {
            $this->app->events()->update($this->user(), $id, $request->post, $request->str('scope') === 'following' ? 'following' : 'one');
        } catch (ValidationException $e) {
            return $this->back("/calendar/events/$id/edit", $e->errors, $request->post);
        }
        return $this->redirect('/calendar/events/' . $id, 'Évènement modifié.');
    }

    public function cancel(Request $request): Response
    {
        $this->app->events()->cancel($this->user(), $this->id($request), $request->str('scope') === 'following' ? 'following' : 'one');
        return $this->redirect('/calendar/events/' . $this->id($request), 'Évènement annulé : les participants sont prévenus.');
    }

    public function respond(Request $request): Response
    {
        $this->app->events()->respond($this->user(), $this->id($request), $request->str('response'), $request->str('scope') === 'one' ? 'one' : 'series');
        return $this->redirect('/calendar/events/' . $this->id($request), 'Réponse enregistrée.');
    }

    public function reminder(Request $request): Response
    {
        $minutes = $request->str('reminder');
        $this->app->events()->setReminder($this->user(), $this->id($request), $minutes === '' ? null : (int) $minutes);
        return $this->redirect('/calendar/events/' . $this->id($request), 'Rappel enregistré.');
    }

    /** @param array<string,mixed>|null $event @param array<string,mixed> $values */
    private function form(?array $event, array $values): Response
    {
        $me = (int) $this->user()['id'];
        return $this->view('calendar/form', [
            'currentPath' => '/calendar', 'event' => $event, 'values' => $values,
            'people' => array_values(array_filter((new UserRepository($this->app->db()))->options(), static fn ($p) => (int) $p['id'] !== $me)),
            'departments' => $this->app->db()->fetchAll('SELECT id, name FROM departments WHERE is_active = 1 ORDER BY name'),
        ]);
    }
}
