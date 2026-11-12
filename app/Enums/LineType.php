<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum LineType: string implements HasLabel
{
    case Catalogue = 'catalogue';
    case Branded = 'branded';
    case Courier = 'courier';
    case Design = 'design';
    case Setup = 'setup';
    case Service = 'service';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Catalogue => 'Catalogue item',
            self::Branded => 'Branded item (other supplier)',
            self::Courier => 'Courier / delivery',
            self::Design => 'Design / artwork',
            self::Setup => 'Setup / origination',
            self::Service => 'Service / labour',
            self::Other => 'Other',
        };
    }

    /** Custom line types offered in the "Add custom line" form. */
    public static function customOptions(): array
    {
        return collect(self::cases())->reject(fn ($c) => $c === self::Catalogue)
            ->mapWithKeys(fn ($c) => [$c->value => $c->getLabel()])->all();
    }
}
