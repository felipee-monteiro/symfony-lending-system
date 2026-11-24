<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Item;

use App\Domain\Item\ItemId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ItemIdTest extends TestCase
{
    #[Test]
    public function it_is_created_from_a_non_empty_string(): void
    {
        $id = ItemId::fromString('item-1');

        self::assertSame('item-1', $id->toString());
        self::assertSame('item-1', (string) $id);
    }

    #[Test]
    #[DataProvider('emptyStrings')]
    public function it_rejects_empty_strings(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ItemId::fromString($value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function emptyStrings(): iterable
    {
        yield 'empty' => [''];

        yield 'blank' => ['   '];
    }

    #[Test]
    public function it_rejects_non_string_values(): void
    {
        $this->expectException(\TypeError::class);

        ItemId::fromString(123);
    }
}
