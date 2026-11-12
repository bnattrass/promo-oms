<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\Commission;
use App\Services\Reports\CommissionReport;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

/** One card per sales rep: this month's sales, GP, commission, target progress and unpaid commission. */
class RepPerformance extends Widget
{
    protected string $view = 'filament.widgets.rep-performance';

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    public string $period = 'month';

    /** Commission and rep performance are for full admins only. */
    public static function canView(): bool
    {
        return auth()->user()?->isAdminOf(Filament::getTenant()) ?? false;
    }

    protected function getViewData(): array
    {
        $company = Filament::getTenant();

        [$from, $to, $label] = match ($this->period) {
            'last_month' => [now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth(), 'last month'],
            'quarter' => [now()->firstOfQuarter(), now(), 'this quarter'],
            'year' => [now()->month >= 3 ? now()->setDate(now()->year, 3, 1) : now()->setDate(now()->year - 1, 3, 1), now(), 'this tax year'],
            default => [now()->startOfMonth(), now(), 'this month'],
        };

        $cards = (new CommissionReport($company->id))->repCards(
            CarbonImmutable::instance($from)->startOfDay(),
            CarbonImmutable::instance($to)->endOfDay(),
        );

        return [
            'cards' => $cards,
            'periodLabel' => $label,
            'commissionUrl' => Commission::getUrl(),
            'money' => fn ($v) => 'R '.number_format((float) $v, 0, '.', ' '),
            'pct' => fn ($v) => number_format((float) $v, 1).'%',
        ];
    }
}
