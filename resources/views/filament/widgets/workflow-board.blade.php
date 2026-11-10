<x-filament-widgets::widget>
    <style>
        .wf-board { display:grid; gap:1rem; grid-template-columns:repeat(4, minmax(0,1fr)); }
        @media (max-width: 1100px) { .wf-board { grid-template-columns:repeat(2, minmax(0,1fr)); } }
        @media (max-width: 640px) { .wf-board { grid-template-columns:1fr; } }
        .wf-col { border-radius:.75rem; background:rgba(127,127,127,.06); padding:.75rem; display:flex; flex-direction:column; gap:.75rem; }
        .wf-col-head { display:flex; align-items:center; gap:.5rem; font-weight:600; font-size:.95rem; padding:.1rem .25rem; }
        .wf-dot { width:.6rem; height:.6rem; border-radius:9999px; }
        .wf-col-total { margin-left:auto; font-weight:500; font-size:.8rem; opacity:.65; }
        .wf-stage { border-radius:.6rem; background:var(--fi-color-white, #fff); outline:1px solid rgba(0,0,0,.06); padding:.65rem .75rem; }
        .dark .wf-stage { background:rgba(255,255,255,.04); outline-color:rgba(255,255,255,.1); }
        .wf-stage-head { display:flex; align-items:baseline; gap:.5rem; }
        .wf-stage-label { font-size:.82rem; font-weight:600; }
        .wf-stage-count { margin-left:auto; font-size:1.1rem; font-weight:700; }
        .wf-stage-value { font-size:.75rem; opacity:.6; margin-top:.05rem; }
        .wf-items { margin-top:.4rem; display:flex; flex-direction:column; }
        .wf-item { display:flex; gap:.5rem; align-items:baseline; font-size:.78rem; padding:.28rem 0; border-top:1px solid rgba(127,127,127,.15); text-decoration:none; color:inherit; }
        .wf-item:hover .wf-num { text-decoration:underline; }
        .wf-num { font-weight:600; white-space:nowrap; }
        .wf-cust { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; flex:1; opacity:.8; }
        .wf-age { white-space:nowrap; opacity:.55; }
        .wf-overdue { color:#dc2626; opacity:1; font-weight:600; }
        .wf-more { font-size:.75rem; margin-top:.3rem; display:inline-block; opacity:.7; text-decoration:underline; }
        .wf-empty { font-size:.75rem; opacity:.45; margin-top:.3rem; }
    </style>

    <div class="wf-board">
        @foreach ($columns as $column)
            <div class="wf-col">
                <div class="wf-col-head">
                    <span class="wf-dot" style="background: {{ $column['colour'] }}"></span>
                    {{ $column['title'] }}
                    <span class="wf-col-total">{{ collect($column['stages'])->sum('count') }} · {{ $money(collect($column['stages'])->sum('value')) }}</span>
                </div>

                @foreach ($column['stages'] as $stage)
                    <div class="wf-stage" style="border-left:3px solid {{ $column['colour'] }}">
                        <div class="wf-stage-head">
                            <a href="{{ $stage['listUrl'] }}" class="wf-stage-label" style="text-decoration:none; color:inherit">{{ $stage['label'] }}</a>
                            <span class="wf-stage-count">{{ $stage['count'] }}</span>
                        </div>
                        <div class="wf-stage-value">{{ $money($stage['value']) }} ex VAT</div>

                        @if ($stage['items']->isEmpty())
                            <div class="wf-empty">Nothing here</div>
                        @else
                            <div class="wf-items">
                                @foreach ($stage['items'] as $item)
                                    <a href="{{ $item['url'] }}" class="wf-item">
                                        <span class="wf-num">{{ $item['number'] }}</span>
                                        <span class="wf-cust">{{ $item['customer'] }}</span>
                                        <span class="wf-age {{ $item['overdue'] ? 'wf-overdue' : '' }}" title="{{ $item['overdue'] ? 'Past the required date' : 'Days at this stage since the document date' }}">{{ $item['age'] }}d</span>
                                    </a>
                                @endforeach
                            </div>
                            @if ($stage['count'] > $stage['items']->count())
                                <a href="{{ $stage['listUrl'] }}" class="wf-more">+ {{ $stage['count'] - $stage['items']->count() }} more</a>
                            @endif
                        @endif
                    </div>
                @endforeach
            </div>
        @endforeach
    </div>
</x-filament-widgets::widget>
