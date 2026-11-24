<?php

declare(strict_types=1);

namespace App\Application\Reservation;

use App\Domain\Item\ItemId;

/**
 * Infrastructure contract: the fact that an item was returned, serialized to be handled
 * asynchronously. It is deliberately separate from the domain event (ItemReturned).
 */
final readonly class NotifyNextInQueue
{
    public function __construct(
        public ItemId $itemId,
        public \DateTimeImmutable $returnedAt,
    ) {}
}
