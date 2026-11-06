<?php

namespace App\Filament\Resources\Team\Pages;

use App\Filament\Resources\Team\TeamResource;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateTeamMember extends CreateRecord
{
    protected static string $resource = TeamResource::class;

    /** An existing user (e.g. already in another company) is added rather than duplicated. */
    protected function handleRecordCreation(array $data): Model
    {
        $user = User::withoutGlobalScopes()->firstOrNew(['email' => $data['email']]);
        $user->fill($data)->save();
        $user->companies()->syncWithoutDetaching([Filament::getTenant()->id => ['role' => $this->data['role'] ?? 'sales']]);

        return $user;
    }

}
