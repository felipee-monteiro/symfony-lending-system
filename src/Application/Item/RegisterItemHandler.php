<?php

declare(strict_types=1);

namespace App\Application\Item;

use App\Domain\Item\Item;
use App\Domain\Item\ItemId;
use App\Repository\DoctrineItemRepository;
use Symfony\Component\Uid\Uuid;

final readonly class RegisterItemHandler
{
    public function __construct(
        private DoctrineItemRepository $items,
    ) {}

    public function __invoke(string $title): Item
    {
        $item = new Item(ItemId::fromString(Uuid::v7()->toRfc4122()), $title);

        $this->items->save($item);

        return $item;
    }
}
