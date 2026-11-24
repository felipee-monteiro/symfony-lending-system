<?php

declare(strict_types=1);

namespace App\ReadModel;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * Open loans whose effective due date is before the start of today. The "now" comes
 * from the injected clock as a SQL parameter, never from CURRENT_TIMESTAMP, so a frozen
 * clock in tests actually moves the filter.
 */
final readonly class OverdueItemsQuery
{
    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
    ) {}

    /**
     * @return ItemRow[]
     */
    public function __invoke(): array
    {
        $startOfToday = $this->clock->now()->setTime(0, 0);

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT *
                FROM (
                    SELECT i.id AS item_id,
                           i.title AS title,
                           i.status AS status,
                           l.borrower_id AS borrower_id,
                           l.lent_at AS lent_at,
                           COALESCE(
                               (SELECT r.due_at FROM loan_renewal r WHERE r.loan_id = l.id ORDER BY r.id DESC LIMIT 1),
                               l.due_at
                           ) AS effective_due_at,
                           (
                               SELECT COUNT(*)
                               FROM reservation q
                               WHERE q.item_id = i.id
                                 AND q.status IN ('pending', 'notified')
                           ) AS queue_count
                    FROM item i
                    INNER JOIN loan l ON l.item_id = i.id
                    WHERE l.returned_at IS NULL
                ) AS listing
                WHERE listing.effective_due_at < :startOfToday
                ORDER BY listing.effective_due_at ASC, listing.title ASC
                SQL,
            ['startOfToday' => $startOfToday->format('Y-m-d H:i:s')],
        );

        return array_map(
            static fn (array $row): ItemRow => ItemRow::fromRow($row, $startOfToday),
            $rows,
        );
    }
}
