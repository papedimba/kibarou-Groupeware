<?php

declare(strict_types=1);

namespace App\Modules\Calendar;

/** Fichier iCalendar (RFC 5545) : heures en UTC, journée entière en dates, lignes pliées à 75 octets. */
final class IcsWriter
{
    /** @param list<array<string,mixed>> $events */
    public function calendar(array $events, string $host): string
    {
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Kibarou Groupware//Calendrier//FR', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH'];
        $stamp = gmdate('Ymd\THis\Z');
        foreach ($events as $e) {
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:event-' . (int) $e['id'] . '@' . $host;
            $lines[] = 'DTSTAMP:' . $stamp;
            if ((int) $e['all_day'] === 1) {
                $lines[] = 'DTSTART;VALUE=DATE:' . str_replace('-', '', (string) $e['start_date']);
                // Fin exclusive en iCalendar : le lendemain du dernier jour.
                $lines[] = 'DTEND;VALUE=DATE:' . (new \DateTimeImmutable((string) $e['end_date']))->modify('+1 day')->format('Ymd');
            } else {
                $lines[] = 'DTSTART:' . self::utc((string) $e['start_at']);
                $lines[] = 'DTEND:' . self::utc((string) $e['end_at']);
            }
            $lines[] = 'SUMMARY:' . self::text((string) $e['title']);
            if (!empty($e['location'])) {
                $lines[] = 'LOCATION:' . self::text((string) $e['location']);
            }
            if (!empty($e['description'])) {
                $lines[] = 'DESCRIPTION:' . self::text((string) $e['description']);
            }
            $lines[] = 'STATUS:' . ($e['status'] === 'cancelled' ? 'CANCELLED' : 'CONFIRMED');
            if (!empty($e['updated_at'])) {
                $lines[] = 'LAST-MODIFIED:' . self::utc((string) $e['updated_at']);
            }
            $lines[] = 'END:VEVENT';
        }
        $lines[] = 'END:VCALENDAR';
        return implode('', array_map(static fn ($l) => self::fold($l) . "\r\n", $lines));
    }

    private static function utc(string $sql): string
    {
        return (new \DateTimeImmutable($sql, new \DateTimeZone('UTC')))->format('Ymd\THis\Z');
    }

    private static function text(string $value): string
    {
        return str_replace(["\\", ';', ',', "\r\n", "\n", "\r"], ['\\\\', '\;', '\\,', '\\n', '\\n', ''], $value);
    }

    /** Lignes de 75 octets au plus, sans couper un caractère UTF-8 ; la suite commence par une espace. */
    private static function fold(string $line): string
    {
        $out = [];
        $max = 75;
        while (strlen($line) > $max) {
            $part = mb_strcut($line, 0, $max, 'UTF-8');
            $out[] = $part;
            $line = ' ' . substr($line, strlen($part));
            $max = 75;
        }
        $out[] = $line;
        return implode("\r\n", $out);
    }
}
