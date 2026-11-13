<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Tenancy\EditCompanyProfile;
use App\Filament\Widgets\OpenOrdersTable;
use App\Filament\Widgets\RepPerformance;
use App\Filament\Widgets\SalesStats;
use App\Filament\Widgets\WorkflowBoard;
use App\Models\Company;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->passwordReset()
            ->profile()
            ->brandName(config('app.name'))
            ->colors(['primary' => Color::Indigo])
            ->maxContentWidth(Width::Full)
            // Small layout tweaks: list-page tabs aligned left instead of centred.
            ->renderHook(PanelsRenderHook::STYLES_AFTER, fn (): string => '<style>.fi-sc-tabs > .fi-tabs:not(.fi-contained){margin-inline-start:0;margin-inline-end:auto}</style>')
            ->tenant(Company::class, slugAttribute: 'slug')
            ->tenantProfile(EditCompanyProfile::class)
            ->navigationGroups(['Sales', 'Purchasing', 'Catalogue', 'Settings'])
            ->databaseNotifications()
            ->globalSearchKeyBindings(['command+k', 'ctrl+k'])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([Dashboard::class])
            ->widgets([WorkflowBoard::class, RepPerformance::class, SalesStats::class, OpenOrdersTable::class])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([Authenticate::class]);
    }
}
