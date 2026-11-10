<?php

namespace App\Filament\RelationManagers;

use App\Models\Attachment;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\On;

/** "Documents" tab on quotes and sales orders: customer POs, artwork, proofs, delivery notes. */
class AttachmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'attachments';

    protected static ?string $title = 'Documents';

    #[On('refresh-lines')]
    public function refreshDocuments(): void {}

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('type')->options(Attachment::TYPES)->default('customer_po')->required(),
            TextInput::make('notes')->placeholder('e.g. PO 4500123, revised artwork v2'),
            FileUpload::make('path')
                ->label('File')
                ->disk('local')
                ->directory('attachments')
                ->visibility('private')
                ->maxSize(20480)
                ->storeFileNamesIn('original_name')
                ->required()
                ->visibleOn('create')
                ->columnSpanFull()
                ->helperText('PDF, images, Word, Excel, AI/EPS/CDR artwork - up to 20 MB.'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('type')->badge()
                    ->formatStateUsing(fn (string $state) => Attachment::TYPES[$state] ?? $state)
                    ->color(fn (string $state) => $state === 'customer_po' ? 'success' : 'gray'),
                TextColumn::make('original_name')->label('File')->wrap()
                    ->description(fn (Attachment $record) => $record->notes),
                TextColumn::make('size')->formatStateUsing(fn ($state, Attachment $record) => $record->humanSize()),
                TextColumn::make('uploader.name')->label('Uploaded by')->placeholder('-'),
                TextColumn::make('created_at')->label('Uploaded')->since(),
            ])
            ->filters([
                SelectFilter::make('type')->options(Attachment::TYPES),
            ])
            ->headerActions([
                CreateAction::make()->label('Upload document')->icon(Heroicon::OutlinedArrowUpTray)
                    ->mutateDataUsing(fn (array $data) => $data + [
                        'company_id' => $this->getOwnerRecord()->company_id,
                        'disk' => 'local',
                    ]),
            ])
            ->recordActions([
                Action::make('download')->label('Download')->icon(Heroicon::OutlinedArrowDownTray)
                    ->action(fn (Attachment $record) => Storage::disk($record->disk)->download($record->path, $record->original_name)),
                EditAction::make()->label('Edit details'),
                DeleteAction::make(),
            ]);
    }
}
