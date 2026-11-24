<?php

namespace App\Support;

use Illuminate\Support\Str;

class CatalogueValues
{
    public const MONEY_RULE = '/^\d{1,9}(?:\.\d{1,2})?$/';

    public static function minorUnits(string $amount): int
    {
        if (preg_match(self::MONEY_RULE, $amount) !== 1) {
            throw new \InvalidArgumentException('Invalid KES amount.');
        }
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    public static function money(int $minor): string
    {
        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function nameKey(string $name): string
    {
        return Str::lower(Str::squish($name));
    }

    public static function contactKey(string $contact): string
    {
        // Preserve letters for email/contact references, ignore phone punctuation.
        $key = Str::lower((string) preg_replace('/[\s()+.\-]/u', '', $contact));
        if (preg_match('/^0([17]\d{8})$/', $key, $matches) === 1) {
            return '254'.$matches[1];
        }

        return $key;
    }

    public static function searchPattern(string $search): string
    {
        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], Str::lower($search)).'%';
    }
}
