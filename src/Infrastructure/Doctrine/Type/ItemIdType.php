<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine\Type;

use App\Domain\Item\ItemId;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

use function sprintf;

final class ItemIdType extends Type
{
    public const string NAME = 'item_id';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        $column['length'] ??= 36;

        return $platform->getStringTypeDeclarationSQL($column);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?ItemId
    {
        if ($value === null || $value instanceof ItemId) {
            return $value;
        }

        return ItemId::fromString((string) $value);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! $value instanceof ItemId) {
            throw new \InvalidArgumentException(sprintf('Expected an instance of %s, got %s.', ItemId::class, get_debug_type($value)));
        }

        return $value->toString();
    }
}
