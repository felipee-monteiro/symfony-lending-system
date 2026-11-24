<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine\Type;

use App\Domain\Item\BorrowerId;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

use function sprintf;

final class BorrowerIdType extends Type
{
    public const string NAME = 'borrower_id';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        $column['length'] ??= 255;

        return $platform->getStringTypeDeclarationSQL($column);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?BorrowerId
    {
        if ($value === null || $value instanceof BorrowerId) {
            return $value;
        }

        return BorrowerId::fromString((string) $value);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! $value instanceof BorrowerId) {
            throw new \InvalidArgumentException(sprintf('Expected an instance of %s, got %s.', BorrowerId::class, get_debug_type($value)));
        }

        return $value->toString();
    }
}
