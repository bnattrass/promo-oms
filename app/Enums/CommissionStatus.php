<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Commission on a sales order: due once invoiced, then paid. */
enum CommissionStatus: string implements HasColor, HasLabel
{
    case NotDue = 'not_due';
    case Unpaid = 'unpaid';
    case Paid = 'paid';

    public function getLabel(): string
    {
        return match ($this) {
            self::NotDue => 'Not due yet',
            self::Unpaid => 'Unpaid',
            self::Paid => 'Paid',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::NotDue => 'gray',
            self::Unpaid => 'warning',
            self::Paid => 'success',
        };
    }
}
