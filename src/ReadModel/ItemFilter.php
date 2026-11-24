<?php

declare(strict_types=1);

namespace App\ReadModel;

enum ItemFilter: string
{
    case All       = 'all';
    case Available = 'available';
    case Lent      = 'lent';
    case Overdue   = 'overdue';

    /**
     * Unknown or missing values fall back to "all" instead of failing the request.
     */
    public static function fromQuery(string $value): self
    {
        return self::tryFrom($value) ?? self::All;
    }
}
