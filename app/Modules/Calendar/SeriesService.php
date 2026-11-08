<?php

declare(strict_types=1);

namespace App\Modules\Calendar;

use App\Core\ValidationException;
use DateTimeImmutable;

/** Dates des occurrences d'une série (heure locale de la première conservée). Une série dure 1 an et 260 occurrences au plus. */
final class SeriesService
{
    public const MAX_OCCURRENCES = 260;

    /**
     * @param 'weekdays'|'weekly'|'monthly' $kind @param list<int> $weekdays jours ISO (1 = lundi) pour « weekly »
     * @return list<DateTimeImmutable>
     */
    public function occurrences(string $kind, array $weekdays, DateTimeImmutable $first, DateTimeImmutable $until): array
    {
        $last = $until->setTime(23, 59, 59);
        if ($last < $first) {
            throw new ValidationException(['until' => 'La fin de la série doit suivre le premier évènement.']);
        }
        if ($until->format('Y-m-d') >= $first->modify('+1 year')->format('Y-m-d')) {
            throw new ValidationException(['until' => 'Une série dure un an au plus.']);
        }
        $days = array_map('intval', $weekdays);
        if ($kind === 'weekly' && $days === []) {
            throw new ValidationException(['weekdays' => 'Choisissez au moins un jour.']);
        }
        $out = [];
        if ($kind === 'monthly') {
            $day = (int) $first->format('j');
            for ($m = $first->modify('first day of this month'); $m <= $last; $m = $m->modify('first day of next month')) {
                // Mois sans ce jour (31, 30, 29 février) : sauté.
                if (checkdate((int) $m->format('n'), $day, (int) $m->format('Y'))) {
                    $out[] = $m->setDate((int) $m->format('Y'), (int) $m->format('n'), $day)->setTime((int) $first->format('H'), (int) $first->format('i'));
                }
            }
        } else {
            for ($d = $first; $d <= $last; $d = $d->modify('+1 day')) {
                $n = (int) $d->format('N');
                if ($kind === 'weekdays' ? $n <= 5 : in_array($n, $days, true)) {
                    $out[] = $d;
                }
            }
        }
        if (count($out) > self::MAX_OCCURRENCES) {
            throw new ValidationException(['until' => 'Une série compte ' . self::MAX_OCCURRENCES . ' occurrences au plus.']);
        }
        return $out;
    }
}
