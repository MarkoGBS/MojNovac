<?php

namespace App\Models;

use InvalidArgumentException;

final class Amount
{
    public static function validate(float $amount): float
    {
        $amount = round($amount, 2);
        if (!is_finite($amount) || $amount <= 0) {
            throw new InvalidArgumentException('Iznos mora biti veći od nule.');
        }

        return $amount;
    }
}
