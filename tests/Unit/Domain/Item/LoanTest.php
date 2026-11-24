<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Item;

use App\Domain\Item\BorrowerId;
use App\Domain\Item\Item;
use App\Domain\Item\ItemId;
use App\Domain\Item\Loan;
use App\Domain\Item\Period;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LoanTest extends TestCase
{
    #[Test]
    public function it_is_created_as_an_open_loan_for_the_borrower(): void
    {
        // Arrange
        $borrower = BorrowerId::fromString('borrower-1');
        $lentAt   = new \DateTimeImmutable('2026-01-01 10:00:00');

        // Act
        $loan = new Loan($this->item(), $borrower, Period::startedAt($lentAt, $lentAt->modify('+7 days')));

        // Assert
        self::assertSame($lentAt, $loan->period()->lentAt());
        self::assertNull($loan->period()->returnedAt());
        self::assertTrue($loan->isOpen());
        self::assertSame($borrower, $loan->borrowerId());
    }

    #[Test]
    public function it_marks_the_period_as_returned(): void
    {
        // Arrange
        $lentAt     = new \DateTimeImmutable('2026-01-01 10:00:00');
        $returnedAt = new \DateTimeImmutable('2026-01-05 10:00:00');
        $loan       = new Loan($this->item(), BorrowerId::fromString('borrower-1'), Period::startedAt($lentAt, $lentAt->modify('+7 days')));

        // Act
        $loan->markReturned($returnedAt);

        // Assert
        self::assertFalse($loan->isOpen());
        self::assertSame($lentAt, $loan->period()->lentAt());
        self::assertSame($returnedAt, $loan->period()->returnedAt());
    }

    #[Test]
    public function it_rejects_a_return_date_before_the_lent_date(): void
    {
        // Arrange
        $loan = $this->loan();

        // Act & Assert
        $this->expectException(\InvalidArgumentException::class);

        $loan->markReturned(new \DateTimeImmutable('2025-12-31 10:00:00'));
    }

    #[Test]
    public function a_new_loan_is_due_on_its_original_due_date(): void
    {
        // Arrange
        $loan = $this->loan();

        // Act
        $dueAt = $loan->effectiveDueAt();

        // Assert
        self::assertEquals($this->dueAt(), $dueAt);
        self::assertSame([], $loan->renewals());
    }

    #[Test]
    public function a_renewal_extends_the_effective_due_date_without_touching_the_period(): void
    {
        // Arrange
        $loan          = $this->loan();
        $originalDueAt = $loan->period()->dueAt();
        $newDueAt      = new \DateTimeImmutable('2026-01-22 10:00:00');
        $renewedAt     = new \DateTimeImmutable('2026-01-07 09:00:00');

        // Act
        $renewal = $loan->renew($newDueAt, $renewedAt);

        // Assert
        self::assertSame($loan, $renewal->loan());
        self::assertSame($newDueAt, $renewal->dueAt());
        self::assertSame($renewedAt, $renewal->renewedAt());
        self::assertCount(1, $loan->renewals());
        self::assertSame($newDueAt, $loan->effectiveDueAt());
        self::assertSame($originalDueAt, $loan->period()->dueAt(), 'The original period must not be rewritten.');
    }

    #[Test]
    #[DataProvider('provide_due_dates_that_do_not_extend_the_loan')]
    public function a_renewal_must_strictly_extend_the_effective_due_date(\DateTimeImmutable $newDueAt): void
    {
        // Arrange
        $loan = $this->loan();

        // Act & Assert
        try {
            $loan->renew($newDueAt, new \DateTimeImmutable('2026-01-07 09:00:00'));
            self::fail('A renewal must extend the due date.');
        } catch (\DomainException $exception) {
            self::assertSame('A renovação deve estender o prazo', $exception->getMessage());
        }

        self::assertSame([], $loan->renewals(), 'A rejected renewal must not be recorded.');
        self::assertEquals($this->dueAt(), $loan->effectiveDueAt());
    }

    /**
     * @return iterable<string, array{\DateTimeImmutable}>
     */
    public static function provide_due_dates_that_do_not_extend_the_loan(): iterable
    {
        yield 'same instant as the current due date' => [new \DateTimeImmutable('2026-01-08 10:00:00')];

        yield 'before the current due date' => [new \DateTimeImmutable('2026-01-08 09:59:59')];
    }

    #[Test]
    public function the_latest_renewal_wins(): void
    {
        // Arrange
        $loan      = $this->loan();
        $firstDue  = new \DateTimeImmutable('2026-01-15 10:00:00');
        $secondDue = new \DateTimeImmutable('2026-01-22 10:00:00');

        // Act
        $loan->renew($firstDue, new \DateTimeImmutable('2026-01-07 09:00:00'));
        $loan->renew($secondDue, new \DateTimeImmutable('2026-01-14 09:00:00'));

        // Assert
        self::assertCount(2, $loan->renewals());
        self::assertSame($secondDue, $loan->effectiveDueAt());
        self::assertEquals($this->dueAt(), $loan->period()->dueAt());
    }

    #[Test]
    public function a_returned_loan_cannot_be_renewed(): void
    {
        // Arrange
        $loan = $this->loan();
        $loan->markReturned(new \DateTimeImmutable('2026-01-05 10:00:00'));

        // Act & Assert
        try {
            $loan->renew(new \DateTimeImmutable('2026-01-22 10:00:00'), new \DateTimeImmutable('2026-01-06 09:00:00'));
            self::fail('A returned loan must not be renewed.');
        } catch (\DomainException $exception) {
            self::assertSame('A returned loan cannot be renewed.', $exception->getMessage());
        }

        self::assertSame([], $loan->renewals(), 'A rejected renewal must not be recorded.');
        self::assertEquals($this->dueAt(), $loan->effectiveDueAt());
    }

    #[Test]
    public function an_open_loan_is_not_late_before_its_due_date(): void
    {
        // Arrange
        $loan = $this->loan();

        // Act
        $daysLate = $loan->daysLateAt(new \DateTimeImmutable('2026-01-08 23:59:59'));

        // Assert
        self::assertSame(0, $daysLate);
    }

    #[Test]
    public function an_open_loan_counts_calendar_days_after_its_due_date(): void
    {
        // Arrange
        $loan = $this->loan();

        // Act
        $daysLate = $loan->daysLateAt(new \DateTimeImmutable('2026-01-11 00:00:00'));

        // Assert
        self::assertSame(3, $daysLate);
    }

    #[Test]
    public function a_renewal_pushes_the_lateness_reference_forward(): void
    {
        // Arrange
        $loan = $this->loan();
        $loan->renew(new \DateTimeImmutable('2026-01-15 10:00:00'), new \DateTimeImmutable('2026-01-07 09:00:00'));

        // Act
        $daysLate = $loan->daysLateAt(new \DateTimeImmutable('2026-01-18 10:00:00'));

        // Assert
        self::assertSame(3, $daysLate);
    }

    #[Test]
    public function a_returned_loan_is_measured_against_its_return_date(): void
    {
        // Arrange
        $loan = $this->loan();
        $loan->markReturned(new \DateTimeImmutable('2026-01-15 10:00:00'));

        // Act
        $daysLate = $loan->daysLateAt(new \DateTimeImmutable('2026-03-01 10:00:00'));

        // Assert
        self::assertSame(7, $daysLate);
    }

    #[Test]
    public function a_loan_returned_on_time_is_never_late(): void
    {
        // Arrange
        $loan = $this->loan();
        $loan->markReturned(new \DateTimeImmutable('2026-01-05 10:00:00'));

        // Act
        $daysLate = $loan->daysLateAt(new \DateTimeImmutable('2026-03-01 10:00:00'));

        // Assert
        self::assertSame(0, $daysLate);
    }

    private function loan(): Loan
    {
        return new Loan(
            $this->item(),
            BorrowerId::fromString('borrower-1'),
            Period::startedAt(new \DateTimeImmutable('2026-01-01 10:00:00'), $this->dueAt()),
        );
    }

    private function dueAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-01-08 10:00:00');
    }

    private function item(): Item
    {
        return new Item(ItemId::fromString('item-1'), 'Cordless drill');
    }
}
