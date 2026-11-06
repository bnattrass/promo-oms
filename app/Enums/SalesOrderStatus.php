<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum SalesOrderStatus: string implements HasColor, HasLabel
{
    case Confirmed = 'confirmed';
    case AwaitingDeposit = 'awaiting_deposit';
    case Artwork = 'artwork';
    case ArtworkApproved = 'artwork_approved';
    case Ordered = 'ordered';
    case InProduction = 'in_production';
    case Ready = 'ready';
    case PartiallyDispatched = 'partially_dispatched';
    case Dispatched = 'dispatched';
    case Invoiced = 'invoiced';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return ucwords(str_replace('_', ' ', $this->value));
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Confirmed => 'gray',
            self::AwaitingDeposit => 'warning',
            self::Artwork, self::ArtworkApproved => 'info',
            self::Ordered, self::InProduction => 'primary',
            self::Ready, self::PartiallyDispatched, self::Dispatched => 'success',
            self::Invoiced, self::Closed => 'gray',
            self::Cancelled => 'danger',
        };
    }

    public function isOpen(): bool
    {
        return ! in_array($this, [self::Invoiced, self::Closed, self::Cancelled], true);
    }
}
