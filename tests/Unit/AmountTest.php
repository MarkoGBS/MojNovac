<?php

namespace Tests\Unit;

use App\Models\Amount;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AmountTest extends TestCase
{
    public function testPositiveAmountIsRounded(): void
    {
        self::assertSame(125.46, Amount::validate(125.456));
        self::assertSame(0.01, Amount::validate(0.005));
    }

    public function testZeroAmountIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Amount::validate(0);
    }

    public function testNegativeAmountIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Amount::validate(-10);
    }

    public function testAmountThatRoundsToZeroIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Amount::validate(0.001);
    }

    #[DataProvider('nonFiniteAmounts')]
    public function testNonFiniteAmountIsRejected(float $amount): void
    {
        $this->expectException(InvalidArgumentException::class);
        Amount::validate($amount);
    }

    public static function nonFiniteAmounts(): array
    {
        return [
            'positive infinity' => [INF],
            'negative infinity' => [-INF],
            'not a number' => [NAN],
        ];
    }
}
