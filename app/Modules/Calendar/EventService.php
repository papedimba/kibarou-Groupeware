<?php

declare(strict_types=1);

namespace App\Modules\Calendar;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\ValidationException;
use App\Modules\Notifications\Notifier;
use App\Services\AuditLogger;
use App\Services\Rbac;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Règles du calendrier : seul l'organisateur modifie, chacun répond pour soi, aucune description dans les notifications.
 * Heures saisies et affichées dans le fuseau de l'entreprise, stockées en UTC ; journée entière en dates.
 */
final class EventService
{
    public const REMINDERS = [15, 60, 1440];
    private const RESPONSES = ['yes' => 'J\'y serai', 'maybe' => 'Peut-être', 'no' => 'Je n\'y serai pas'];

    public function __construct(
        private readonly Database $db,
        private readonly EventRepository $repo,
        private readonly Rbac $rbac,
        private readonly AuditLogger $audit,
        private readonly Notifier $notifier,
        private readonly string $timezone,
    ) {
    }

    /** @param array<string,mixed> $actor @param array<string,mixed> $input */
    public function create(array $actor, array $input): int
    {
        $data = $this->validate($input);
        $me = (int) $actor['id'];
        $invited = $this->invitees($input, $me);
        $id = $this->db->transaction(function () use ($data, $me, $invited) {
            $id = $this->db->insert('events', $data + ['organizer_id' => $me, 'created_at' => now_utc(), 'updated_at' => now_utc()]);
            $this->addParticipant($id, $me, 'yes', $data['reminder_minutes']);
            foreach ($invited as $uid) {
                $this->addParticipant($id, $uid, 'pending', $data['reminder_minutes']);
            }
            return $id;
        });
        $this->notifyInvited($actor, $id, $invited);
        return $id;
    }

    /** @param array<string,mixed> $actor @param array<string,mixed> $input */
    public function update(array $actor, int $eventId, array $input, string $scope = 'one'): void
    {
        $event = $this->requireOrganizer($actor, $eventId);
        $data = $this->validate($input);
        $me = (int) $actor['id'];
        $wanted = $this->invitees($input, $me);
        $current = array_map('intval', array_column($this->db->fetchAll('SELECT user_id FROM event_participants WHERE event_id = ? AND user_id <> ?', [$eventId, $me]), 'user_id'));
        $timeChanged = $this->timeKey($event) !== $this->timeKey($data);
        $visibleChange = $timeChanged || $event['title'] !== $data['title'] || (string) $event['location'] !== (string) $data['location'];
        $added = array_values(array_diff($wanted, $current));
        $this->db->transaction(function () use ($eventId, $data, $event, $me, $wanted, $current, $added, $timeChanged) {
            $this->db->update('events', $data + ['updated_at' => now_utc()], ['id' => $eventId]);
            foreach (array_diff($current, $wanted) as $uid) {
                $this->db->run('DELETE FROM event_participants WHERE event_id = ? AND user_id = ?', [$eventId, $uid]);
            }
            foreach ($added as $uid) {
                $this->addParticipant($eventId, $uid, 'pending', $data['reminder_minutes']);
            }
            if ($timeChanged) {
                // Nouvel horaire : les réponses ne valent plus, et le rappel repart.
                $this->db->run("UPDATE event_participants SET response = 'pending', responded_at = NULL WHERE event_id = ? AND user_id <> ?", [$eventId, $me]);
                $this->db->run('UPDATE event_participants SET reminded_at = NULL WHERE event_id = ?', [$eventId]);
            }
            if ($data['reminder_minutes'] !== ($event['reminder_minutes'] !== null ? (int) $event['reminder_minutes'] : null)) {
                $this->db->run('UPDATE event_participants SET reminder_minutes = ?, reminded_at = NULL WHERE event_id = ? AND reminder_custom = 0', [$data['reminder_minutes'], $eventId]);
            }
        });
        if ($visibleChange) {
            $this->notifyParticipants($actor, $eventId, 'calendar.updated', 'Modifié : ' . $data['title'] . ' — ' . $this->when($data), array_diff($wanted, $added));
        }
        $this->notifyInvited($actor, $eventId, $added);
    }

