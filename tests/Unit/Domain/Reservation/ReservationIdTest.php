<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Reservation;

use App\Domain\Reservation\ReservationId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReservationIdTest extends TestCase
{
    #[Test]
    public function it_is_created_from_a_non_empty_string(): void
    {
        $id = ReservationId::fromString('reservation-1');

        self::assertSame('reservation-1', $id->toString());
        self::assertSame('reservation-1', (string) $id);
    }

    #[Test]
    #[DataProvider('emptyStrings')]
    public function it_rejects_empty_strings(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ReservationId::fromString($value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function emptyStrings(): iterable
    {
        yield 'empty' => [''];

        yield 'blank' => ['   '];
    }
}
