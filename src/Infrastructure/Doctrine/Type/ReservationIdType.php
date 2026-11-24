<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine\Type;

use App\Domain\Reservation\ReservationId;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

use function sprintf;

final class ReservationIdType extends Type
{
    public const string NAME = 'reservation_id';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        $column['length'] ??= 36;

        return $platform->getStringTypeDeclarationSQL($column);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?ReservationId
    {
        if ($value === null || $value instanceof ReservationId) {
            return $value;
        }

        return ReservationId::fromString((string) $value);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! $value instanceof ReservationId) {
            throw new \InvalidArgumentException(sprintf('Expected an instance of %s, got %s.', ReservationId::class, get_debug_type($value)));
        }

        return $value->toString();
    }
}
