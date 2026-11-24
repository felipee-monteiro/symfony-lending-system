<?php

declare(strict_types=1);

namespace App\Application\Item;

use App\Domain\Item\ItemId;
use App\Repository\DoctrineItemRepository;
use Psr\Clock\ClockInterface;

/**
 * Renewal does not rewrite the loan: the domain appends a LoanRenewal record.
 */
final readonly class RenewLoanHandler
{
    public function __construct(
        private DoctrineItemRepository $items,
        private ClockInterface $clock,
    ) {}

    public function __invoke(ItemId $id, \DateTimeImmutable $newDueAt): void
    {
        $item = $this->items->get($id);

        $item->renewLoan($newDueAt, $this->clock->now());

        $this->items->save($item);
    }
}
