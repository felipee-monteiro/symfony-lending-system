<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\Item\BorrowerId;
use App\Domain\Item\Item;
use App\Domain\Item\ItemId;
use App\Domain\Reservation\Reservation;
use App\Domain\Reservation\ReservationId;
use App\Domain\Reservation\ReservationStatus;
use App\Repository\DoctrineItemRepository;
use App\Repository\DoctrineReservationRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DoctrineReservationRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    private DoctrineReservationRepository $reservations;

    private DoctrineItemRepository $items;

    protected function setUp(): void
    {
        self::bootKernel();

        $container           = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->reservations  = $container->get(DoctrineReservationRepository::class);
        $this->items         = $container->get(DoctrineItemRepository::class);

        // Test-only schema: production/dev changes go through migrations.
        $this->entityManager->getConfiguration()->setUseSchemaEditorApi(true);
        (new SchemaTool($this->entityManager))->createSchema(
            $this->entityManager->getMetadataFactory()->getAllMetadata(),
        );

        // SQLitePlatform does not emit partial indexes, so the SchemaTool creates a full
        // unique index. Replace it with the same index the migration declares.
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('DROP INDEX IF EXISTS uniq_reservation_active');
        $connection->executeStatement(
            'CREATE UNIQUE INDEX uniq_reservation_active ON reservation (item_id, borrower_id) '
            ."WHERE status IN ('pending', 'notified')",
        );

        $this->items->save(new Item(ItemId::fromString('item-1'), 'Cordless drill'));
    }

    public function test_it_persists_and_reloads_the_aggregate(): void
    {
        // Arrange
        $reservedAt  = new \DateTimeImmutable('2026-02-01 10:00:00');
        $reservation = $this->reservation('reservation-1', 'item-1', 'borrower-1', $reservedAt);

        // Act
        $this->reservations->save($reservation);
        $this->entityManager->clear();

        // Assert
        $loaded = $this->reservations->find(ReservationId::fromString('reservation-1'));

        self::assertInstanceOf(Reservation::class, $loaded);
        self::assertSame('reservation-1', $loaded->id()->toString());
        self::assertSame('item-1', $loaded->itemId()->toString());
        self::assertSame('borrower-1', $loaded->borrowerId()->toString());
        self::assertSame(ReservationStatus::Pending, $loaded->status());
        self::assertEquals($reservedAt, $loaded->reservedAt());
        self::assertNull($loaded->notifiedAt());
    }

    public function test_it_persists_the_notified_state_and_date(): void
    {
        // Arrange
        $reservation = $this->reservation(
            'reservation-1',
            'item-1',
            'borrower-1',
            new \DateTimeImmutable('2026-02-01 10:00:00'),
        );
        $notifiedAt = new \DateTimeImmutable('2026-02-05 09:30:00');
        $this->reservations->save($reservation);

        // Act
        $reservation->markNotified($notifiedAt);
        $this->reservations->save($reservation);
        $this->entityManager->clear();

        // Assert
        $loaded = $this->reservations->find(ReservationId::fromString('reservation-1'));

        self::assertInstanceOf(Reservation::class, $loaded);
        self::assertSame(ReservationStatus::Notified, $loaded->status());
        self::assertEquals($notifiedAt, $loaded->notifiedAt());
    }

    public function test_the_queue_is_ordered_by_reserved_at_then_id(): void
    {
        // Arrange
        $reservedAt = new \DateTimeImmutable('2026-02-01 10:00:00');
        // Saved out of order on purpose: order must come from the database, not insertion.
        $this->reservations->save($this->reservation('reservation-2', 'item-1', 'borrower-2', $reservedAt));
        $this->reservations->save($this->reservation('reservation-3', 'item-1', 'borrower-3', $reservedAt));
        $this->reservations->save($this->reservation('reservation-1', 'item-1', 'borrower-1', $reservedAt));
        $this->entityManager->clear();

        // Act
        $next = $this->reservations->nextPendingFor(
            ItemId::fromString('item-1'),
            new \DateTimeImmutable('2026-02-10 00:00:00'),
        );

        // Assert
        self::assertInstanceOf(Reservation::class, $next);
        self::assertSame('reservation-1', $next->id()->toString());
    }

    public function test_a_pending_reservation_placed_after_the_return_is_not_returned(): void
    {
        // Arrange
        $this->reservations->save($this->reservation(
            'reservation-1',
            'item-1',
            'borrower-1',
            new \DateTimeImmutable('2026-02-05 10:00:00'),
        ));
        $this->reservations->save($this->reservation(
            'reservation-2',
            'item-1',
            'borrower-2',
            new \DateTimeImmutable('2026-02-06 10:00:00'),
        ));
        $this->entityManager->clear();

        // Act
        $next = $this->reservations->nextPendingFor(
            ItemId::fromString('item-1'),
            new \DateTimeImmutable('2026-02-05 12:00:00'),
        );

        // Assert
        self::assertInstanceOf(Reservation::class, $next);
        self::assertSame('reservation-1', $next->id()->toString());
    }

    public function test_there_is_no_next_pending_reservation_when_the_queue_is_empty(): void
    {
        $next = $this->reservations->nextPendingFor(
            ItemId::fromString('item-1'),
            new \DateTimeImmutable('2026-02-10 00:00:00'),
        );

        self::assertNull($next);
    }

    public function test_a_pending_reservation_is_active(): void
    {
        // Arrange
        $this->reservations->save($this->reservation(
            'reservation-1',
            'item-1',
            'borrower-1',
            new \DateTimeImmutable('2026-02-01 10:00:00'),
        ));

        // Act & Assert
        self::assertTrue($this->reservations->hasActiveFor(
            ItemId::fromString('item-1'),
            BorrowerId::fromString('borrower-1'),
        ));
    }

    public function test_a_notified_reservation_is_still_active(): void
    {
        // Arrange
        $reservation = $this->reservation(
            'reservation-1',
            'item-1',
            'borrower-1',
            new \DateTimeImmutable('2026-02-01 10:00:00'),
        );
        $reservation->markNotified(new \DateTimeImmutable('2026-02-05 09:30:00'));
        $this->reservations->save($reservation);

        // Act & Assert
        self::assertTrue($this->reservations->hasActiveFor(
            ItemId::fromString('item-1'),
            BorrowerId::fromString('borrower-1'),
        ));
    }

    public function test_a_cancelled_reservation_is_not_active(): void
    {
        // Arrange
        $reservation = $this->reservation(
            'reservation-1',
            'item-1',
            'borrower-1',
            new \DateTimeImmutable('2026-02-01 10:00:00'),
        );
        $reservation->cancel();
        $this->reservations->save($reservation);

        // Act & Assert
        self::assertFalse($this->reservations->hasActiveFor(
            ItemId::fromString('item-1'),
            BorrowerId::fromString('borrower-1'),
        ));
    }

    public function test_another_borrower_is_not_queued(): void
    {
        // Arrange
        $this->reservations->save($this->reservation(
            'reservation-1',
            'item-1',
            'borrower-1',
            new \DateTimeImmutable('2026-02-01 10:00:00'),
        ));

        // Act & Assert
        self::assertFalse($this->reservations->hasActiveFor(
            ItemId::fromString('item-1'),
            BorrowerId::fromString('borrower-2'),
        ));
    }

    public function test_two_active_reservations_for_the_same_item_and_borrower_are_rejected_by_the_database(): void
    {
        // Arrange
        $this->reservations->save($this->reservation(
            'reservation-1',
            'item-1',
            'borrower-1',
            new \DateTimeImmutable('2026-02-01 10:00:00'),
        ));

        // Act & Assert
        $this->expectException(UniqueConstraintViolationException::class);

        $this->reservations->save($this->reservation(
            'reservation-2',
            'item-1',
            'borrower-1',
            new \DateTimeImmutable('2026-02-02 10:00:00'),
        ));
    }

    public function test_a_cancelled_reservation_frees_the_slot_for_a_new_active_one(): void
    {
        // Arrange
        $cancelled = $this->reservation(
            'reservation-1',
            'item-1',
            'borrower-1',
            new \DateTimeImmutable('2026-02-01 10:00:00'),
        );
        $cancelled->cancel();
        $this->reservations->save($cancelled);

        // Act
        $this->reservations->save($this->reservation(
            'reservation-2',
            'item-1',
            'borrower-1',
            new \DateTimeImmutable('2026-02-02 10:00:00'),
        ));

        // Assert
        self::assertTrue($this->reservations->hasActiveFor(
            ItemId::fromString('item-1'),
            BorrowerId::fromString('borrower-1'),
        ));
    }

    private function reservation(
        string $id,
        string $itemId,
        string $borrowerId,
        \DateTimeImmutable $reservedAt,
    ): Reservation {
        return Reservation::place(
            ReservationId::fromString($id),
            ItemId::fromString($itemId),
            BorrowerId::fromString($borrowerId),
            $reservedAt,
        );
    }
}
