<?php

declare(strict_types=1);

namespace App\Application\Item;

use App\Domain\Item\BorrowerId;
use App\Domain\Item\ItemId;
use App\Repository\DoctrineItemRepository;
use Psr\Clock\ClockInterface;

/**
 * The current time is taken here (application edge) so the domain stays deterministic.
 */
final readonly class LendItemHandler
{
    public function __construct(
        private DoctrineItemRepository $items,
        private ClockInterface $clock,
    ) {}

    public function __invoke(ItemId $id, BorrowerId $borrower, \DateTimeImmutable $dueAt): void
    {
        $item = $this->items->get($id);

        $item->lendTo($borrower, $this->clock->now(), $dueAt);

        $this->items->save($item);
    }
}
