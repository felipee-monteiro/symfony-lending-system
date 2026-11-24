<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Item;

use App\Domain\Item\OverdueDays;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OverdueDaysTest extends TestCase
{
    #[Test]
    public function there_are_no_overdue_days_before_the_due_date(): void
    {
        // Arrange
        $dueAt     = $this->inLisbon('2026-01-10 10:00:00');
        $reference = $this->inLisbon('2026-01-09 10:00:00');

        // Act
        $days = OverdueDays::calculate($dueAt, $reference);

        // Assert
        self::assertSame(0, $days);
    }

    #[Test]
    public function there_are_no_overdue_days_on_the_due_date(): void
    {
        // Arrange
        $dueAt     = $this->inLisbon('2026-01-10 10:00:00');
        $reference = $this->inLisbon('2026-01-10 23:59:59');

        // Act
        $days = OverdueDays::calculate($dueAt, $reference);

        // Assert
        self::assertSame(0, $days);
    }

    #[Test]
    public function there_are_no_overdue_days_at_the_exact_due_instant(): void
    {
        // Arrange
        $dueAt = $this->inLisbon('2026-01-10 10:00:00');

        // Act
        $days = OverdueDays::calculate($dueAt, $dueAt);

        // Assert
        self::assertSame(0, $days);
    }

    #[Test]
    public function one_overdue_day_starts_at_midnight_after_the_due_date(): void
    {
        // Arrange
        $dueAt     = $this->inLisbon('2026-01-10 23:59:59');
        $reference = $this->inLisbon('2026-01-11 00:00:00');

        // Act
        $days = OverdueDays::calculate($dueAt, $reference);

        // Assert
        self::assertSame(1, $days);
    }

    #[Test]
    #[DataProvider('provide_calendarDayRanges')]
    public function overdue_days_are_counted_in_calendar_days(string $dueAt, string $reference, int $expected): void
    {
        // Arrange
        $dueDate = $this->inLisbon($dueAt);
        $now     = $this->inLisbon($reference);

        // Act
        $days = OverdueDays::calculate($dueDate, $now);

        // Assert
        self::assertSame($expected, $days);
    }

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function provide_calendarDayRanges(): iterable
    {
        yield 'same month' => ['2026-01-01 10:00:00', '2026-01-08 10:00:00', 7];

        yield 'month boundary' => ['2026-01-31 10:00:00', '2026-02-02 10:00:00', 2];

        yield 'year boundary' => ['2026-12-31 10:00:00', '2027-01-02 10:00:00', 2];

        yield 'leap year' => ['2028-02-28 10:00:00', '2028-03-01 10:00:00', 2];

        yield 'spring forward in Lisbon' => ['2026-03-28 12:00:00', '2026-03-30 12:00:00', 2];

        yield 'fall back in Lisbon' => ['2026-10-24 12:00:00', '2026-10-26 12:00:00', 2];
    }

    #[Test]
    public function overdue_days_are_never_negative(): void
    {
        // Arrange
        $dueAt     = $this->inLisbon('2026-06-01 10:00:00');
        $reference = $this->inLisbon('2025-01-01 10:00:00');

        // Act
        $days = OverdueDays::calculate($dueAt, $reference);

        // Assert
        self::assertSame(0, $days);
    }

    #[Test]
    public function spring_forward_does_not_shorten_the_calendar_count(): void
    {
        // Arrange: Lisbon skips from 01:00 to 02:00 on 2026-03-29, so 28 -> 30 is 47 wall-clock hours.
        $dueAt     = $this->inLisbon('2026-03-28 12:00:00');
        $reference = $this->inLisbon('2026-03-30 12:00:00');

        // Act
        $days = OverdueDays::calculate($dueAt, $reference);

        // Assert
        self::assertSame(2, $days);
    }

    private function inLisbon(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date, new \DateTimeZone('Europe/Lisbon'));
    }
}
