<?php

declare(strict_types=1);

namespace App\Tests\Integration\ReadModel;

use App\Domain\Item\BorrowerId;
use App\Domain\Item\Item;
use App\Domain\Item\ItemId;
use App\Repository\DoctrineItemRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Shared fixture for the DBAL read models: real schema, real writes through the
 * aggregate, frozen clock. No HTTP involved.
 */
abstract class ReadModelTestCase extends KernelTestCase
{
    protected EntityManagerInterface $entityManager;

    protected DoctrineItemRepository $items;

    protected Connection $connection;

    protected MockClock $clock;

    protected \DateTimeImmutable $startOfToday;

    protected function setUp(): void
    {
        self::bootKernel();

        $container           = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->items         = $container->get(DoctrineItemRepository::class);
        $this->connection    = $container->get(Connection::class);

        // Set the clock before building the schema: the Messenger schema listener
        // instantiates the transports (and their clock) while generating the schema.
        // 18:30 on purpose: an item due today at 18:00 is already in the past by the
        // wall clock but must still count as "not late" in calendar days.
        $this->clock        = new MockClock(new \DateTimeImmutable('2026-02-10 18:30:00'));
        $this->startOfToday = new \DateTimeImmutable('2026-02-10 00:00:00');

        $container->set(ClockInterface::class, $this->clock);

        $this->entityManager->getConfiguration()->setUseSchemaEditorApi(true);
        (new SchemaTool($this->entityManager))->createSchema(
            $this->entityManager->getMetadataFactory()->getAllMetadata(),
        );
    }

    protected function saveAvailableItem(string $id, string $title): Item
    {
        $item = new Item(ItemId::fromString($id), $title);
        $this->items->save($item);

        return $item;
    }

    protected function saveLentItem(
        string $id,
        string $title,
        string $borrowerId,
        string $lentAt,
        string $dueAt,
    ): Item {
        $item = new Item(ItemId::fromString($id), $title);
        $item->lendTo(
            BorrowerId::fromString($borrowerId),
            new \DateTimeImmutable($lentAt),
            new \DateTimeImmutable($dueAt),
        );
        $this->items->save($item);

        return $item;
    }

    protected function renewItem(Item $item, string $newDueAt, string $renewedAt): void
    {
        $item->renewLoan(new \DateTimeImmutable($newDueAt), new \DateTimeImmutable($renewedAt));
        $this->items->save($item);
    }

    protected function returnItem(Item $item, string $returnedAt): void
    {
        $item->returnFrom(new \DateTimeImmutable($returnedAt));
        $this->items->save($item);
    }
}
