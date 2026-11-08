<?php

declare(strict_types=1);

namespace App\Modules\Calendar;

use App\Core\Database;
use App\Modules\Notifications\Notifier;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Rappels d'évènements, appelés par cron:run (toutes les 5 minutes : précision de ±5 min).
 * Une seule fois par participant (reminded_at), jamais à ceux qui ont décliné, jamais après le début ;
 * journée entière : la veille à 18 h (rappel « la veille »), sinon 7 h le jour même, heure de l'entreprise.
 */
final class ReminderService
{
    public function __construct(private readonly Database $db, private readonly Notifier $notifier, private readonly string $timezone)
    {
    }

    public function run(DateTimeImmutable $nowUtc): int
    {
        $now = $nowUtc->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $local = $nowUtc->setTimezone(new DateTimeZone($this->timezone));
        $due = $this->db->fetchAll(
            "SELECT p.event_id, p.user_id, e.title, e.start_at FROM event_participants p JOIN events e ON e.id = p.event_id
             WHERE e.status = 'confirmed' AND e.all_day = 0 AND p.response <> 'no' AND p.reminder_minutes IS NOT NULL AND p.reminded_at IS NULL
               AND e.start_at > ? AND DATE_SUB(e.start_at, INTERVAL p.reminder_minutes MINUTE) <= ?",
            [$now, $now],
        );
        $sent = 0;
        foreach ($due as $r) {
            $time = (new DateTimeImmutable($r['start_at'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($this->timezone))->format('H:i');
            $sent += $this->send($r, 'Rappel : ' . $r['title'] . ' à ' . $time);
        }
        $allDay = $this->db->fetchAll(
            "SELECT p.event_id, p.user_id, p.reminder_minutes, e.title, e.start_date FROM event_participants p JOIN events e ON e.id = p.event_id
             WHERE e.status = 'confirmed' AND e.all_day = 1 AND p.response <> 'no' AND p.reminder_minutes IS NOT NULL AND p.reminded_at IS NULL
               AND e.start_date BETWEEN ? AND ?",
            [$local->format('Y-m-d'), $local->modify('+1 day')->format('Y-m-d')],
        );
        foreach ($allDay as $r) {
            $day = new DateTimeImmutable($r['start_date'], new DateTimeZone($this->timezone));
            $eve = (int) $r['reminder_minutes'] === 1440;
            $at = $eve ? $day->modify('-1 day')->setTime(18, 0) : $day->setTime(7, 0);
            if ($local >= $at && $local < $day->modify('+1 day')) {
                $sent += $this->send($r, 'Rappel : ' . $r['title'] . ($local < $day ? ' demain' : ' aujourd\'hui'));
            }
        }
        return $sent;
    }

    /** @param array<string,mixed> $r */
    private function send(array $r, string $title): int
    {
        // Marquage d'abord : deux exécutions qui se chevaucheraient n'envoient pas deux fois.
        $claimed = $this->db->run('UPDATE event_participants SET reminded_at = UTC_TIMESTAMP() WHERE event_id = ? AND user_id = ? AND reminded_at IS NULL', [$r['event_id'], $r['user_id']])->rowCount();
        if ($claimed === 0) {
            return 0;
        }
        $this->notifier->notify((int) $r['user_id'], 'calendar.reminder', $title, '/calendar/events/' . $r['event_id']);
        return 1;
    }
}