    /** @param array<string,mixed> $actor */
    public function cancel(array $actor, int $eventId, string $scope = 'one'): void
    {
        $event = $this->requireOrganizer($actor, $eventId);
        if ($event['status'] === 'cancelled') {
            return;
        }
        $this->db->update('events', ['status' => 'cancelled', 'updated_at' => now_utc()], ['id' => $eventId]);
        $others = array_map('intval', array_column($this->db->fetchAll('SELECT user_id FROM event_participants WHERE event_id = ? AND user_id <> ?', [$eventId, (int) $actor['id']]), 'user_id'));
        $this->notifyParticipants($actor, $eventId, 'calendar.cancelled', 'Annulé : ' . $event['title'] . ' — ' . $this->when($event), $others);
        $this->audit->log((int) $actor['id'], 'calendar.event_cancelled', 'event', $eventId);
    }

    /** @param array<string,mixed> $actor */
    public function respond(array $actor, int $eventId, string $response, string $scope = 'series'): void
    {
        $me = (int) $actor['id'];
        $event = $this->repo->visibleEvent($me, $eventId) ?? throw new HttpException(404);
        if (!isset(self::RESPONSES[$response])) {
            throw new ValidationException(['response' => 'Réponse inconnue.']);
        }
        $this->db->run('UPDATE event_participants SET response = ?, responded_at = UTC_TIMESTAMP() WHERE event_id = ? AND user_id = ?', [$response, $eventId, $me]);
        if ((int) $event['organizer_id'] !== $me && $event['organizer_id'] !== null) {
            $this->notifier->notify((int) $event['organizer_id'], 'calendar.response',
                $actor['first_name'] . ' ' . $actor['last_name'] . ' : ' . self::RESPONSES[$response] . ' — ' . $event['title'], '/calendar/events/' . $eventId, null, $me);
        }
    }

    /** Rappel personnel (aucun, 15 min, 1 h, la veille). @param array<string,mixed> $actor */
    public function setReminder(array $actor, int $eventId, ?int $minutes): void
    {
        $me = (int) $actor['id'];
        $this->repo->visibleEvent($me, $eventId) ?? throw new HttpException(404);
        $minutes = in_array($minutes, self::REMINDERS, true) ? $minutes : null;
        $this->db->run('UPDATE event_participants SET reminder_minutes = ?, reminder_custom = 1, reminded_at = NULL WHERE event_id = ? AND user_id = ?', [$minutes, $eventId, $me]);
    }

    /** « 2026-03-30 09:00 » (heure locale) → « 2026-03-30 07:00:00 » UTC. */
    public function toUtc(string $local): string
    {
        $value = str_replace('T', ' ', trim($local));
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i', substr($value, 0, 16), new DateTimeZone($this->timezone));
        if ($date === false) {
            throw new ValidationException(['start' => 'Date ou heure invalide.']);
        }
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public function toLocal(string $utc): DateTimeImmutable
    {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($this->timezone));
    }

    public function timezone(): DateTimeZone
    {
        return new DateTimeZone($this->timezone);
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed> colonnes de `events`
     */
    public function validate(array $input): array
    {
        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 150) {
            throw new ValidationException(['title' => 'Le titre est obligatoire (150 caractères au plus).']);
        }
        $location = trim((string) ($input['location'] ?? ''));
        if (mb_strlen($location) > 150) {
            throw new ValidationException(['location' => 'Le lieu compte 150 caractères au plus.']);
        }
        $allDay = (string) ($input['all_day'] ?? '0') === '1';
        $start = str_replace('T', ' ', trim((string) ($input['start'] ?? '')));
        $end = str_replace('T', ' ', trim((string) ($input['end'] ?? '')));
        $data = ['title' => $title, 'location' => $location !== '' ? $location : null,
            'description' => trim((string) ($input['description'] ?? '')) ?: null, 'all_day' => $allDay ? 1 : 0,
            'is_private' => (string) ($input['is_private'] ?? '0') === '1' ? 1 : 0,
            'reminder_minutes' => in_array((int) ($input['reminder'] ?? 0), self::REMINDERS, true) ? (int) $input['reminder'] : null,
            'start_at' => null, 'end_at' => null, 'start_date' => null, 'end_date' => null];
        if ($allDay) {
            $s = DateTimeImmutable::createFromFormat('!Y-m-d', substr($start, 0, 10));
            $e = DateTimeImmutable::createFromFormat('!Y-m-d', substr($end !== '' ? $end : $start, 0, 10));
            if ($s === false || $e === false) {
                throw new ValidationException(['start' => 'Date invalide.']);
            }
            if ($e < $s) {
                throw new ValidationException(['end' => 'La fin doit suivre le début.']);
            }
            $data['start_date'] = $s->format('Y-m-d');
            $data['end_date'] = $e->format('Y-m-d');
            return $data;
        }
        if (strlen($start) < 16 || strlen($end) < 16) {
            throw new ValidationException(['start' => 'Indiquez la date et l\'heure de début et de fin.']);
        }
        $data['start_at'] = $this->toUtc($start);
        $data['end_at'] = $this->toUtc($end);
        if ($data['end_at'] <= $data['start_at']) {
            throw new ValidationException(['end' => 'La fin doit suivre le début.']);
        }
        return $data;
    }

