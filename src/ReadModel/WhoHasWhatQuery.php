<?php

declare(strict_types=1);

namespace App\ReadModel;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * "Who has what": one row per open loan, with the effective due date resolved from the
 * latest renewal. Raw DBAL on purpose, so the listing does not hydrate the aggregate.
 */
final readonly class WhoHasWhatQuery
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
                       ) AS effective_due_at
                FROM item i
                INNER JOIN loan l ON l.item_id = i.id
                WHERE l.returned_at IS NULL
                ORDER BY l.borrower_id ASC, i.title ASC
                SQL,
        );

        return array_map(
            static fn (array $row): ItemRow => ItemRow::fromRow($row, $startOfToday),
            $rows,
        );
    }
}
