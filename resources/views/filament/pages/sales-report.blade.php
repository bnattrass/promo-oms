<x-filament-panels::page>
    <style>
        .sr-grid { display:grid; gap:1rem; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); }
        .sr-card { border-radius:.75rem; padding:1rem 1.25rem; background:var(--fi-color-white, #fff); box-shadow:0 1px 2px rgba(0,0,0,.05); outline:1px solid rgba(0,0,0,.06); }
        .dark .sr-card { background:rgba(255,255,255,.04); outline-color:rgba(255,255,255,.1); }
        .sr-label { font-size:.8rem; opacity:.65; }
        .sr-value { font-size:1.5rem; font-weight:600; margin-top:.15rem; }
        .sr-sub { font-size:.8rem; opacity:.65; margin-top:.15rem; }
        .sr-table { width:100%; font-size:.875rem; border-collapse:collapse; }
        .sr-table th { text-align:left; font-weight:600; font-size:.75rem; text-transform:uppercase; letter-spacing:.03em; opacity:.6; padding:.5rem .75rem; border-bottom:1px solid rgba(127,127,127,.25); }
        .sr-table td { padding:.55rem .75rem; border-bottom:1px solid rgba(127,127,127,.12); }
        .sr-table .num { text-align:right; white-space:nowrap; font-variant-numeric:tabular-nums; }
        .sr-bar { height:.5rem; border-radius:9999px; background:rgb(var(--primary-500, 99 102 241)); min-width:2px; }
        .sr-filters { display:flex; flex-wrap:wrap; gap:.75rem; align-items:end; }
        .sr-filters label { font-size:.8rem; opacity:.7; display:block; margin-bottom:.25rem; }
        .sr-presets { display:flex; flex-wrap:wrap; gap:.4rem; }
    </style>

    {{-- Filters --}}
    <x-filament::section>
        <div class="sr-filters">
            <div>
                <label>From</label>
                <x-filament::input.wrapper><x-filament::input type="date" wire:model.live="from" /></x-filament::input.wrapper>
            </div>
            <div>
                <label>To</label>
                <x-filament::input.wrapper><x-filament::input type="date" wire:model.live="to" /></x-filament::input.wrapper>
            </div>
            @if ($this->canSeeAllReps())
                <div style="min-width:14rem">
                    <label>Sales rep</label>
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model.live="rep">
                            <option value="">All sales people</option>
                            @foreach ($this->repOptions() as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>
            @endif
            <div class="sr-presets">
                @foreach (['this_month' => 'This month', 'last_month' => 'Last month', 'quarter' => 'This quarter', 'last_3' => 'Last 3 months', 'last_12' => 'Last 12 months', 'tax_year' => 'Tax year (Mar-Feb)'] as $key => $label)
                    <x-filament::button size="sm" color="gray" wire:click="preset('{{ $key }}')">{{ $label }}</x-filament::button>
                @endforeach
            </div>
        </div>
    </x-filament::section>

    {{-- Headline numbers --}}
    <div class="sr-grid">
        <div class="sr-card">
            <div class="sr-label">Sales - invoiced (ex VAT)</div>
            <div class="sr-value">{{ $money($summary['sales']) }}</div>
            <div class="sr-sub">
                {{ $summary['orders'] }} orders · avg {{ $money($summary['average_order']) }}
                @if ($summary['target']) · {{ $pct($summary['sales'] / $summary['target'] * 100) }} of {{ $money($summary['target']) }} target @endif
            </div>
        </div>
        <div class="sr-card">
            <div class="sr-label">Gross profit</div>
            <div class="sr-value">{{ $money($summary['gp']) }}</div>
            <div class="sr-sub">{{ $pct($summary['gp_percent']) }} GP</div>
        </div>
        <div class="sr-card">
            <div class="sr-label">Commission</div>
            <div class="sr-value">{{ $money($summary['commission']) }}</div>
            <div class="sr-sub">on orders in this period</div>
        </div>
        <div class="sr-card">
            <div class="sr-label">Quotes issued</div>
            <div class="sr-value">{{ number_format($summary['quotes']) }}</div>
            <div class="sr-sub">{{ $money($summary['quote_value']) }} quoted · {{ $pct($summary['win_rate']) }} won</div>
        </div>
        <div class="sr-card">
            <div class="sr-label">Order book (not yet invoiced)</div>
            <div class="sr-value">{{ $money($summary['order_book']['value']) }}</div>
            <div class="sr-sub">{{ $summary['order_book']['count'] }} open order(s) · GP {{ $money($summary['order_book']['gp']) }}</div>
        </div>
        <div class="sr-card">
            <div class="sr-label">Open quotes</div>
            <div class="sr-value">{{ $money($summary['open_pipeline']) }}</div>
            <div class="sr-sub">draft, sent and accepted quotes</div>
        </div>
    </div>

    {{-- By sales rep --}}
    @if ($this->canSeeAllReps())
        <x-filament::section heading="By sales person">
            @php($maxRep = max(1, $byRep->max('sales') ?? 1))
            <table class="sr-table">
                <thead><tr>
                    <th>Sales person</th><th class="num">Orders</th><th class="num">Sales</th><th style="width:18%"></th>
                    <th class="num">GP</th><th class="num">GP %</th><th class="num">Commission</th><th class="num">Quotes</th><th class="num">Win rate</th><th class="num">vs target</th>
                </tr></thead>
                <tbody>
                @forelse ($byRep as $row)
                    <tr>
                        <td>{{ $row['rep'] }}</td>
                        <td class="num">{{ $row['orders'] }}</td>
                        <td class="num">{{ $money($row['sales']) }}</td>
                        <td><div class="sr-bar" style="width: {{ round($row['sales'] / $maxRep * 100) }}%"></div></td>
                        <td class="num">{{ $money($row['gp']) }}</td>
                        <td class="num">{{ $pct($row['gp_percent']) }}</td>
                        <td class="num">{{ $money($row['commission']) }}</td>
                        <td class="num">{{ $row['quotes'] }}</td>
                        <td class="num">{{ $pct($row['win_rate']) }}</td>
                        <td class="num">{{ $pct($row['target_percent']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="10" style="opacity:.6">No sales in this period.</td></tr>
                @endforelse
                </tbody>
            </table>
        </x-filament::section>
    @endif

    {{-- By month --}}
    <x-filament::section heading="By month">
        @php($maxMonth = max(1, $byMonth->max('sales') ?? 1))
        <table class="sr-table">
            <thead><tr><th>Month</th><th class="num">Orders</th><th class="num">Sales</th><th style="width:30%"></th><th class="num">GP</th><th class="num">GP %</th><th class="num">Quotes</th></tr></thead>
            <tbody>
            @foreach ($byMonth as $row)
                <tr>
                    <td>{{ $row['month'] }}</td>
                    <td class="num">{{ $row['orders'] }}</td>
                    <td class="num">{{ $money($row['sales']) }}</td>
                    <td><div class="sr-bar" style="width: {{ round($row['sales'] / $maxMonth * 100) }}%"></div></td>
                    <td class="num">{{ $money($row['gp']) }}</td>
                    <td class="num">{{ $pct($row['gp_percent']) }}</td>
                    <td class="num">{{ $row['quotes'] }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </x-filament::section>

    <div style="display:grid; gap:1.5rem; grid-template-columns:repeat(auto-fit,minmax(420px,1fr))">
        <x-filament::section heading="Top customers">
            <table class="sr-table">
                <thead><tr><th>Customer</th><th class="num">Orders</th><th class="num">Sales</th><th class="num">GP %</th></tr></thead>
                <tbody>
                @forelse ($customers as $row)
                    <tr><td>{{ $row['customer'] }}</td><td class="num">{{ $row['orders'] }}</td><td class="num">{{ $money($row['sales']) }}</td><td class="num">{{ $pct($row['gp_percent']) }}</td></tr>
                @empty
                    <tr><td colspan="4" style="opacity:.6">No sales in this period.</td></tr>
                @endforelse
                </tbody>
            </table>
        </x-filament::section>

        <x-filament::section heading="Products vs services">
            <table class="sr-table">
                <thead><tr><th>Line type</th><th class="num">Lines</th><th class="num">Sales</th><th class="num">GP</th><th class="num">GP %</th></tr></thead>
                <tbody>
                @forelse ($lineTypes as $row)
                    <tr><td>{{ $row['type'] }}</td><td class="num">{{ $row['lines'] }}</td><td class="num">{{ $money($row['sales']) }}</td><td class="num">{{ $money($row['gp']) }}</td><td class="num">{{ $pct($row['gp_percent']) }}</td></tr>
                @empty
                    <tr><td colspan="5" style="opacity:.6">No sales in this period.</td></tr>
                @endforelse
                </tbody>
            </table>
        </x-filament::section>
    </div>

    <p style="font-size:.8rem; opacity:.6">Sales = orders marked as invoiced, by invoice date, ex VAT. Orders not yet invoiced show as the order book. Quotes counted by quote date; win rate = converted ÷ (converted + declined + expired).</p>
</x-filament-panels::page>
