<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\Reservation\ReserveItemHandler;
use App\Domain\Item\BorrowerId;
use App\Domain\Item\Item;
use App\Domain\Item\ItemId;
use App\Domain\Reservation\Exception\BorrowerAlreadyQueued;
use App\Domain\Reservation\Exception\ItemIsAvailableForReservation;
use App\Domain\Reservation\Reservation;
use App\Domain\Reservation\ReservationStatus;
use App\Repository\DoctrineItemRepository;
use App\Repository\DoctrineReservationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

final class ReserveItemHandlerTest extends KernelTestCase
{
    private const string FROZEN_NOW = '2026-02-01 09:00:00';

    private EntityManagerInterface $entityManager;

    private DoctrineItemRepository $items;

    private DoctrineReservationRepository $reservations;

    private MockClock $clock;

    private ReserveItemHandler $handler;

    protected function setUp(): void
    {
        self::bootKernel();

        $container           = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->items         = $container->get(DoctrineItemRepository::class);
        $this->reservations  = $container->get(DoctrineReservationRepository::class);

        $this->entityManager->getConfiguration()->setUseSchemaEditorApi(true);
        (new SchemaTool($this->entityManager))->createSchema(
            $this->entityManager->getMetadataFactory()->getAllMetadata(),
        );

        // Same partial index the migration declares (the SchemaTool cannot emit it on SQLite).
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('DROP INDEX IF EXISTS uniq_reservation_active');
        $connection->executeStatement(
            'CREATE UNIQUE INDEX uniq_reservation_active ON reservation (item_id, borrower_id) '
            ."WHERE status IN ('pending', 'notified')",
        );

        $this->clock   = new MockClock(new \DateTimeImmutable(self::FROZEN_NOW));
        $this->handler = new ReserveItemHandler($this->reservations, $this->items, $this->clock);
    }

    public function test_a_borrower_can_reserve_a_lent_item(): void
    {
        // Arrange
        $itemId   = $this->lentItem();
        $borrower = BorrowerId::fromString('borrower-1');

        // Act
        ($this->handler)($itemId, $borrower);
        $this->entityManager->clear();

        // Assert
        $reservation = $this->reservations->nextPendingFor(
            $itemId,
            new \DateTimeImmutable('2026-02-02 00:00:00'),
        );

        self::assertInstanceOf(Reservation::class, $reservation);
        self::assertSame(ReservationStatus::Pending, $reservation->status());
        self::assertTrue($reservation->borrowerId()->equals($borrower));
        self::assertEquals(new \DateTimeImmutable(self::FROZEN_NOW), $reservation->reservedAt());
    }

    public function test_an_available_item_cannot_be_reserved(): void
    {
        // Arrange
        $item = new Item(ItemId::fromString('item-1'), 'Cordless drill');
        $this->items->save($item);

        // Act & Assert
        try {
            ($this->handler)($item->id(), BorrowerId::fromString('borrower-1'));
            self::fail('An available item must not be reserved.');
        } catch (ItemIsAvailableForReservation) {
            // Expected: the state assertion below is what matters.
        }

        self::assertSame(0, $this->reservationCount(), 'A rejected reservation must not be persisted.');
    }

    public function test_the_same_borrower_cannot_reserve_the_same_item_twice(): void
    {
        // Arrange
        $itemId   = $this->lentItem();
        $borrower = BorrowerId::fromString('borrower-1');
        ($this->handler)($itemId, $borrower);

        // Act & Assert
        try {
            ($this->handler)($itemId, $borrower);
            self::fail('A borrower must not queue twice for the same item.');
        } catch (BorrowerAlreadyQueued) {
            // Expected.
        }

        self::assertSame(1, $this->reservationCount(), 'The duplicate must not be persisted.');
    }

    public function test_reserving_an_unknown_item_fails(): void
    {
        // Act & Assert
        try {
            ($this->handler)(
                ItemId::fromString('missing-item'),
                BorrowerId::fromString('borrower-1'),
            );
            self::fail('An unknown item must not be reserved.');
        } catch (\DomainException $exception) {
            self::assertSame('Item não encontrado', $exception->getMessage());
        }

        self::assertSame(0, $this->reservationCount());
    }

    public function test_the_queue_is_served_in_creation_order(): void
    {
        // Arrange
        $itemId = $this->lentItem();
        $first  = BorrowerId::fromString('borrower-a');

        // Act
        ($this->handler)($itemId, $first);
        $this->clock->modify('+1 day');
        ($this->handler)($itemId, BorrowerId::fromString('borrower-b'));
        $this->entityManager->clear();

        // Assert
        $next = $this->reservations->nextPendingFor($itemId, $this->clock->now());

        self::assertInstanceOf(Reservation::class, $next);
        self::assertTrue($next->borrowerId()->equals($first));
        self::assertSame(ReservationStatus::Pending, $next->status());
        self::assertSame(2, $this->reservationCount());
        self::assertTrue($this->reservations->hasActiveFor($itemId, $first));
        self::assertTrue($this->reservations->hasActiveFor($itemId, BorrowerId::fromString('borrower-b')));
    }

    private function lentItem(): ItemId
    {
        $item = new Item(ItemId::fromString('item-1'), 'Cordless drill');
        $item->lendTo(
            BorrowerId::fromString('current-holder'),
            new \DateTimeImmutable('2026-01-01 10:00:00'),
            new \DateTimeImmutable('2026-01-08 10:00:00'),
        );
        $this->items->save($item);

        return $item->id();
    }

    private function reservationCount(): int
    {
        return (int) $this->entityManager
            ->getConnection()
            ->fetchOne('SELECT COUNT(*) FROM reservation');
    }
}
