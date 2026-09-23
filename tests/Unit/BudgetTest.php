<?php

namespace Tests\Unit;

use App\Models\Budget;
use DomainException;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BudgetTest extends TestCase
{
    #[DataProvider('invalidBudgets')]
    public function testInvalidBudgetIsRejectedBeforeDatabaseWrite(float $amount, int $month, int $year): void
    {
        $db = $this->createMock(PDO::class);
        $db->expects(self::never())->method('prepare');

        $this->expectException(DomainException::class);
        (new Budget($db))->save(42, 3, $amount, $month, $year, 7);
    }

    public static function invalidBudgets(): array
    {
        return [
            'amount rounds to zero' => [0.001, 9, 2026],
            'positive infinity' => [INF, 9, 2026],
            'negative infinity' => [-INF, 9, 2026],
            'not a number' => [NAN, 9, 2026],
            'month before January' => [100.0, 0, 2026],
            'month after December' => [100.0, 13, 2026],
            'year below allowed range' => [100.0, 9, 2019],
            'year above allowed range' => [100.0, 9, 2101],
        ];
    }

    public function testCurrentBudgetsIncludeRemainingAmountsFromDatabaseDecimals(): void
    {
        $rows = [
            ['id' => 1, 'category_name' => 'Food', 'amount' => '100.10', 'spent' => '30.05'],
            ['id' => 2, 'category_name' => 'Travel', 'amount' => '40.20', 'spent' => '55.35'],
        ];
        $statement = $this->createMock(PDOStatement::class);
        $statement->expects(self::once())->method('execute')->with([42])->willReturn(true);
        $statement->method('fetchAll')->willReturn($rows);
        $db = $this->createMock(PDO::class);
        $db->expects(self::once())->method('prepare')->willReturn($statement);

        self::assertSame([
            $rows[0] + ['remaining' => 70.05],
            $rows[1] + ['remaining' => -15.15],
        ], (new Budget($db))->currentForUser(42));
    }
}
