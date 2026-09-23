<?php

namespace Tests\Unit;

use App\Models\BudgetCalculator;
use PHPUnit\Framework\TestCase;

final class BudgetCalculatorTest extends TestCase
{
    public function testRemainingBudget(): void
    {
        self::assertSame(8500.0, BudgetCalculator::remaining(30000, 21500));
    }

    public function testSpendingPercentage(): void
    {
        self::assertSame(71.7, BudgetCalculator::percentage(30000, 21500));
    }

    public function testZeroBudgetHasZeroPercentage(): void
    {
        self::assertSame(0.0, BudgetCalculator::percentage(0, 100));
    }
}