    /** Libellé local : « mardi 10/11 à 09:00 » ou « mardi 10/11 (journée) ». @param array<string,mixed> $e */
    public function when(array $e): string
    {
        $days = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
        if ((int) $e['all_day'] === 1) {
            $d = new DateTimeImmutable((string) $e['start_date']);
            return $days[(int) $d->format('w')] . ' ' . $d->format('d/m') . ' (journée)';
        }
        $d = $this->toLocal((string) $e['start_at']);
        return $days[(int) $d->format('w')] . ' ' . $d->format('d/m') . ' à ' . $d->format('H:i');
    }

    /** @param array<string,mixed> $actor @return array<string,mixed> */
    public function requireOrganizer(array $actor, int $eventId): array
    {
        $event = $this->repo->visibleEvent((int) $actor['id'], $eventId) ?? throw new HttpException(404);
        if ((int) $event['organizer_id'] !== (int) $actor['id']) {
            throw new HttpException(403);
        }
        return $event;
    }

    /**
     * Invités : personnes choisies et membres actifs des services choisis au moment de l'invitation.
     * @param array<string,mixed> $input @return list<int>
     */
    private function invitees(array $input, int $organizerId): array
    {
        $ids = array_map('intval', (array) ($input['participants'] ?? []));
        $depts = array_values(array_filter(array_map('intval', (array) ($input['departments'] ?? []))));
        if ($depts !== []) {
            $ids = [...$ids, ...array_map('intval', array_column($this->db->fetchAll(
                "SELECT id FROM users WHERE status = 'active' AND deleted_at IS NULL AND department_id IN (" . implode(',', array_fill(0, count($depts), '?')) . ')',
                $depts,
            ), 'id'))];
        }
        $ids = array_values(array_unique(array_filter($ids, static fn ($id) => $id > 0 && $id !== $organizerId)));
        if ($ids === []) {
            return [];
        }
        $active = array_map('intval', array_column($this->db->fetchAll(
            "SELECT id FROM users WHERE status = 'active' AND deleted_at IS NULL AND id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')',
            $ids,
        ), 'id'));
        sort($active);
        return $active;
    }

    private function addParticipant(int $eventId, int $userId, string $response, ?int $reminder): void
    {
        $this->db->insert('event_participants', ['event_id' => $eventId, 'user_id' => $userId, 'response' => $response,
            'reminder_minutes' => $reminder, 'responded_at' => $response === 'pending' ? null : now_utc()]);
    }

    /** @param array<string,mixed> $actor @param list<int> $userIds */
    private function notifyInvited(array $actor, int $eventId, array $userIds): void
    {
        $event = $this->db->fetch('SELECT * FROM events WHERE id = ?', [$eventId]);
        foreach ($userIds as $uid) {
            $this->notifier->notify($uid, 'calendar.invited', 'Invitation : ' . $event['title'] . ' — ' . $this->when($event), '/calendar/events/' . $eventId, null, (int) $actor['id']);
        }
    }

    /** Participants (sauf ceux qui ont décliné) parmi $userIds. @param array<string,mixed> $actor @param iterable<int> $userIds */
    private function notifyParticipants(array $actor, int $eventId, string $type, string $title, iterable $userIds): void
    {
        $declined = array_map('intval', array_column($this->db->fetchAll("SELECT user_id FROM event_participants WHERE event_id = ? AND response = 'no'", [$eventId]), 'user_id'));
        foreach ($userIds as $uid) {
            if (!in_array((int) $uid, $declined, true)) {
                $this->notifier->notify((int) $uid, $type, $title, '/calendar/events/' . $eventId, null, (int) $actor['id']);
            }
        }
    }

    /** @param array<string,mixed> $e */
    private function timeKey(array $e): string
    {
        return implode('|', [(int) $e['all_day'], $e['start_at'] ?? '', $e['end_at'] ?? '', $e['start_date'] ?? '', $e['end_date'] ?? '']);
    }
}
