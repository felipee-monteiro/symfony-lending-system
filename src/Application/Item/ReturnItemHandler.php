<?php

declare(strict_types=1);

namespace App\Application\Item;

use App\Application\Reservation\NotifyNextInQueue;
use App\Domain\Item\Event\ItemReturned;
use App\Domain\Item\ItemId;
use App\Repository\DoctrineItemRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The current time is taken here (application edge) so the domain stays deterministic.
 * Domain facts are drained only after the flush, so a worker cannot observe a return that
 * was not committed yet.
 */
final readonly class ReturnItemHandler
{
    public function __construct(
        private DoctrineItemRepository $items,
        private ClockInterface $clock,
        private MessageBusInterface $bus,
    ) {}

    public function __invoke(ItemId $id): void
    {
        $item = $this->items->get($id);

        $item->returnFrom($this->clock->now());

        $this->items->save($item);

        foreach ($item->releaseEvents() as $event) {
            if ($event instanceof ItemReturned) {
                $this->bus->dispatch(new NotifyNextInQueue($event->itemId, $event->returnedAt));
            }
        }
    }
}
