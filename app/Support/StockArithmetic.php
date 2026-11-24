<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

class StockArithmetic
{
    public const MAX_TOTAL_MINOR = 9_000_000_000_000_000;

    public const TOTAL_RULE = '/^\d{1,14}(?:\.\d{1,2})?$/';

    public static function totalAmount(string $amount, string $field): int
    {
        if (preg_match(self::TOTAL_RULE, $amount) !== 1) {
            throw ValidationException::withMessages([$field => 'Enter a nonnegative KES total with at most 2 decimal places.']);
        }
        $minor = BigDecimal::of($amount)->multipliedBy(100);
        if ($minor->isGreaterThan(self::MAX_TOTAL_MINOR)) {
            throw ValidationException::withMessages([$field => 'The amount exceeds the supported total.']);
        }

        return $minor->toInt();
    }

    public static function quantity(string $value, int $precision, string $field): int
    {
        $pattern = $precision === 0 ? '/^\d{1,9}$/' : '/^\d{1,9}(?:\.\d{1,'.$precision.'})?$/';
        if (preg_match($pattern, $value) !== 1) {
            throw ValidationException::withMessages([$field => 'Quantity must be nonnegative, with up to '.$precision.' decimal places and 9 whole digits.']);
        }

        return BigDecimal::of($value)->multipliedBy(1000)->toInt();
    }

    public static function scaled(string $value): int
    {
        return BigDecimal::of($value)->multipliedBy(1000)->toInt();
    }

    public static function decimal(int $scaled): string
    {
        return (string) BigDecimal::of($scaled)->dividedBy(1000, 3);
    }

    public static function lineValue(int $quantity, int $cost, string $field = 'rows'): int
    {
        $value = BigDecimal::of($quantity)->multipliedBy($cost)->dividedBy(1000, 0, RoundingMode::HalfUp);
        if ($value->isGreaterThan(self::MAX_TOTAL_MINOR)) {
            throw ValidationException::withMessages([$field => 'The line value exceeds the supported total.']);
        }

        return $value->toInt();
    }

    public static function average(int $value, int $scaledQuantity): int
    {
        return BigDecimal::of($value)->multipliedBy(1000)->dividedBy($scaledQuantity, 0, RoundingMode::HalfUp)->toInt();
    }

    public static function total(int $current, int $addition, string $field = 'rows'): int
    {
        if ($addition > self::MAX_TOTAL_MINOR - $current) {
            throw ValidationException::withMessages([$field => 'The combined amount exceeds the supported total.']);
        }

        return $current + $addition;
    }
}
