<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Item;

use App\Domain\Item\BorrowerId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BorrowerIdTest extends TestCase
{
    #[Test]
    public function it_is_created_from_a_non_empty_string(): void
    {
        $id = BorrowerId::fromString('borrower-1');

        self::assertSame('borrower-1', $id->toString());
        self::assertSame('borrower-1', (string) $id);
    }

    #[Test]
    #[DataProvider('emptyStrings')]
    public function it_rejects_empty_strings(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);

        BorrowerId::fromString($value);
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

        BorrowerId::fromString(123);
    }
}
