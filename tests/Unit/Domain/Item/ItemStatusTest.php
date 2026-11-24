<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Item;

use App\Domain\Item\ItemStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ItemStatusTest extends TestCase
{
    #[Test]
    public function it_has_an_available_case(): void
    {
        self::assertSame('Available', ItemStatus::Available->name);
    }

    #[Test]
    public function it_has_a_lent_case(): void
    {
        self::assertSame('Lent', ItemStatus::Lent->name);
    }

    #[Test]
    public function it_only_has_the_two_expected_cases(): void
    {
        self::assertSame([ItemStatus::Available, ItemStatus::Lent], ItemStatus::cases());
    }
}
