<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\Reservation\NotifyNextInQueue;
use App\Application\Reservation\NotifyNextInQueueHandler;
use App\Domain\Item\BorrowerId;
use App\Domain\Item\Item;
use App\Domain\Item\ItemId;
use App\Domain\Reservation\Reservation;
use App\Domain\Reservation\ReservationId;
use App\Domain\Reservation\ReservationStatus;
use App\Repository\DoctrineItemRepository;
use App\Repository\DoctrineReservationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

final class NotifyNextInQueueHandlerTest extends KernelTestCase
{
    private const string RETURNED_AT = '2026-02-10 09:00:00';

    private const string NOTIFIED_AT = '2026-02-10 09:05:00';

    private EntityManagerInterface $entityManager;

    private DoctrineItemRepository $items;

    private DoctrineReservationRepository $reservations;

    private MockClock $clock;

    private NotifyNextInQueueHandler $handler;

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

        $this->clock   = new MockClock(new \DateTimeImmutable(self::NOTIFIED_AT));
        $this->handler = new NotifyNextInQueueHandler($this->reservations, new NullLogger, $this->clock);
    }

    public function test_the_first_pending_reservation_is_notified(): void
    {
        // Arrange
        $itemId = $this->item();
        $this->queue($itemId, 'reservation-1', 'borrower-1', '2026-02-09 10:00:00');
        $this->entityManager->clear();

        // Act
        ($this->handler)($this->message($itemId));

        // Assert
        $loaded = $this->reservations->find(ReservationId::fromString('reservation-1'));

        self::assertInstanceOf(Reservation::class, $loaded);
        self::assertSame(ReservationStatus::Notified, $loaded->status());
        self::assertEquals(new \DateTimeImmutable(self::NOTIFIED_AT), $loaded->notifiedAt());
    }

    public function test_a_redelivered_message_does_not_notify_the_next_reservation(): void
    {
        // Arrange
        $itemId = $this->item();
        $this->queue($itemId, 'reservation-1', 'borrower-1', '2026-02-09 10:00:00');
        $this->queue($itemId, 'reservation-2', 'borrower-2', '2026-02-09 11:00:00');
        $this->entityManager->clear();

        $message = $this->message($itemId);
        ($this->handler)($message);
        $this->entityManager->clear();

        // Act: same message delivered again.
        ($this->handler)($message);
        $this->entityManager->clear();

        // Assert
        $second = $this->reservations->find(ReservationId::fromString('reservation-2'));

        self::assertInstanceOf(Reservation::class, $second);
        self::assertSame(ReservationStatus::Pending, $second->status(), 'A duplicate delivery must not move the queue.');
        self::assertNull($second->notifiedAt());
    }

    public function test_an_empty_queue_is_a_no_op(): void
    {
        // Arrange
        $itemId = $this->item();

        // Act
        ($this->handler)($this->message($itemId));

        // Assert
        self::assertSame(0, (int) $this->entityManager
            ->getConnection()
            ->fetchOne('SELECT COUNT(*) FROM reservation'));
    }

    public function test_a_reservation_placed_after_the_return_is_not_notified(): void
    {
        // Arrange
        $itemId = $this->item();
        $this->queue($itemId, 'reservation-1', 'borrower-1', '2026-02-10 12:00:00');
        $this->entityManager->clear();

        // Act
        ($this->handler)($this->message($itemId));
        $this->entityManager->clear();

        // Assert
        $loaded = $this->reservations->find(ReservationId::fromString('reservation-1'));

        self::assertInstanceOf(Reservation::class, $loaded);
        self::assertSame(ReservationStatus::Pending, $loaded->status());
        self::assertNull($loaded->notifiedAt());
    }

    private function item(): ItemId
    {
        $item = new Item(ItemId::fromString('item-1'), 'Cordless drill');
        $this->items->save($item);

        return $item->id();
    }

    private function queue(ItemId $itemId, string $id, string $borrower, string $reservedAt): void
    {
        $this->reservations->save(Reservation::place(
            ReservationId::fromString($id),
            $itemId,
            BorrowerId::fromString($borrower),
            new \DateTimeImmutable($reservedAt),
        ));
    }

    private function message(ItemId $itemId): NotifyNextInQueue
    {
        return new NotifyNextInQueue($itemId, new \DateTimeImmutable(self::RETURNED_AT));
    }
}
