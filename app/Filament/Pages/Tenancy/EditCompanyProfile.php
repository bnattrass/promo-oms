<?php

namespace App\Filament\Pages\Tenancy;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Tenancy\EditTenantProfile;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class EditCompanyProfile extends EditTenantProfile
{
    public static function getLabel(): string
    {
        return 'Company settings';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Company')->columns(2)->schema([
                TextInput::make('name')->required(),
                TextInput::make('slug')->required()->alphaDash()->helperText('Used in the web address'),
                TextInput::make('registration_number'),
                TextInput::make('vat_number')->label('VAT number'),
                TextInput::make('email')->email(),
                TextInput::make('phone')->tel(),
                Textarea::make('address')->rows(3)->columnSpanFull(),
                FileUpload::make('logo_path')->label('Logo (used on PDFs)')->image()->disk('public')->directory('logos')->columnSpanFull(),
            ]),
            Section::make('Documents and pricing')->columns(3)->schema([
                TextInput::make('quote_prefix')->required()->maxLength(10),
                TextInput::make('sales_order_prefix')->required()->maxLength(10),
                TextInput::make('purchase_order_prefix')->required()->maxLength(10),
                TextInput::make('default_gp_percent')->label('Default GP %')->numeric()->suffix('%')->required(),
                TextInput::make('vat_percent')->label('VAT %')->numeric()->suffix('%')->required(),
                Grid::make(2)->schema([
                    TextInput::make('quote_valid_days')->numeric()->suffix('days'),
                    TextInput::make('quote_follow_up_days')->numeric()->suffix('working days')
                        ->helperText('Automatic follow-up email after a quote is sent'),
                ]),
                Textarea::make('quote_terms')->label('Terms printed on quotes')->rows(4)->columnSpanFull(),
                Textarea::make('bank_details')->rows(4)->columnSpanFull(),
            ]),
        ]);
    }
}
