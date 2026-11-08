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
