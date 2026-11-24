<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\Item\LendItemHandler;
use App\Application\Item\RegisterItemHandler;
use App\Application\Item\RenewLoanHandler;
use App\Application\Item\ReturnItemHandler;
use App\Domain\Item\BorrowerId;
use App\Domain\Item\ItemId;
use App\Domain\Item\ItemStatus;
use App\Repository\DoctrineItemRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Handlers are wired straight from the repository, the same way the container does it.
 * (They are not exposed as public services because no controller uses them yet, so the
 * compiler removes them from the container.)
 */
final class ItemHandlersTest extends KernelTestCase
{
    private const string FROZEN_NOW = '2026-02-01 09:00:00';

    private EntityManagerInterface $entityManager;

    private DoctrineItemRepository $items;

    private MockClock $clock;

    private MessageBusInterface $bus;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();

        // Set the clock before touching the bus/schema: building the schema instantiates
        // the Messenger transports, which capture the clock.
        $this->clock = new MockClock(new \DateTimeImmutable(self::FROZEN_NOW));
        $container->set(ClockInterface::class, $this->clock);

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->items         = $container->get(DoctrineItemRepository::class);
        $this->bus           = $container->get(MessageBusInterface::class);

        $this->entityManager->getConfiguration()->setUseSchemaEditorApi(true);
        (new SchemaTool($this->entityManager))->createSchema(
            $this->entityManager->getMetadataFactory()->getAllMetadata(),
        );
    }

    public function test_lend_records_the_clock_time_as_the_lent_date(): void
    {
        // Arrange
        $id = $this->register('Cordless drill');

        // Act
        $this->lend($id, '2026-02-08 09:00:00');

        // Assert
        $this->entityManager->clear();
        $loaded = $this->items->get($id);
        self::assertSame(ItemStatus::Lent, $loaded->status());
        self::assertSame('borrower-1', $loaded->loans()[0]->borrowerId()->toString());
        self::assertEquals(new \DateTimeImmutable(self::FROZEN_NOW), $loaded->loans()[0]->period()->lentAt());
        self::assertEquals(new \DateTimeImmutable('2026-02-08 09:00:00'), $loaded->loans()[0]->period()->dueAt());
    }

    public function test_return_records_the_clock_time_as_the_returned_date(): void
    {
        // Arrange
        $id = $this->register('Cordless drill');
        $this->lend($id, '2026-02-08 09:00:00');
        $this->clock->modify('+4 days');

        // Act
        (new ReturnItemHandler($this->items, $this->clock, $this->bus))($id);

        // Assert
        $this->entityManager->clear();
        $loaded = $this->items->get($id);
        self::assertSame(ItemStatus::Available, $loaded->status());
        self::assertCount(1, $loaded->loans());
        self::assertFalse($loaded->loans()[0]->isOpen());
        self::assertEquals(new \DateTimeImmutable('2026-02-05 09:00:00'), $loaded->loans()[0]->period()->returnedAt());
    }

    public function test_renew_records_the_clock_time_as_the_renewed_date_and_extends_the_loan(): void
    {
        // Arrange
        $id = $this->register('Cordless drill');
        $this->lend($id, '2026-02-08 09:00:00');
        $this->clock->modify('+3 days');
        $newDueAt = new \DateTimeImmutable('2026-02-15 09:00:00');

        // Act
        (new RenewLoanHandler($this->items, $this->clock))($id, $newDueAt);

        // Assert
        $this->entityManager->clear();
        $loaded = $this->items->get($id);
        $loan   = $loaded->loans()[0];

        self::assertSame(ItemStatus::Lent, $loaded->status());
        self::assertCount(1, $loaded->loans());
        self::assertCount(1, $loan->renewals());
        self::assertEquals(new \DateTimeImmutable('2026-02-04 09:00:00'), $loan->renewals()[0]->renewedAt());
        self::assertEquals($newDueAt, $loan->renewals()[0]->dueAt());
        self::assertEquals($newDueAt, $loan->effectiveDueAt());
        self::assertEquals(new \DateTimeImmutable('2026-02-08 09:00:00'), $loan->period()->dueAt());
    }

    public function test_lending_an_unknown_item_fails(): void
    {
        // Arrange
        $lend = new LendItemHandler($this->items, $this->clock);

        // Act & Assert
        $this->expectException(\RuntimeException::class);

        $lend(ItemId::fromString('missing'), BorrowerId::fromString('borrower-1'), new \DateTimeImmutable('2026-02-08 09:00:00'));
    }

    private function register(string $title): ItemId
    {
        $register = new RegisterItemHandler($this->items);

        return $register($title)->id();
    }

    private function lend(ItemId $id, string $dueAt): void
    {
        (new LendItemHandler($this->items, $this->clock))(
            $id,
            BorrowerId::fromString('borrower-1'),
            new \DateTimeImmutable($dueAt),
        );
    }
}
