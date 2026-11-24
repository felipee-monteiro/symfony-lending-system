<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\Item\ReturnItemHandler;
use App\Application\Reservation\NotifyNextInQueue;
use App\Domain\Item\BorrowerId;
use App\Domain\Item\Item;
use App\Domain\Item\ItemId;
use App\Domain\Item\ItemStatus;
use App\Domain\Reservation\Reservation;
use App\Domain\Reservation\ReservationId;
use App\Domain\Reservation\ReservationStatus;
use App\Repository\DoctrineItemRepository;
use App\Repository\DoctrineReservationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class ReturnItemHandlerTest extends KernelTestCase
{
    private const string FROZEN_NOW = '2026-02-10 09:00:00';

    private EntityManagerInterface $entityManager;

    private DoctrineItemRepository $items;

    private DoctrineReservationRepository $reservations;

    private MockClock $clock;

    private InMemoryTransport $transport;

    private ReturnItemHandler $handler;

    protected function setUp(): void
    {
        self::bootKernel();

        $container           = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->items         = $container->get(DoctrineItemRepository::class);
        $this->reservations  = $container->get(DoctrineReservationRepository::class);
        $this->transport     = $container->get('messenger.transport.async');

        $this->entityManager->getConfiguration()->setUseSchemaEditorApi(true);
        (new SchemaTool($this->entityManager))->createSchema(
            $this->entityManager->getMetadataFactory()->getAllMetadata(),
        );

        $this->clock   = new MockClock(new \DateTimeImmutable(self::FROZEN_NOW));
        $this->handler = new ReturnItemHandler(
            $this->items,
            $this->clock,
            $container->get(MessageBusInterface::class),
        );
    }

    public function test_returning_an_item_dispatches_one_notification_message(): void
    {
        // Arrange
        $itemId = $this->lentItem();

        $this->reservations->save(Reservation::place(
            ReservationId::fromString('reservation-1'),
            $itemId,
            BorrowerId::fromString('borrower-1'),
            new \DateTimeImmutable('2026-02-09 10:00:00'),
        ));

        // Act
        ($this->handler)($itemId);

        // Assert
        $sent = $this->transport->getSent();

        self::assertCount(1, $sent);

        $message = $sent[0]->getMessage();
        self::assertInstanceOf(NotifyNextInQueue::class, $message);
        self::assertTrue($message->itemId->equals($itemId));
        self::assertEquals(new \DateTimeImmutable(self::FROZEN_NOW), $message->returnedAt);
    }

    public function test_the_item_is_returned_before_the_message_is_dispatched(): void
    {
        // Arrange
        $itemId = $this->lentItem();

        // Act
        ($this->handler)($itemId);
        $this->entityManager->clear();

        // Assert
        $loaded = $this->items->get($itemId);
        self::assertSame(ItemStatus::Available, $loaded->status());
        self::assertCount(1, $this->transport->getSent());
    }

    public function test_returning_an_item_does_not_notify_the_queue_synchronously(): void
    {
        // Arrange
        $itemId = $this->lentItem();
        $this->reservations->save(Reservation::place(
            ReservationId::fromString('reservation-1'),
            $itemId,
            BorrowerId::fromString('borrower-1'),
            new \DateTimeImmutable('2026-02-09 10:00:00'),
        ));

        // Act
        ($this->handler)($itemId);
        $this->entityManager->clear();

        // Assert: the async transport only queues; the worker has not run yet.
        $loaded = $this->reservations->find(ReservationId::fromString('reservation-1'));
        self::assertInstanceOf(Reservation::class, $loaded);
        self::assertSame(ReservationStatus::Pending, $loaded->status());
        self::assertNull($loaded->notifiedAt());
    }

    private function lentItem(): ItemId
    {
        $item = new Item(ItemId::fromString('item-1'), 'Cordless drill');
        $item->lendTo(
            BorrowerId::fromString('current-holder'),
            new \DateTimeImmutable('2026-02-01 10:00:00'),
            new \DateTimeImmutable('2026-02-08 10:00:00'),
        );
        $this->items->save($item);

        return $item->id();
    }
}
