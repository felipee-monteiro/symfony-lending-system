<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Item;

use App\Domain\Item\Period;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PeriodTest extends TestCase
{
    #[Test]
    public function a_period_cannot_be_due_before_it_starts(): void
    {
        // Arrange
        $lentAt = new \DateTimeImmutable('2026-01-10 10:00:00');

        // Act & Assert
        $this->expectException(\InvalidArgumentException::class);

        Period::startedAt($lentAt, new \DateTimeImmutable('2026-01-10 09:59:59'));
    }

    #[Test]
    public function a_period_may_be_due_at_the_same_instant_it_starts(): void
    {
        // Arrange
        $at = new \DateTimeImmutable('2026-01-10 10:00:00');

        // Act
        $period = Period::startedAt($at, $at);

        // Assert
        self::assertTrue($period->isOpen());
        self::assertSame($at, $period->dueAt());
    }

    #[Test]
    public function ending_a_period_keeps_its_original_due_date(): void
    {
        // Arrange
        $lentAt     = new \DateTimeImmutable('2026-01-01 10:00:00');
        $dueAt      = new \DateTimeImmutable('2026-01-08 10:00:00');
        $returnedAt = new \DateTimeImmutable('2026-01-05 10:00:00');
        $period     = Period::startedAt($lentAt, $dueAt);

        // Act
        $closed = $period->endedAt($returnedAt);

        // Assert
        self::assertNotSame($period, $closed);
        self::assertTrue($period->isOpen(), 'The original period must stay untouched.');
        self::assertSame($lentAt, $closed->lentAt());
        self::assertSame($dueAt, $closed->dueAt());
        self::assertSame($returnedAt, $closed->returnedAt());
        self::assertTrue($closed->isClosed());
    }

    #[Test]
    public function a_period_may_be_returned_after_its_due_date(): void
    {
        // Arrange
        $period = Period::startedAt(
            new \DateTimeImmutable('2026-01-01 10:00:00'),
            new \DateTimeImmutable('2026-01-08 10:00:00'),
        );
        $returnedAt = new \DateTimeImmutable('2026-01-15 10:00:00');

        // Act
        $closed = $period->endedAt($returnedAt);

        // Assert
        self::assertTrue($closed->isClosed());
        self::assertSame($returnedAt, $closed->returnedAt());
    }

    #[Test]
    public function a_period_cannot_end_before_it_starts(): void
    {
        // Arrange
        $period = Period::startedAt(
            new \DateTimeImmutable('2026-01-10 10:00:00'),
            new \DateTimeImmutable('2026-01-17 10:00:00'),
        );

        // Act
        try {
            $period->endedAt(new \DateTimeImmutable('2026-01-09 10:00:00'));
            self::fail('A period must not end before it starts.');
        } catch (\InvalidArgumentException) {
            // expected
        }

        // Assert
        self::assertTrue($period->isOpen(), 'The failed return must not change the period.');
    }

    #[Test]
    public function a_period_may_be_due_at_the_same_instant_it_ends(): void
    {
        // Arrange
        $at     = new \DateTimeImmutable('2026-01-10 10:00:00');
        $period = Period::startedAt($at, $at);

        // Act
        $closed = $period->endedAt($at);

        // Assert
        self::assertTrue($closed->isClosed());
        self::assertSame($at, $closed->dueAt());
    }
}
