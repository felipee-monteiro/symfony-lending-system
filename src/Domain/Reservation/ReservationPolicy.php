<?php

declare(strict_types=1);

namespace App\Domain\Reservation;

use App\Domain\Item\Item;
use App\Domain\Item\ItemStatus;
use App\Domain\Reservation\Exception\BorrowerAlreadyQueued;
use App\Domain\Reservation\Exception\ItemIsAvailableForReservation;

/**
 * Domain rules that need facts the Reservation aggregate cannot see on its own.
 * The caller only gathers the facts; the decision (and the exception) lives here.
 */
final readonly class ReservationPolicy
{
    public function assertReservable(Item $item, bool $alreadyQueued): void
    {
        if ($item->status() !== ItemStatus::Lent) {
            throw ItemIsAvailableForReservation::forItem($item->id()->toString());
        }

        if ($alreadyQueued) {
            throw BorrowerAlreadyQueued::forItem($item->id()->toString());
        }
    }
}
