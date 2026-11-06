<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PaymentTerms: string implements HasLabel
{
    case Cod = 'cod';
    case Deposit = 'deposit';
    case ThirtyDays = '30_days';

    public function getLabel(): string
    {
        return match ($this) {
            self::Cod => 'Payment before production',
            self::Deposit => 'Deposit, balance on delivery',
            self::ThirtyDays => '30 days from invoice',
        };
    }
}
