<?php

declare(strict_types=1);

namespace App\Tests\Integration\ReadModel;

use App\ReadModel\WhoHasWhatQuery;
use PHPUnit\Framework\Attributes\Test;

final class WhoHasWhatQueryTest extends ReadModelTestCase
{
    #[Test]
    public function it_lists_only_items_with_an_open_loan(): void
    {
        // Arrange
        $this->saveLentItem('item-1', 'Drill', 'borrower-1', '2026-02-01 10:00:00', '2026-02-08 10:00:00');
        $returned = $this->saveLentItem('item-2', 'Ladder', 'borrower-1', '2026-02-01 10:00:00', '2026-02-08 10:00:00');
        $this->returnItem($returned, '2026-02-05 10:00:00');
        $this->saveAvailableItem('item-3', 'Saw');

        // Act
        $rows = (new WhoHasWhatQuery($this->connection, $this->clock))();

        // Assert
        self::assertCount(1, $rows);
        self::assertSame('item-1', $rows[0]->itemId);
        self::assertSame('Drill', $rows[0]->title);
        self::assertSame('borrower-1', $rows[0]->borrowerId);
        self::assertSame('lent', $rows[0]->status);
    }

    #[Test]
    public function it_orders_by_borrower_and_then_by_title(): void
    {
        // Arrange
        $this->saveLentItem('item-1', 'Zipper', 'borrower-2', '2026-02-01 10:00:00', '2026-02-08 10:00:00');
        $this->saveLentItem('item-2', 'Drill', 'borrower-1', '2026-02-01 10:00:00', '2026-02-08 10:00:00');
        $this->saveLentItem('item-3', 'Anvil', 'borrower-2', '2026-02-01 10:00:00', '2026-02-08 10:00:00');

        // Act
        $rows = (new WhoHasWhatQuery($this->connection, $this->clock))();

        // Assert
        self::assertSame(['Drill', 'Anvil', 'Zipper'], array_map(static fn ($row) => $row->title, $rows));
        self::assertSame(['borrower-1', 'borrower-2', 'borrower-2'], array_map(static fn ($row) => $row->borrowerId, $rows));
    }

    #[Test]
    public function it_returns_the_effective_due_date_from_the_last_renewal(): void
    {
        // Arrange
        $item = $this->saveLentItem('item-1', 'Drill', 'borrower-1', '2026-02-01 10:00:00', '2026-02-03 10:00:00');
        $this->renewItem($item, '2026-02-15 10:00:00', '2026-02-02 09:00:00');
        $this->renewItem($item, '2026-02-22 10:00:00', '2026-02-14 09:00:00');

        // Act
        $rows = (new WhoHasWhatQuery($this->connection, $this->clock))();

        // Assert
        self::assertCount(1, $rows);
        self::assertSame('2026-02-22 10:00:00', $rows[0]->effectiveDueAt?->format('Y-m-d H:i:s'));
        self::assertSame('2026-02-01 10:00:00', $rows[0]->lentAt?->format('Y-m-d H:i:s'));
    }
}
