<?php

namespace App\Filament\Resources\Suppliers\Pages;

use App\Filament\Resources\Suppliers\SupplierResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSupplier extends EditRecord
{
    protected static string $resource = SupplierResource::class;

    protected function getHeaderActions(): array
    {
        return [
            SupplierResource::testConnectionAction(),
            SupplierResource::syncNowAction(),
            DeleteAction::make(),
        ];
    }

    /** Credentials are hidden from serialisation; load them into the form explicitly. */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['credentials'] = $this->record->credentials ?? [];

        return $data;
    }
}
