<?php

declare(strict_types=1);

namespace App\Application\Reservation;

use App\Domain\Item\BorrowerId;
use App\Domain\Item\Exception\ItemNotFound;
use App\Domain\Item\ItemId;
use App\Domain\Reservation\Reservation;
use App\Domain\Reservation\ReservationId;
use App\Domain\Reservation\ReservationPolicy;
use App\Repository\DoctrineItemRepository;
use App\Repository\DoctrineReservationRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Orchestrates placing a reservation: loads the facts, delegates the decision to the
 * policy and the state change to the aggregate. No business rule lives here.
 */
final readonly class ReserveItemHandler
{
    public function __construct(
        private DoctrineReservationRepository $reservations,
        private DoctrineItemRepository $items,
        private ClockInterface $clock,
    ) {}

    public function __invoke(ItemId $itemId, BorrowerId $borrowerId): void
    {
        try {
            $item = $this->items->get($itemId);
        } catch (ItemNotFound $exception) {
            throw new \DomainException('Item não encontrado', 0, $exception);
        }

        $alreadyQueued = $this->reservations->hasActiveFor($itemId, $borrowerId);

        (new ReservationPolicy)->assertReservable($item, $alreadyQueued);

        $this->reservations->save(Reservation::place(
            ReservationId::fromString(Uuid::v7()->toRfc4122()),
            $itemId,
            $borrowerId,
            $this->clock->now(),
        ));
    }
}
