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
        $rows = $this->expand($data, $input);
        if ($rows === []) {
            throw new ValidationException(['until' => 'Aucune date de la série ne tombe dans cette période.']);
        }
        $id = $this->db->transaction(function () use ($rows, $input, $me, $invited) {
            $seriesId = count($rows) > 1 || in_array($input['repeat'] ?? '', ['weekdays', 'weekly', 'monthly'], true) ? $this->db->insert('calendar_series', [
                'organizer_id' => $me, 'kind' => $input['repeat'], 'weekdays' => implode(',', array_map('intval', (array) ($input['weekdays'] ?? []))) ?: null,
                'until_date' => substr((string) $input['until'], 0, 10), 'created_at' => now_utc(),
            ]) : null;
            $first = null;
            foreach ($rows as $row) {
                $id = $this->db->insert('events', $row + ['series_id' => $seriesId, 'organizer_id' => $me, 'created_at' => now_utc(), 'updated_at' => now_utc()]);
                $first ??= $id;
                $this->addParticipant($id, $me, 'yes', $row['reminder_minutes']);
                foreach ($invited as $uid) {
                    $this->addParticipant($id, $uid, 'pending', $row['reminder_minutes']);
                }
            }
            return $first;
        });
        // Une seule invitation par personne, même pour une série.
        $this->notifyInvited($actor, $id, $invited);
        return $id;
    }

    /**
     * $scope : « one » (cette fois seulement ; une occurrence de série devient détachée) ou « following »
     * (cette occurrence et les suivantes non détachées ; les précédentes ne bougent pas).
     * @param array<string,mixed> $actor @param array<string,mixed> $input
     */
    public function update(array $actor, int $eventId, array $input, string $scope = 'one'): void
    {
        $event = $this->requireOrganizer($actor, $eventId);
        $data = $this->validate($input);
        $me = (int) $actor['id'];
        $wanted = $this->invitees($input, $me);
        $targets = [$eventId => $data];
        if ($scope === 'following' && $event['series_id'] !== null) {
            $targets = $this->shiftFollowing($event, $data);
        }
        $timeChanged = false;
        $added = [];
        $before = [];
        $cancelledLater = 0;
        if ($scope === 'following' && $event['series_id'] !== null && !empty($input['until'])) {
            // Nouvelle date de fin validée avant toute écriture (1 an et 260 occurrences au plus).
            $this->seriesDates($event, $data, (string) $input['until']);
        }
        $this->db->transaction(function () use ($targets, $wanted, $me, $event, $scope, $data, $input, &$timeChanged, &$added, &$before, &$cancelledLater) {
            foreach ($targets as $id => $row) {
                $old = $this->db->fetch('SELECT * FROM events WHERE id = ?', [$id]);
                $current = array_map('intval', array_column($this->db->fetchAll('SELECT user_id FROM event_participants WHERE event_id = ? AND user_id <> ?', [$id, $me]), 'user_id'));
                $before = array_values(array_unique([...$before, ...$current]));
                $changed = $this->timeKey($old) !== $this->timeKey($row);
                $timeChanged = $timeChanged || $changed;
                $detached = $scope === 'one' && $event['series_id'] !== null ? ['detached' => 1] : [];
                $this->db->update('events', $row + $detached + ['updated_at' => now_utc()], ['id' => $id]);
                foreach (array_diff($current, $wanted) as $uid) {
                    $this->db->run('DELETE FROM event_participants WHERE event_id = ? AND user_id = ?', [$id, $uid]);
                }
                foreach (array_diff($wanted, $current) as $uid) {
                    $this->addParticipant($id, $uid, 'pending', $row['reminder_minutes']);
                    $added[] = $uid;
                }
                if ($changed) {
                    // Nouvel horaire : les réponses ne valent plus, et le rappel repart.
                    $this->db->run("UPDATE event_participants SET response = 'pending', responded_at = NULL WHERE event_id = ? AND user_id <> ?", [$id, $me]);
                    $this->db->run('UPDATE event_participants SET reminded_at = NULL WHERE event_id = ?', [$id]);
                }
                if ($row['reminder_minutes'] !== ($old['reminder_minutes'] !== null ? (int) $old['reminder_minutes'] : null)) {
                    $this->db->run('UPDATE event_participants SET reminder_minutes = ?, reminded_at = NULL WHERE event_id = ? AND reminder_custom = 0', [$row['reminder_minutes'], $id]);
                }
            }
            // Dans la même transaction : une date de fin refusée (plus d'un an) annule toute la modification.
            if ($scope === 'following' && $event['series_id'] !== null && !empty($input['until'])) {
                $cancelledLater = $this->resizeSeries($event, $data, (string) $input['until']);
            }
        });
        if ($cancelledLater > 0) {
            $others = array_map('intval', array_column($this->db->fetchAll('SELECT user_id FROM event_participants WHERE event_id = ? AND user_id <> ?', [$eventId, $me]), 'user_id'));
            $this->notifyParticipants($actor, $eventId, 'calendar.cancelled', 'Annulé : ' . $data['title'] . ' — dates après le '
                . (new \DateTimeImmutable(substr((string) $input['until'], 0, 10)))->format('d/m/Y'), $others);
        }
        $added = array_values(array_unique(array_diff($added, $before)));
        $visibleChange = $timeChanged || $event['title'] !== $data['title'] || (string) $event['location'] !== (string) $data['location'];
        // Une seule notification par personne, même quand toute une série change.
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
        // « Toute la série à venir » : occurrences détachées comprises.
        $ids = $scope === 'following' && $event['series_id'] !== null ? $this->followingIds($event, true) : [$eventId];
        $this->db->run("UPDATE events SET status = 'cancelled', updated_at = UTC_TIMESTAMP() WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')', $ids);
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
        $ids = $scope === 'series' && $event['series_id'] !== null ? $this->followingIds($event, true) : [$eventId];
        $this->db->run('UPDATE event_participants SET response = ?, responded_at = UTC_TIMESTAMP() WHERE user_id = ? AND event_id IN ('
            . implode(',', array_fill(0, count($ids), '?')) . ')', [$response, $me, ...$ids]);
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

    /**
     * Lignes `events` à créer : une seule, ou une par occurrence quand `repeat` est demandé.
     * @param array<string,mixed> $data @param array<string,mixed> $input @return list<array<string,mixed>>
     */
    private function expand(array $data, array $input): array
    {
        $kind = (string) ($input['repeat'] ?? '');
        if (!in_array($kind, ['weekdays', 'weekly', 'monthly'], true)) {
            return [$data];
        }
        $until = \DateTimeImmutable::createFromFormat('!Y-m-d', substr((string) ($input['until'] ?? ''), 0, 10), $this->timezone());
        if ($until === false) {
            throw new ValidationException(['until' => 'Indiquez la date de fin de la série.']);
        }
        [$start, $minutes, $days] = $this->shape($data);
        return array_map(fn (DateTimeImmutable $d) => $this->at($data, $d, $minutes, $days),
            (new SeriesService())->occurrences($kind, array_map('intval', (array) ($input['weekdays'] ?? [])), $start, $until));
    }

    /**
     * Nouvelle date de fin (une série se prolonge ou se raccourcit) : occurrences ajoutées avec les participants et
     * réponses de l'occurrence modifiée, ou annulées au-delà de la nouvelle fin. 1 an et 260 occurrences au plus.
     * @param array<string,mixed> $event @param array<string,mixed> $data
     */
    private function resizeSeries(array $event, array $data, string $until): int
    {
        $series = $this->db->fetch('SELECT * FROM calendar_series WHERE id = ?', [$event['series_id']]);
        $newUntil = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($until, 0, 10), $this->timezone());
        if ($series === null || $newUntil === false || $newUntil->format('Y-m-d') === $series['until_date']) {
            return 0;
        }
        $rows = $this->db->fetchAll("SELECT * FROM events WHERE series_id = ? ORDER BY COALESCE(start_at, start_date)", [$series['id']]);
        $current = $this->db->fetch('SELECT * FROM events WHERE id = ?', [$event['id']]);
        [, $minutes, $days] = $this->shape($current);
        $dates = $this->seriesDates($event, $current, $until);
        $existing = array_map(fn ($r) => $this->shape($r)[0]->format('Y-m-d'), $rows);
        $people = $this->db->fetchAll('SELECT * FROM event_participants WHERE event_id = ?', [$event['id']]);
        return $this->db->transaction(function () use ($dates, $existing, $series, $current, $minutes, $days, $people, $newUntil) {
            foreach ($dates as $d) {
                if (in_array($d->format('Y-m-d'), $existing, true) || $d->format('Y-m-d') <= max($existing)) {
                    continue;
                }
                $row = array_intersect_key($current, array_flip(['title', 'location', 'description', 'all_day', 'is_private', 'reminder_minutes', 'organizer_id']));
                $id = $this->db->insert('events', $this->at($row + ['start_at' => null, 'end_at' => null, 'start_date' => null, 'end_date' => null], $d, $minutes, $days)
                    + ['series_id' => $series['id'], 'created_at' => now_utc(), 'updated_at' => now_utc()]);
                foreach ($people as $p) {
                    $this->db->insert('event_participants', ['event_id' => $id, 'user_id' => $p['user_id'], 'response' => $p['response'],
                        'reminder_minutes' => $p['reminder_minutes'], 'reminder_custom' => $p['reminder_custom'], 'responded_at' => $p['responded_at']]);
                }
            }
            $cancelled = $this->db->run("UPDATE events SET status = 'cancelled', updated_at = UTC_TIMESTAMP() WHERE series_id = ? AND status = 'confirmed'
                AND COALESCE(start_date, DATE(start_at)) > ?", [$series['id'], $newUntil->format('Y-m-d')])->rowCount();
            $this->db->update('calendar_series', ['until_date' => $newUntil->format('Y-m-d')], ['id' => $series['id']]);
            return $cancelled;
        });
    }

    /**
     * Dates de toute la série jusqu'à $until, à l'heure de $data (lève ValidationException au-delà des limites).
     * @param array<string,mixed> $event @param array<string,mixed> $data @return list<DateTimeImmutable>
     */
    private function seriesDates(array $event, array $data, string $until): array
    {
        $series = $this->db->fetch('SELECT * FROM calendar_series WHERE id = ?', [$event['series_id']]);
        $newUntil = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($until, 0, 10), $this->timezone());
        if ($series === null || $newUntil === false) {
            throw new ValidationException(['until' => 'Date de fin invalide.']);
        }
        $firstRow = $this->db->fetch('SELECT * FROM events WHERE series_id = ? ORDER BY COALESCE(start_at, start_date) LIMIT 1', [$series['id']]);
        [$first] = $this->shape($firstRow);
        [$start] = $this->shape($data);
        return (new SeriesService())->occurrences((string) $series['kind'], array_filter(array_map('intval', explode(',', (string) $series['weekdays']))),
            $first->setTime((int) $start->format('H'), (int) $start->format('i')), $newUntil);
    }

    /**
     * « Cette occurrence et les suivantes » : même décalage de jours, nouvelle heure et nouvelle durée pour chacune.
     * @param array<string,mixed> $event @param array<string,mixed> $data @return array<int,array<string,mixed>>
     */
    private function shiftFollowing(array $event, array $data): array
    {
        [$oldStart] = $this->shape($event);
        [$newStart, $minutes, $days] = $this->shape($data);
        $shift = (int) $oldStart->setTime(0, 0)->diff($newStart->setTime(0, 0))->format('%r%a');
        $out = [];
        foreach ($this->followingIds($event, false) as $id) {
            [$occ] = $this->shape($this->db->fetch('SELECT * FROM events WHERE id = ?', [$id]));
            $day = $occ->modify(($shift >= 0 ? '+' : '') . $shift . ' days');
            $out[$id] = $this->at($data, $day->setTime((int) $newStart->format('H'), (int) $newStart->format('i')), $minutes, $days);
        }
        return $out;
    }

    /** Début local, durée en minutes (horaire) et en jours (journée entière). @param array<string,mixed> $e @return array{DateTimeImmutable,int,int} */
    private function shape(array $e): array
    {
        if ((int) $e['all_day'] === 1) {
            $s = new DateTimeImmutable((string) $e['start_date'], $this->timezone());
            return [$s, 0, (int) $s->diff(new DateTimeImmutable((string) $e['end_date'], $this->timezone()))->format('%a')];
        }
        $s = $this->toLocal((string) $e['start_at']);
        return [$s, (int) (($this->toLocal((string) $e['end_at'])->getTimestamp() - $s->getTimestamp()) / 60), 0];
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function at(array $data, DateTimeImmutable $startLocal, int $minutes, int $days): array
    {
        if ((int) $data['all_day'] === 1) {
            return ['start_date' => $startLocal->format('Y-m-d'), 'end_date' => $startLocal->modify("+$days days")->format('Y-m-d')] + $data;
        }
        // Heure murale conservée : 8 h reste 8 h après un changement d'heure.
        return ['start_at' => $this->toUtc($startLocal->format('Y-m-d H:i')), 'end_at' => $this->toUtc($startLocal->modify("+$minutes minutes")->format('Y-m-d H:i'))] + $data;
    }

    /**
     * Cette occurrence et les suivantes de la série, non annulées ; occurrences détachées seulement si demandé (et toujours celle-ci).
     * @param array<string,mixed> $event @return list<int>
     */
    private function followingIds(array $event, bool $withDetached): array
    {
        return array_map('intval', array_column($this->db->fetchAll(
            "SELECT id FROM events WHERE series_id = ? AND status = 'confirmed'
               AND COALESCE(start_at, CAST(start_date AS DATETIME)) >= COALESCE(?, CAST(? AS DATETIME))
               AND (detached = 0 OR id = ? OR ? = 1) ORDER BY COALESCE(start_at, start_date)",
            [$event['series_id'], $event['start_at'], $event['start_date'], $event['id'], $withDetached ? 1 : 0],
        ), 'id'));
    }

    /** @param array<string,mixed> $e */
    private function timeKey(array $e): string
    {
        return implode('|', [(int) $e['all_day'], $e['start_at'] ?? '', $e['end_at'] ?? '', $e['start_date'] ?? '', $e['end_date'] ?? '']);
    }
}
