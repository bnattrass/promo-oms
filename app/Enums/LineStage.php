<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum LineStage: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Artwork = 'artwork';
    case ArtworkApproved = 'artwork_approved';
    case Ordered = 'ordered';
    case InProduction = 'in_production';
    case Ready = 'ready';
    case Dispatched = 'dispatched';

    public function getLabel(): string
    {
        return ucwords(str_replace('_', ' ', $this->value));
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Artwork, self::ArtworkApproved => 'info',
            self::Ordered, self::InProduction => 'primary',
            self::Ready, self::Dispatched => 'success',
        };
    }
}
