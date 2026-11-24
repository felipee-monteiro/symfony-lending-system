<?php

declare(strict_types=1);

namespace App\ReadModel;

use App\Domain\Item\OverdueDays;
use DateTimeImmutable;

/**
 * Flat row for the listing screens. It is built from a DBAL row so the UI never needs
 * to hydrate the Item aggregate (and never triggers N+1 on loans).
 */
final readonly class ItemRow
{
    public function __construct(
        public string $itemId,
        public string $title,
        public string $status,
        public ?string $borrowerId,
        public ?DateTimeImmutable $lentAt,
        public ?DateTimeImmutable $effectiveDueAt,
        public ?int $daysLate,
        public int $queueCount = 0,
    ) {}

    /**
     * `daysLate` is only computed for an item with an open loan; the reference is the
     * start of today, the same value the SQL filter uses.
     *
     * @param  array<string, mixed>  $row
     */
    public static function fromRow(array $row, DateTimeImmutable $startOfToday): self
    {
        $borrowerId     = $row['borrower_id'] !== null ? (string) $row['borrower_id'] : null;
        $effectiveDueAt = self::date($row['effective_due_at']);

        return new self(
            itemId: (string) $row['item_id'],
            title: (string) $row['title'],
            status: (string) $row['status'],
            borrowerId: $borrowerId,
            lentAt: self::date($row['lent_at']),
            effectiveDueAt: $effectiveDueAt,
            daysLate: $borrowerId !== null && $effectiveDueAt !== null
                ? OverdueDays::calculate($effectiveDueAt, $startOfToday)
                : null,
            queueCount: (int) ($row['queue_count'] ?? 0),
        );
    }

    private static function date(mixed $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        return new DateTimeImmutable((string) $value);
    }
}
