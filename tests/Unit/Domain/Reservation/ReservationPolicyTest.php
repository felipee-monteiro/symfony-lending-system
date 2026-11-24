<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Reservation;

use App\Domain\Item\BorrowerId;
use App\Domain\Item\Item;
use App\Domain\Item\ItemId;
use App\Domain\Item\ItemStatus;
use App\Domain\Reservation\Exception\BorrowerAlreadyQueued;
use App\Domain\Reservation\Exception\ItemIsAvailableForReservation;
use App\Domain\Reservation\ReservationPolicy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReservationPolicyTest extends TestCase
{
    #[Test]
    public function a_lent_item_can_be_reserved_by_a_borrower_not_yet_queued(): void
    {
        // Arrange
        $item = $this->lentItem();

        // Act
        (new ReservationPolicy)->assertReservable($item, false);

        // Assert
        self::assertSame(ItemStatus::Lent, $item->status(), 'The policy must not change the item.');
    }

    #[Test]
    public function an_available_item_cannot_be_reserved(): void
    {
        // Arrange
        $item = new Item(ItemId::fromString('item-1'), 'Cordless drill');

        // Act & Assert
        try {
            (new ReservationPolicy)->assertReservable($item, false);
            self::fail('An available item must not be reserved.');
        } catch (ItemIsAvailableForReservation $exception) {
            self::assertSame(
                'Item "item-1" is available; borrow it instead of reserving.',
                $exception->getMessage(),
            );
        }
    }

    #[Test]
    public function a_borrower_already_queued_cannot_reserve_the_same_item_again(): void
    {
        // Arrange
        $item = $this->lentItem();

        // Act & Assert
        try {
            (new ReservationPolicy)->assertReservable($item, true);
            self::fail('A borrower must not queue twice for the same item.');
        } catch (BorrowerAlreadyQueued $exception) {
            self::assertSame('The borrower is already queued for item "item-1".', $exception->getMessage());
        }
    }

    private function lentItem(): Item
    {
        $item = new Item(ItemId::fromString('item-1'), 'Cordless drill');
        $item->lendTo(
            BorrowerId::fromString('borrower-1'),
            new \DateTimeImmutable('2026-02-01 10:00:00'),
            new \DateTimeImmutable('2026-02-08 10:00:00'),
        );

        return $item;
    }
}
