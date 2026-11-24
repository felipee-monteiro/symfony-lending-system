<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\Item\BorrowerId;
use App\Domain\Item\Exception\ItemNotFound;
use App\Domain\Item\Item;
use App\Domain\Item\ItemId;
use App\Domain\Item\ItemStatus;
use App\Repository\DoctrineItemRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DoctrineItemRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    private DoctrineItemRepository $items;

    protected function setUp(): void
    {
        self::bootKernel();

        $container           = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->items         = $container->get(DoctrineItemRepository::class);

        // Test-only schema: production/dev changes go through migrations.
        $this->entityManager->getConfiguration()->setUseSchemaEditorApi(true);
        (new SchemaTool($this->entityManager))->createSchema(
            $this->entityManager->getMetadataFactory()->getAllMetadata(),
        );
    }

    public function test_it_persists_and_reloads_the_aggregate(): void
    {
        $item = new Item(ItemId::fromString('item-1'), 'Cordless drill');

        $this->items->save($item);
        $this->entityManager->clear();

        $loaded = $this->items->get(ItemId::fromString('item-1'));

        self::assertSame('item-1', $loaded->id()->toString());
        self::assertSame('Cordless drill', $loaded->title());
        self::assertSame(ItemStatus::Available, $loaded->status());
        self::assertSame([], $loaded->loans());
    }

    public function test_it_persists_loans_with_their_embeddable_period(): void
    {
        $item   = new Item(ItemId::fromString('item-1'), 'Cordless drill');
        $lentAt = new \DateTimeImmutable('2026-01-01 10:00:00');
        $dueAt  = new \DateTimeImmutable('2026-01-08 10:00:00');
        $item->lendTo(BorrowerId::fromString('borrower-1'), $lentAt, $dueAt);

        $this->items->save($item);
        $this->entityManager->clear();

        $loaded = $this->items->get(ItemId::fromString('item-1'));

        self::assertSame(ItemStatus::Lent, $loaded->status());
        self::assertCount(1, $loaded->loans());

        $loan = $loaded->loans()[0];
        self::assertSame('borrower-1', $loan->borrowerId()->toString());
        self::assertEquals($lentAt, $loan->period()->lentAt());
        self::assertEquals($dueAt, $loan->period()->dueAt());
        self::assertNull($loan->period()->returnedAt());
    }

    public function test_it_persists_renewals_as_new_rows_without_touching_the_loan(): void
    {
        // Arrange
        $item          = new Item(ItemId::fromString('item-1'), 'Cordless drill');
        $originalDueAt = new \DateTimeImmutable('2026-01-08 10:00:00');
        $item->lendTo(
            BorrowerId::fromString('borrower-1'),
            new \DateTimeImmutable('2026-01-01 10:00:00'),
            $originalDueAt,
        );
        $this->items->save($item);
        $this->entityManager->clear();

        // Act
        $loaded   = $this->items->get(ItemId::fromString('item-1'));
        $newDueAt = new \DateTimeImmutable('2026-01-22 10:00:00');
        $loaded->loans()[0]->renew($newDueAt, new \DateTimeImmutable('2026-01-07 09:00:00'));
        $this->items->save($loaded);
        $this->entityManager->clear();

        // Assert: the loan row keeps the original due date; the renewal is a new row.
        $connection = $this->entityManager->getConnection();
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM loan'));
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM loan_renewal'));
        self::assertSame(
            '2026-01-08 10:00:00',
            (string) $connection->fetchOne('SELECT due_at FROM loan'),
            'The renewal must not rewrite the loan period.',
        );

        $loaded = $this->items->get(ItemId::fromString('item-1'));
        self::assertEquals($newDueAt, $loaded->loans()[0]->effectiveDueAt());
        self::assertCount(1, $loaded->loans()[0]->renewals());
    }

    public function test_it_persists_the_return_and_keeps_the_loan_history(): void
    {
        $item = new Item(ItemId::fromString('item-1'), 'Cordless drill');
        $item->lendTo(
            BorrowerId::fromString('borrower-1'),
            new \DateTimeImmutable('2026-01-01 10:00:00'),
            new \DateTimeImmutable('2026-01-08 10:00:00'),
        );
        $this->items->save($item);

        $this->entityManager->clear();
        $loaded     = $this->items->get(ItemId::fromString('item-1'));
        $returnedAt = new \DateTimeImmutable('2026-01-05 10:00:00');
        $loaded->returnFrom($returnedAt);
        $this->items->save($loaded);

        $this->entityManager->clear();
        $loaded = $this->items->get(ItemId::fromString('item-1'));

        self::assertSame(ItemStatus::Available, $loaded->status());
        self::assertCount(1, $loaded->loans());
        self::assertFalse($loaded->loans()[0]->isOpen());
        self::assertEquals($returnedAt, $loaded->loans()[0]->period()->returnedAt());
    }

    public function test_it_lists_items_with_their_loans(): void
    {
        $this->items->save(new Item(ItemId::fromString('item-1'), 'Drill'));
        $item = new Item(ItemId::fromString('item-2'), 'Ladder');
        $item->lendTo(
            BorrowerId::fromString('borrower-1'),
            new \DateTimeImmutable('2026-01-01 10:00:00'),
            new \DateTimeImmutable('2026-01-08 10:00:00'),
        );
        $this->items->save($item);

        $this->entityManager->clear();

        $items = $this->items->allWithLoans();

        self::assertCount(2, $items);
        self::assertSame('Drill', $items[0]->title());
        self::assertSame(ItemStatus::Available, $items[0]->status());
        self::assertSame('Ladder', $items[1]->title());
        self::assertSame(ItemStatus::Lent, $items[1]->status());
        self::assertCount(1, $items[1]->loans());
    }

    public function test_it_throws_when_the_item_does_not_exist(): void
    {
        $this->expectException(ItemNotFound::class);

        $this->items->get(ItemId::fromString('missing'));
    }
}
