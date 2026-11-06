<?php

namespace App\Filament\Resources\Quotes\Pages;

use App\Enums\QuoteStatus;
use App\Filament\Resources\Quotes\QuoteResource;
use Filament\Resources\Pages\CreateRecord;

class CreateQuote extends CreateRecord
{
    protected static string $resource = QuoteResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['status'] = QuoteStatus::Draft;

        return $data;
    }

    /** Straight to the edit page so lines can be added. */
    protected function getRedirectUrl(): string
    {
        return QuoteResource::getUrl('edit', ['record' => $this->record]);
    }
}
