<?php

declare(strict_types=1);

namespace App\Domain\Item\Exception;

use App\Domain\Item\ItemId;

final class ItemNotFound extends \RuntimeException
{
    public static function withId(ItemId $id): self
    {
        return new self(\sprintf('Item "%s" was not found.', $id->toString()));
    }
}
