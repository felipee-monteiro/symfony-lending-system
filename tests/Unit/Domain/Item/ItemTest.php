<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Item;

use App\Domain\Item\BorrowerId;
use App\Domain\Item\Event\ItemReturned;
use App\Domain\Item\Item;
use App\Domain\Item\ItemId;
use App\Domain\Item\ItemStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ItemTest extends TestCase
{
    #[Test]
    public function a_new_item_is_available_and_has_no_loans(): void
    {
        $item = $this->item();

        self::assertSame(ItemStatus::Available, $item->status());
        self::assertSame([], $item->loans());
    }

    #[Test]
    public function a_new_item_can_be_lent_with_a_due_date(): void
    {
        // Arrange
        $item     = $this->item();
        $borrower = BorrowerId::fromString('borrower-1');
        $lentAt   = new \DateTimeImmutable('2026-01-01 10:00:00');
        $dueAt    = new \DateTimeImmutable('2026-01-08 10:00:00');

        // Act
        $item->lendTo($borrower, $lentAt, $dueAt);

        // Assert
        self::assertSame(ItemStatus::Lent, $item->status());
        self::assertCount(1, $item->loans());

        $loan = $item->loans()[0];
        self::assertTrue($loan->borrowerId()->equals($borrower));
        self::assertSame($lentAt, $loan->period()->lentAt());
        self::assertSame($dueAt, $loan->period()->dueAt());
        self::assertNull($loan->period()->returnedAt());
    }

    #[Test]
    public function a_lent_item_throws_when_lent_again(): void
    {
        // Arrange
        $item = $this->item();
        $item->lendTo(
            BorrowerId::fromString('borrower-1'),
            new \DateTimeImmutable('2026-01-01 10:00:00'),
            new \DateTimeImmutable('2026-01-08 10:00:00'),
        );

        // Act & Assert
        try {
            $item->lendTo(
                BorrowerId::fromString('borrower-2'),
                new \DateTimeImmutable('2026-01-02 10:00:00'),
                new \DateTimeImmutable('2026-01-09 10:00:00'),
            );
            self::fail('A lent item must not be lent again.');
        } catch (\DomainException $exception) {
            self::assertSame('Item is already lent.', $exception->getMessage());
        }

        self::assertSame(ItemStatus::Lent, $item->status());
        self::assertCount(1, $item->loans(), 'A rejected lend must not record a loan.');
    }

    #[Test]
    public function a_lent_item_can_be_returned(): void
    {
        // Arrange
        $item       = $this->item();
        $returnedAt = new \DateTimeImmutable('2026-01-05 10:00:00');
        $item->lendTo(
            BorrowerId::fromString('borrower-1'),
            new \DateTimeImmutable('2026-01-01 10:00:00'),
            new \DateTimeImmutable('2026-01-08 10:00:00'),
        );

        // Act
        $item->returnFrom($returnedAt);

        // Assert
        self::assertSame(ItemStatus::Available, $item->status());
        self::assertCount(1, $item->loans());
        self::assertFalse($item->loans()[0]->isOpen());
        self::assertSame($returnedAt, $item->loans()[0]->period()->returnedAt());
    }

    #[Test]
    public function an_available_item_throws_when_returned(): void
    {
        // Arrange
        $item = $this->item();

        // Act & Assert
        try {
            $item->returnFrom(new \DateTimeImmutable('2026-01-05 10:00:00'));
            self::fail('An available item must not be returned.');
        } catch (\DomainException $exception) {
            self::assertSame('Item is not lent.', $exception->getMessage());
        }

        self::assertSame(ItemStatus::Available, $item->status());
        self::assertSame([], $item->loans(), 'A rejected return must not record a loan.');
    }

    #[Test]
    public function lending_then_returning_leaves_the_item_available(): void
    {
        // Arrange
        $item = $this->item();

        // Act
        $item->lendTo(
            BorrowerId::fromString('borrower-1'),
            new \DateTimeImmutable('2026-01-01 10:00:00'),
            new \DateTimeImmutable('2026-01-08 10:00:00'),
        );
        $item->returnFrom(new \DateTimeImmutable('2026-01-05 10:00:00'));

        // Assert
        self::assertSame(ItemStatus::Available, $item->status());
    }

    #[Test]
    public function it_keeps_the_previous_loans_when_lent_again(): void
    {
        // Arrange
        $item = $this->item();
        $item->lendTo(
            BorrowerId::fromString('borrower-1'),
            new \DateTimeImmutable('2026-01-01 10:00:00'),
            new \DateTimeImmutable('2026-01-08 10:00:00'),
        );
        $item->returnFrom(new \DateTimeImmutable('2026-01-05 10:00:00'));

        // Act
        $item->lendTo(
            BorrowerId::fromString('borrower-2'),
            new \DateTimeImmutable('2026-01-10 10:00:00'),
            new \DateTimeImmutable('2026-01-17 10:00:00'),
        );

        // Assert
        self::assertSame(ItemStatus::Lent, $item->status());
        self::assertCount(2, $item->loans());
        self::assertFalse($item->loans()[0]->isOpen());
        self::assertTrue($item->loans()[1]->isOpen());
        self::assertSame('borrower-2', $item->loans()[1]->borrowerId()->toString());
        self::assertEquals(
            new \DateTimeImmutable('2026-01-17 10:00:00'),
            $item->loans()[1]->period()->dueAt(),
        );
    }

    #[Test]
    public function renewing_an_available_item_throws_and_keeps_the_state(): void
    {
        // Arrange
        $item = $this->item();

        // Act & Assert
        try {
            $item->renewLoan(
                new \DateTimeImmutable('2026-01-15 10:00:00'),
                new \DateTimeImmutable('2026-01-07 09:00:00'),
            );
            self::fail('An available item cannot be renewed.');
        } catch (\DomainException $exception) {
            self::assertSame('Item não está emprestado', $exception->getMessage());
        }

        self::assertSame(ItemStatus::Available, $item->status());
        self::assertNull($item->currentLoan());
        self::assertSame([], $item->loans());
    }

    #[Test]
    public function renewing_a_lent_item_extends_the_current_loan_due_date(): void
    {
        // Arrange
        $item = $this->item();
        $item->lendTo(
            BorrowerId::fromString('borrower-1'),
            new \DateTimeImmutable('2026-01-01 10:00:00'),
            new \DateTimeImmutable('2026-01-08 10:00:00'),
        );
        $newDueAt = new \DateTimeImmutable('2026-01-22 10:00:00');

        // Act
        $item->renewLoan($newDueAt, new \DateTimeImmutable('2026-01-07 09:00:00'));

        // Assert
        self::assertSame(ItemStatus::Lent, $item->status());
        self::assertCount(1, $item->loans());
        self::assertEquals(new \DateTimeImmutable('2026-01-08 10:00:00'), $item->currentLoan()->period()->dueAt());
        self::assertEquals($newDueAt, $item->currentLoan()->effectiveDueAt());
        self::assertCount(1, $item->currentLoan()->renewals());
    }

    #[Test]
    public function an_invalid_renewal_on_a_lent_item_throws_and_keeps_the_history(): void
    {
        // Arrange
        $item = $this->item();
        $item->lendTo(
            BorrowerId::fromString('borrower-1'),
            new \DateTimeImmutable('2026-01-01 10:00:00'),
            new \DateTimeImmutable('2026-01-08 10:00:00'),
        );

        // Act
        try {
            $item->renewLoan(
                new \DateTimeImmutable('2026-01-08 10:00:00'),
                new \DateTimeImmutable('2026-01-07 09:00:00'),
            );
            self::fail('A renewal must extend the due date.');
        } catch (\DomainException $exception) {
            // Assert the Loan rule is the one that rejects it.
            self::assertSame('A renovação deve estender o prazo', $exception->getMessage());
        }

        // Assert
        self::assertSame(ItemStatus::Lent, $item->status());
        self::assertCount(1, $item->loans());
        self::assertSame([], $item->currentLoan()->renewals());
    }

    #[Test]
    public function returning_a_renewed_loan_keeps_the_renewals_in_the_history(): void
    {
        // Arrange
        $item = $this->item();
        $item->lendTo(
            BorrowerId::fromString('borrower-1'),
            new \DateTimeImmutable('2026-01-01 10:00:00'),
            new \DateTimeImmutable('2026-01-08 10:00:00'),
        );
        $newDueAt = new \DateTimeImmutable('2026-01-22 10:00:00');
        $item->renewLoan($newDueAt, new \DateTimeImmutable('2026-01-07 09:00:00'));
        $returnedAt = new \DateTimeImmutable('2026-01-20 10:00:00');

        // Act
        $item->returnFrom($returnedAt);

        // Assert
        self::assertSame(ItemStatus::Available, $item->status());
        self::assertNull($item->currentLoan());

        $loan = $item->loans()[0];
        self::assertFalse($loan->isOpen());
        self::assertSame($returnedAt, $loan->period()->returnedAt());
        self::assertEquals($newDueAt, $loan->effectiveDueAt());
        self::assertCount(1, $loan->renewals());
    }

    #[Test]
    public function lending_again_after_a_renewed_loan_keeps_the_previous_history(): void
    {
        // Arrange
        $item = $this->item();
        $item->lendTo(
            BorrowerId::fromString('borrower-1'),
            new \DateTimeImmutable('2026-01-01 10:00:00'),
            new \DateTimeImmutable('2026-01-08 10:00:00'),
        );
        $renewedDueAt = new \DateTimeImmutable('2026-01-22 10:00:00');
        $item->renewLoan($renewedDueAt, new \DateTimeImmutable('2026-01-07 09:00:00'));
        $item->returnFrom(new \DateTimeImmutable('2026-02-01 10:00:00'));

        // Act
        $item->lendTo(
            BorrowerId::fromString('borrower-2'),
            new \DateTimeImmutable('2026-02-10 10:00:00'),
            new \DateTimeImmutable('2026-02-17 10:00:00'),
        );

        // Assert
        self::assertSame(ItemStatus::Lent, $item->status());
        self::assertCount(2, $item->loans());

        $previous = $item->loans()[0];
        self::assertFalse($previous->isOpen());
        self::assertCount(1, $previous->renewals());
        self::assertEquals($renewedDueAt, $previous->effectiveDueAt());

        self::assertSame($item->loans()[1], $item->currentLoan());
        self::assertTrue($item->currentLoan()->isOpen());
        self::assertSame([], $item->currentLoan()->renewals());
    }

    #[Test]
    public function returning_a_lent_item_records_an_item_returned_event(): void
    {
        // Arrange
        $item = $this->item();
        $item->lendTo(
            BorrowerId::fromString('borrower-1'),
            new \DateTimeImmutable('2026-01-01 10:00:00'),
            new \DateTimeImmutable('2026-01-08 10:00:00'),
        );
        $returnedAt = new \DateTimeImmutable('2026-01-05 10:00:00');

        // Act
        $item->returnFrom($returnedAt);

        // Assert
        $events = $item->releaseEvents();

        self::assertCount(1, $events);
        self::assertInstanceOf(ItemReturned::class, $events[0]);
        self::assertTrue($events[0]->itemId->equals($item->id()));
        self::assertSame($returnedAt, $events[0]->returnedAt);
    }

    #[Test]
    public function releasing_events_returns_them_only_once(): void
    {
        // Arrange
        $item = $this->item();
        $item->lendTo(
            BorrowerId::fromString('borrower-1'),
            new \DateTimeImmutable('2026-01-01 10:00:00'),
            new \DateTimeImmutable('2026-01-08 10:00:00'),
        );
        $item->returnFrom(new \DateTimeImmutable('2026-01-05 10:00:00'));

        // Act
        $first  = $item->releaseEvents();
        $second = $item->releaseEvents();

        // Assert
        self::assertCount(1, $first);
        self::assertSame([], $second);
    }

    #[Test]
    public function a_rejected_return_records_no_event(): void
    {
        // Arrange
        $item = $this->item();

        // Act & Assert
        try {
            $item->returnFrom(new \DateTimeImmutable('2026-01-05 10:00:00'));
            self::fail('An available item must not be returned.');
        } catch (\DomainException) {
            // Expected: the state assertion below is what matters.
        }

        self::assertSame([], $item->releaseEvents());
    }

    private function item(): Item
    {
        return new Item(ItemId::fromString('item-1'), 'Cordless drill');
    }
}
