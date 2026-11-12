<x-filament-panels::page>
    @php($money = fn ($v) => 'R '.number_format((float) $v, 0, '.', ' '))
    @php($totals = $this->repTotals())
    <style>
        .cm-grid { display:grid; gap:1rem; grid-template-columns:repeat(auto-fill, minmax(240px,1fr)); }
        .cm-card { border-radius:.75rem; padding:.9rem 1rem; background:var(--fi-color-white,#fff); outline:1px solid rgba(0,0,0,.07); }
        .dark .cm-card { background:rgba(255,255,255,.04); outline-color:rgba(255,255,255,.1); }
        .cm-rep { font-weight:600; }
        .cm-row { display:flex; justify-content:space-between; font-size:.82rem; margin-top:.3rem; }
        .cm-unpaid { color:#b45309; font-weight:600; } .cm-paid { color:#047857; } .cm-muted { opacity:.6; }
    </style>

    @if ($totals->isNotEmpty())
        <div class="cm-grid">
            @foreach ($totals as $t)
                <div class="cm-card">
                    <div class="cm-rep">{{ $t['rep'] }} <span class="cm-muted" style="font-weight:400">· {{ $t['orders'] }} order(s) shown</span></div>
                    <div class="cm-row"><span>Earned</span><b>{{ $money($t['earned']) }}</b></div>
                    <div class="cm-row cm-unpaid"><span>Unpaid (invoiced)</span><span>{{ $money($t['unpaid']) }}</span></div>
                    <div class="cm-row cm-paid"><span>Paid</span><span>{{ $money($t['paid']) }}</span></div>
                    <div class="cm-row cm-muted"><span>Not due yet</span><span>{{ $money($t['not_due']) }}</span></div>
                </div>
            @endforeach
        </div>
    @endif

    {{ $this->table }}

    <p style="font-size:.8rem; opacity:.6">Commission becomes due once an order is invoiced. Totals above follow the filters on the table (default: unpaid).</p>
</x-filament-panels::page>
