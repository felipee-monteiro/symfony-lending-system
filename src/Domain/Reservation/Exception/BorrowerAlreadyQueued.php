<?php

declare(strict_types=1);

namespace App\Domain\Reservation\Exception;

final class BorrowerAlreadyQueued extends \DomainException
{
    public static function forItem(string $itemId): self
    {
        return new self(\sprintf('The borrower is already queued for item "%s".', $itemId));
    }
}
