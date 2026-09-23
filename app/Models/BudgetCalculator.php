<?php

namespace App\Models;

final class BudgetCalculator
{
    public static function remaining(float $budget, float $spent): float
    {
        return round($budget - $spent, 2);
    }

    public static function percentage(float $budget, float $spent): float
    {
        return $budget > 0 ? round(($spent / $budget) * 100, 1) : 0.0;
    }
}
