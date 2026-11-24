<?php

declare(strict_types=1);

namespace App\Tests\Integration\ReadModel;

use App\ReadModel\ItemOverviewQuery;
use PHPUnit\Framework\Attributes\Test;

final class ItemOverviewQueryTest extends ReadModelTestCase
{
    #[Test]
    public function it_lists_every_item_ordered_by_title(): void
    {
        // Arrange
        $this->saveAvailableItem('item-1', 'Drill');
        $this->saveLentItem('item-2', 'Ladder', 'borrower-1', '2026-02-01 10:00:00', '2026-02-08 10:00:00');
        $returned = $this->saveLentItem('item-3', 'Saw', 'borrower-2', '2026-02-01 10:00:00', '2026-02-08 10:00:00');
        $this->returnItem($returned, '2026-02-05 10:00:00');

        // Act
        $rows = (new ItemOverviewQuery($this->connection, $this->clock))();

        // Assert
        self::assertCount(3, $rows);
        self::assertSame(['Drill', 'Ladder', 'Saw'], array_map(static fn ($row) => $row->title, $rows));
    }

    #[Test]
    public function it_fills_the_loan_data_for_an_open_loan(): void
    {
        // Arrange
        $item = $this->saveLentItem('item-1', 'Drill', 'borrower-1', '2026-02-01 10:00:00', '2026-02-03 10:00:00');
        $this->renewItem($item, '2026-02-08 10:00:00', '2026-02-02 09:00:00');

        // Act
        $rows = (new ItemOverviewQuery($this->connection, $this->clock))();

        // Assert
        self::assertCount(1, $rows);
        self::assertSame('lent', $rows[0]->status);
        self::assertSame('borrower-1', $rows[0]->borrowerId);
        self::assertSame('2026-02-01 10:00:00', $rows[0]->lentAt?->format('Y-m-d H:i:s'));
        self::assertSame('2026-02-08 10:00:00', $rows[0]->effectiveDueAt?->format('Y-m-d H:i:s'));
        self::assertSame(2, $rows[0]->daysLate);
    }

    #[Test]
    public function it_leaves_the_loan_data_empty_for_available_items(): void
    {
        // Arrange
        $this->saveAvailableItem('item-1', 'Drill');

        // Act
        $rows = (new ItemOverviewQuery($this->connection, $this->clock))();

        // Assert
        self::assertCount(1, $rows);
        self::assertSame('available', $rows[0]->status);
        self::assertNull($rows[0]->borrowerId);
        self::assertNull($rows[0]->lentAt);
        self::assertNull($rows[0]->effectiveDueAt);
        self::assertNull($rows[0]->daysLate);
    }

    #[Test]
    public function a_returned_loan_does_not_leak_into_the_item_row(): void
    {
        // Arrange
        $returned = $this->saveLentItem('item-1', 'Saw', 'borrower-1', '2026-02-01 10:00:00', '2026-02-08 10:00:00');
        $this->returnItem($returned, '2026-02-05 10:00:00');

        // Act
        $rows = (new ItemOverviewQuery($this->connection, $this->clock))();

        // Assert
        self::assertCount(1, $rows);
        self::assertSame('available', $rows[0]->status);
        self::assertNull($rows[0]->borrowerId);
        self::assertNull($rows[0]->effectiveDueAt);
        self::assertNull($rows[0]->daysLate);
    }
}
