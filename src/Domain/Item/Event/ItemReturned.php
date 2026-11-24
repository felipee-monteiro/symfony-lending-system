<?php

declare(strict_types=1);

namespace App\Domain\Item\Event;

use App\Domain\Item\ItemId;

/**
 * Recorded when an item is returned. The domain only records the fact; the application
 * layer releases it and turns it into a Messenger message.
 */
final readonly class ItemReturned
{
    public function __construct(
        public ItemId $itemId,
        public \DateTimeImmutable $returnedAt,
    ) {}
}
