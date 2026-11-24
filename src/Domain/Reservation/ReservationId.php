<?php

declare(strict_types=1);

namespace App\Domain\Reservation;

use App\Shared\Domain\Traits\StringableID;

final readonly class ReservationId
{
    use StringableID;

    private function __construct(
        private string $value,
    ) {}

    public function toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
