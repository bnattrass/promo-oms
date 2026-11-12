<?php

namespace App\Filament\Support;

use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

/** A "Save changes" button in the page header of long edit pages (also Cmd/Ctrl + S). */
class TopSaveAction
{
    public static function make(EditRecord $page): Action
    {
        return Action::make('saveTop')
            ->label('Save changes')
            ->icon(Heroicon::OutlinedCheck)
            ->color('primary')
            ->action(fn () => $page->save());
    }
}
