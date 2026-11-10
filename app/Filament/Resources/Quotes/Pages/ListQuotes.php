<?php

namespace App\Filament\Resources\Quotes\Pages;

use App\Enums\QuoteStatus;
use App\Filament\Resources\Quotes\QuoteResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Livewire\Attributes\Url;

class ListQuotes extends ListRecords
{
    protected static string $resource = QuoteResource::class;

    #[Url]
    public ?string $activeTab = null;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('New quote')];
    }

    public function getTabs(): array
    {
        return [
            'open' => Tab::make('Open')->modifyQueryUsing(fn ($query) => $query->whereIn('status', [QuoteStatus::Draft, QuoteStatus::Sent, QuoteStatus::Accepted])),
            'all' => Tab::make('All'),
            'draft' => Tab::make('Draft')->modifyQueryUsing(fn ($query) => $query->where('status', QuoteStatus::Draft)),
            'sent' => Tab::make('Sent')->modifyQueryUsing(fn ($query) => $query->where('status', QuoteStatus::Sent)),
            'accepted' => Tab::make('Accepted')->modifyQueryUsing(fn ($query) => $query->where('status', QuoteStatus::Accepted)),
            'won' => Tab::make('Converted')->modifyQueryUsing(fn ($query) => $query->where('status', QuoteStatus::Converted)),
            'lost' => Tab::make('Lost')->modifyQueryUsing(fn ($query) => $query->whereIn('status', [QuoteStatus::Declined, QuoteStatus::Expired])),
        ];
    }
}
