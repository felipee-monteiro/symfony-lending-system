<?php

declare(strict_types=1);

namespace App\Domain\Reservation\Exception;

final class ItemIsAvailableForReservation extends \DomainException
{
    public static function forItem(string $itemId): self
    {
        return new self(\sprintf('Item "%s" is available; borrow it instead of reserving.', $itemId));
    }
}
