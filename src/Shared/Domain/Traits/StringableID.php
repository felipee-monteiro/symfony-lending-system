<?php

declare(strict_types=1);

namespace App\Shared\Domain\Traits;

trait StringableID
{
    public static function fromString(string $value): self
    {
        $value = \trim($value);

        if ($value === '') {
            throw new \InvalidArgumentException(\sprintf(self::class.' cannot be empty'));
        }

        return new self($value);
    }
}
