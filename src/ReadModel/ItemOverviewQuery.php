<?php

declare(strict_types=1);

namespace App\ReadModel;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * Full listing: every item, with the open loan data when there is one and nulls when
 * the item is available. Raw DBAL on purpose (no aggregate hydration, no N+1).
 */
final readonly class ItemOverviewQuery
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
                LEFT JOIN loan l ON l.item_id = i.id AND l.returned_at IS NULL
                ORDER BY i.title ASC
                SQL,
        );

        return array_map(
            static fn (array $row): ItemRow => ItemRow::fromRow($row, $startOfToday),
            $rows,
        );
    }
}
