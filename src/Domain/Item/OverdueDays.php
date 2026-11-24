<?php

declare(strict_types=1);

namespace App\Domain\Item;

/**
 * Pure calculation of lateness in calendar days.
 *
 * Each date is reduced to its local calendar date and rebuilt at midnight UTC, so a
 * DST transition between them cannot turn 2 calendar days into 1 (or 3).
 */
final class OverdueDays
{
    private const string DATE_FORMAT = 'Y-m-d';

    private const string REFERENCE_TIMEZONE = 'UTC';

    private function __construct() {}

    public static function calculate(\DateTimeImmutable $dueAt, \DateTimeImmutable $reference): int
    {
        $dueDay       = self::startOfDay($dueAt);
        $referenceDay = self::startOfDay($reference);

        if ($referenceDay <= $dueDay) {
            return 0;
        }

        return $dueDay->diff($referenceDay)->days;
    }

    private static function startOfDay(\DateTimeImmutable $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date->format(self::DATE_FORMAT), new \DateTimeZone(self::REFERENCE_TIMEZONE));
    }
}
