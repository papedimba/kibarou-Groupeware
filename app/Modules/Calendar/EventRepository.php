<?php

declare(strict_types=1);

namespace App\Modules\Calendar;

use App\Core\Database;

/** Lectures du calendrier. Règle de base : on ne lit le détail d'un évènement que si l'on y participe. */
final class EventRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /** Évènement et ma participation ; null si je n'y participe pas. @return array<string,mixed>|null */
    public function visibleEvent(int $userId, int $eventId): ?array
    {
        return $this->db->fetch(
            "SELECT e.*, p.response, p.reminder_minutes AS my_reminder, p.reminder_custom, CONCAT(o.first_name, ' ', o.last_name) AS organizer_name
             FROM events e JOIN event_participants p ON p.event_id = e.id AND p.user_id = ?
             LEFT JOIN users o ON o.id = e.organizer_id WHERE e.id = ?",
            [$userId, $eventId],
        );
    }

    /**
     * Mes évènements (organisateur ou participant) qui chevauchent la période, annulés compris (affichés barrés).
     * @return list<array<string,mixed>>
     */
    public function forUser(int $userId, string $fromUtc, string $toUtc, string $fromDate, string $toDate): array
    {
        return $this->db->fetchAll(
            'SELECT e.*, p.response FROM events e JOIN event_participants p ON p.event_id = e.id AND p.user_id = ?
             WHERE (e.all_day = 0 AND e.start_at < ? AND e.end_at > ?) OR (e.all_day = 1 AND e.start_date <= ? AND e.end_date >= ?)
             ORDER BY e.all_day DESC, COALESCE(e.start_at, e.start_date), e.id',
            [$userId, $toUtc, $fromUtc, $toDate, $fromDate],
        );
    }

    /**
     * Créneaux occupés de plusieurs personnes, sans aucun détail : évènements confirmés, non privés, non déclinés.
     * @param list<int> $userIds @return array<int,list<array<string,mixed>>>
     */
    public function busy(array $userIds, string $fromUtc, string $toUtc, string $fromDate, string $toDate): array
    {
        if ($userIds === []) {
            return [];
        }
        $rows = $this->db->fetchAll(
            "SELECT p.user_id, e.all_day, e.start_at, e.end_at, e.start_date, e.end_date FROM event_participants p JOIN events e ON e.id = p.event_id
             WHERE p.user_id IN (" . implode(',', array_fill(0, count($userIds), '?')) . ") AND p.response <> 'no' AND e.status = 'confirmed' AND e.is_private = 0
               AND ((e.all_day = 0 AND e.start_at < ? AND e.end_at > ?) OR (e.all_day = 1 AND e.start_date <= ? AND e.end_date >= ?))
             ORDER BY COALESCE(e.start_at, e.start_date)",
            [...$userIds, $toUtc, $fromUtc, $toDate, $fromDate],
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['user_id']][] = $r;
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    public function participants(int $eventId): array
    {
        return $this->db->fetchAll(
            "SELECT p.*, u.first_name, u.last_name, u.avatar_path, u.id FROM event_participants p JOIN users u ON u.id = p.user_id
             WHERE p.event_id = ? ORDER BY u.last_name, u.first_name",
            [$eventId],
        );
    }
}
