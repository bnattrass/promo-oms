<?php

namespace App\Filament\Resources\Team\Pages;

use App\Filament\Resources\Team\TeamResource;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;

class EditTeamMember extends EditRecord
{
    protected static string $resource = TeamResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['role'] = $this->record->roleIn(Filament::getTenant()) ?? 'sales';

        return $data;
    }

    protected function afterSave(): void
    {
        $this->record->companies()->updateExistingPivot(Filament::getTenant()->id, ['role' => $this->data['role'] ?? 'sales']);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('remove')
                ->label('Remove from company')
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('They lose access to this company. Their quotes and orders are kept.')
                ->visible(fn () => $this->record->id !== auth()->id())
                ->action(function () {
                    $this->record->companies()->detach(Filament::getTenant()->id);
                    $this->redirect(TeamResource::getUrl());
                }),
        ];
    }
}
