<x-filament-widgets::widget>
    <style>
        .rp-head { display:flex; align-items:center; gap:.75rem; margin-bottom:.75rem; flex-wrap:wrap; }
        .rp-title { font-weight:600; font-size:1rem; }
        .rp-tabs { display:flex; gap:.25rem; margin-left:auto; }
        .rp-tab { font-size:.75rem; padding:.25rem .6rem; border-radius:9999px; cursor:pointer; border:1px solid rgba(127,127,127,.25); background:transparent; color:inherit; }
        .rp-tab.on { background:rgb(var(--primary-600, 79 70 229)); border-color:transparent; color:#fff; }
        .rp-grid { display:grid; gap:1rem; grid-template-columns:repeat(auto-fill, minmax(280px, 1fr)); }
        .rp-card { border-radius:.85rem; padding:1rem 1.1rem; background:var(--fi-color-white, #fff); outline:1px solid rgba(0,0,0,.07); box-shadow:0 1px 2px rgba(0,0,0,.04); }
        .dark .rp-card { background:rgba(255,255,255,.04); outline-color:rgba(255,255,255,.1); }
        .rp-rep { display:flex; align-items:center; gap:.65rem; }
        .rp-avatar { width:2.2rem; height:2.2rem; border-radius:9999px; display:grid; place-items:center; font-weight:700; font-size:.8rem; color:#fff; background:linear-gradient(135deg,#6366f1,#0ea5e9); }
        .rp-name { font-weight:600; }
        .rp-sub { font-size:.72rem; opacity:.6; }
        .rp-sales { font-size:1.6rem; font-weight:700; margin-top:.7rem; letter-spacing:-.01em; }
        .rp-bar { height:.45rem; border-radius:9999px; background:rgba(127,127,127,.18); overflow:hidden; margin-top:.35rem; }
        .rp-bar > div { height:100%; border-radius:9999px; }
        .rp-stats { display:grid; grid-template-columns:repeat(3,1fr); gap:.5rem; margin-top:.85rem; }
        .rp-stat-label { font-size:.68rem; opacity:.6; text-transform:uppercase; letter-spacing:.03em; }
        .rp-stat-value { font-weight:600; font-size:.95rem; }
        .rp-comm { display:flex; justify-content:space-between; gap:.5rem; margin-top:.85rem; padding-top:.7rem; border-top:1px dashed rgba(127,127,127,.3); font-size:.78rem; }
        .rp-comm b { font-size:.9rem; }
        .rp-unpaid { color:#b45309; } .rp-paid { color:#047857; }
        .rp-empty { opacity:.6; font-size:.85rem; }
    </style>

    <div class="rp-head">
        <span class="rp-title">Sales performance - {{ $periodLabel }}</span>
        <div class="rp-tabs">
            @foreach (['month' => 'This month', 'last_month' => 'Last month', 'quarter' => 'Quarter', 'year' => 'Tax year'] as $key => $label)
                <button type="button" wire:click="$set('period', '{{ $key }}')" class="rp-tab {{ $period === $key ? 'on' : '' }}">{{ $label }}</button>
            @endforeach
        </div>
    </div>

    @if ($cards->isEmpty())
        <div class="rp-empty">No sales people yet. Add them under Settings - Team & sales people.</div>
    @else
        <div class="rp-grid">
            @foreach ($cards as $c)
                @php($tp = $c['target_percent'])
                @php($barColour = $tp === null ? '#94a3b8' : ($tp >= 100 ? '#10b981' : ($tp >= 70 ? '#f59e0b' : '#6366f1')))
                <div class="rp-card">
                    <div class="rp-rep">
                        <div class="rp-avatar">{{ strtoupper($c['initials']) }}</div>
                        <div>
                            <div class="rp-name">{{ $c['name'] }}</div>
                            <div class="rp-sub">{{ $c['orders'] }} invoiced · {{ rtrim(rtrim(number_format($c['commission_rate'], 2), '0'), '.') }}% commission on GP</div>
                        </div>
                    </div>

                    <div class="rp-sales">{{ $money($c['sales']) }}</div>
                    <div class="rp-sub">
                        invoiced sales excl. VAT
                        @if ($c['target']) · {{ number_format($tp, 0) }}% of {{ $money($c['target']) }} target @endif
                    </div>
                    @if ($c['target'])
                        <div class="rp-bar"><div style="width: {{ min(100, $tp) }}%; background: {{ $barColour }}"></div></div>
                    @endif

                    <div class="rp-sub" style="margin-top:.35rem">Order book: {{ $money($c['order_book']) }} in {{ $c['order_book_count'] }} order(s) not yet invoiced</div>

                    <div class="rp-stats">
                        <div><div class="rp-stat-label">GP</div><div class="rp-stat-value">{{ $money($c['gp']) }}</div></div>
                        <div><div class="rp-stat-label">GP %</div><div class="rp-stat-value">{{ $pct($c['gp_percent']) }}</div></div>
                        <div><div class="rp-stat-label">Commission</div><div class="rp-stat-value">{{ $money($c['commission']) }}</div></div>
                    </div>

                    <a href="{{ $commissionUrl }}?filters[rep][value]={{ $c['id'] }}" class="rp-comm" style="text-decoration:none; color:inherit">
                        <span class="rp-unpaid">Unpaid<br><b>{{ $money($c['unpaid']) }}</b></span>
                        <span>Not due yet<br><b>{{ $money($c['pending']) }}</b></span>
                        <span class="rp-paid">Paid {{ $periodLabel }}<br><b>{{ $money($c['paid_in_period']) }}</b></span>
                    </a>
                </div>
            @endforeach
        </div>
    @endif
</x-filament-widgets::widget>
