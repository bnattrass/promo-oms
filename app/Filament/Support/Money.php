<?php

namespace App\Filament\Support;

/** Compact rand formatting for tables: "R 54 124.75". */
class Money
{
    public static function format(mixed $value, int $decimals = 2): string
    {
        return 'R '.number_format((float) $value, $decimals, '.', ' ');
    }

    public static function formatter(): \Closure
    {
        return fn ($state) => static::format($state);
    }
}
