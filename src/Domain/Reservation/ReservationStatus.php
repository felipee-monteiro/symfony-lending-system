<?php

declare(strict_types=1);

namespace App\Domain\Reservation;

enum ReservationStatus: string
{
    case Pending   = 'pending';
    case Notified  = 'notified';
    case Cancelled = 'cancelled';
}
