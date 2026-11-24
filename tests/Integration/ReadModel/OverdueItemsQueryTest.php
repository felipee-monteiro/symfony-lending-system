<?php

declare(strict_types=1);

namespace App\Tests\Integration\ReadModel;

use App\Domain\Item\OverdueDays;
use App\ReadModel\OverdueItemsQuery;
use PHPUnit\Framework\Attributes\Test;

final class OverdueItemsQueryTest extends ReadModelTestCase
{
    #[Test]
    public function it_lists_an_open_loan_whose_effective_due_date_has_passed(): void
    {
        // Arrange
        $this->saveLentItem('item-1', 'Late drill', 'borrower-1', '2026-02-01 10:00:00', '2026-02-05 10:00:00');

        // Act
        $rows = (new OverdueItemsQuery($this->connection, $this->clock))();

        // Assert
        self::assertCount(1, $rows);
        self::assertSame('Late drill', $rows[0]->title);
        self::assertSame(5, $rows[0]->daysLate);
    }

    #[Test]
    public function an_item_due_today_is_not_overdue(): void
    {
        // Arrange
        $this->saveLentItem('item-1', 'Today ladder', 'borrower-1', '2026-02-03 10:00:00', '2026-02-10 18:00:00');

        // Act
        $rows = (new OverdueItemsQuery($this->connection, $this->clock))();

        // Assert
        self::assertSame([], $rows);
        self::assertSame(
            0,
            OverdueDays::calculate(
                new \DateTimeImmutable('2026-02-10 18:00:00'),
                $this->startOfToday,
            ),
            'The pure rule and the SQL filter must agree on "due today".',
        );
    }

    #[Test]
    public function it_uses_the_effective_due_date_from_the_last_renewal(): void
    {
        // Arrange
        $item = $this->saveLentItem('item-1', 'Renewed saw', 'borrower-1', '2026-02-01 10:00:00', '2026-02-02 10:00:00');
        $this->renewItem($item, '2026-02-05 10:00:00', '2026-02-02 09:00:00');

        // Act
        $rows = (new OverdueItemsQuery($this->connection, $this->clock))();

        // Assert
        self::assertCount(1, $rows);
        self::assertSame('2026-02-05 10:00:00', $rows[0]->effectiveDueAt?->format('Y-m-d H:i:s'));
        self::assertSame(5, $rows[0]->daysLate, 'Lateness must count from the renewal, not from the original due date.');
    }

    #[Test]
    public function a_renewal_that_moves_the_due_date_past_today_removes_the_item_from_the_list(): void
    {
        // Arrange
        $item = $this->saveLentItem('item-1', 'Renewed hammer', 'borrower-1', '2026-02-01 10:00:00', '2026-02-05 10:00:00');
        $this->renewItem($item, '2026-02-20 10:00:00', '2026-02-04 09:00:00');

        // Act
        $rows = (new OverdueItemsQuery($this->connection, $this->clock))();

        // Assert
        self::assertSame([], $rows);
    }

    #[Test]
    public function a_returned_loan_is_not_overdue(): void
    {
        // Arrange
        $item = $this->saveLentItem('item-1', 'Returned wrench', 'borrower-1', '2026-01-01 10:00:00', '2026-01-05 10:00:00');
        $this->returnItem($item, '2026-01-10 10:00:00');

        // Act
        $rows = (new OverdueItemsQuery($this->connection, $this->clock))();

        // Assert
        self::assertSame([], $rows);
    }

    #[Test]
    public function the_sql_filter_agrees_with_the_pure_overdue_rule(): void
    {
        // Arrange
        $this->saveLentItem('item-1', 'Late drill', 'borrower-1', '2026-02-01 10:00:00', '2026-02-05 10:00:00');
        $this->saveLentItem('item-2', 'Today ladder', 'borrower-2', '2026-02-03 10:00:00', '2026-02-10 18:00:00');
        $item = $this->saveLentItem('item-3', 'Renewed saw', 'borrower-3', '2026-02-01 10:00:00', '2026-02-02 10:00:00');
        $this->renewItem($item, '2026-02-05 10:00:00', '2026-02-02 09:00:00');

        // Act
        $rows = (new OverdueItemsQuery($this->connection, $this->clock))();

        // Assert
        self::assertCount(2, $rows);

        foreach ($rows as $row) {
            self::assertNotNull($row->effectiveDueAt);
            self::assertSame(
                OverdueDays::calculate($row->effectiveDueAt, $this->startOfToday),
                $row->daysLate,
            );
            self::assertGreaterThan(0, $row->daysLate);
        }
    }
}
