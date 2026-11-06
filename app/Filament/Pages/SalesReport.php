<?php

namespace App\Filament\Pages;

use App\Services\Reports\SalesReport as Report;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use UnitEnum;

class SalesReport extends Page
{
    protected string $view = 'filament.pages.sales-report';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 40;

    protected static ?string $navigationLabel = 'Sales reports';

    protected static ?string $title = 'Sales reports';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url]
    public ?string $rep = null;

    public function mount(): void
    {
        $this->from = $this->from ?: now()->startOfMonth()->toDateString();
        $this->to = $this->to ?: now()->toDateString();

        // Sales people only see their own figures; admins see everyone.
        if (! $this->canSeeAllReps()) {
            $this->rep = (string) auth()->id();
        }
    }

    public function canSeeAllReps(): bool
    {
        $user = auth()->user();
        $role = $user?->roleIn(Filament::getTenant());

        return $user?->is_super_admin || in_array($role, ['admin', 'accounts'], true);
    }

    public function preset(string $period): void
    {
        [$from, $to] = match ($period) {
            'this_month' => [now()->startOfMonth(), now()],
            'last_month' => [now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth()],
            'quarter' => [now()->firstOfQuarter(), now()],
            'last_3' => [now()->subMonthsNoOverflow(2)->startOfMonth(), now()],
            'last_12' => [now()->subMonthsNoOverflow(11)->startOfMonth(), now()],
            // South African tax year runs March to February.
            'tax_year' => [now()->month >= 3 ? now()->setDate(now()->year, 3, 1) : now()->setDate(now()->year - 1, 3, 1), now()],
            default => [now()->startOfMonth(), now()],
        };
        $this->from = $from->toDateString();
        $this->to = $to->toDateString();
    }

    public function report(): Report
    {
        $rep = $this->canSeeAllReps() ? ($this->rep ?: null) : auth()->id();

        return new Report(Filament::getTenant()->id, $this->from ?: now()->startOfMonth()->toDateString(), $this->to ?: now()->toDateString(), $rep ? (int) $rep : null);
    }

    public function repOptions(): array
    {
        return Filament::getTenant()->users()->orderBy('name')->pluck('users.name', 'users.id')->all();
    }

    protected function getViewData(): array
    {
        $report = $this->report();

        return [
            'summary' => $report->summary(),
            'byRep' => $report->byRep(),
            'byMonth' => $report->byMonth(),
            'customers' => $report->topCustomers(),
            'lineTypes' => $report->byLineType(),
            'money' => fn ($v) => 'R '.number_format((float) $v, 0, '.', ' '),
            'pct' => fn ($v) => $v === null ? '-' : number_format((float) $v, 1).'%',
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('csv')
                ->label('Export orders (CSV)')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->action(function () {
                    $rows = $this->report()->csvRows();
                    $name = "sales-{$this->from}-to-{$this->to}.csv";

                    return response()->streamDownload(function () use ($rows) {
                        $out = fopen('php://output', 'w');
                        foreach ($rows as $row) {
                            fputcsv($out, $row);
                        }
                        fclose($out);
                    }, $name, ['Content-Type' => 'text/csv']);
                }),
        ];
    }
}
