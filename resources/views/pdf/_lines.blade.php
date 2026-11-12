{{-- $lines, $money, $showSetup (bool), $costMode (bool: purchase order shows cost), $thumbs (line id => data uri) --}}
@php($withImages = collect($thumbs ?? [])->filter()->isNotEmpty())
@php($showSetup = $showSetup && $lines->sum($costMode ? 'setup_cost' : 'setup_price') > 0)
<table class="lines">
    <thead>
    <tr>
        @if($withImages)<th style="width:70px"></th>@endif
        <th style="width:14%">Code</th>
        <th>Description</th>
        <th class="num" style="width:8%">Qty</th>
        <th class="num" style="width:13%">Unit price<br><span style="font-weight:normal;text-transform:none">excl. VAT</span></th>
        @if($showSetup)<th class="num" style="width:12%">Setup<br><span style="font-weight:normal;text-transform:none">excl. VAT</span></th>@endif
        <th class="num" style="width:14%">Total<br><span style="font-weight:normal;text-transform:none">excl. VAT</span></th>
    </tr>
    </thead>
    <tbody>
    @foreach($lines as $line)
        <tr>
            @if($withImages)
                <td style="width:70px">@if(!empty($thumbs[$line->id]))<img src="{{ $thumbs[$line->id] }}" style="width:62px; height:62px; object-fit:contain">@endif</td>
            @endif
            <td>{{ $line->item_code }}</td>
            <td>
                {{ $line->description }}
                @foreach(($line->brandings ?? []) as $b)
                    <div class="branding">
                        {{ $b['position_name'] ?? $b['position_code'] ?? '' }}:
                        {{ $b['method_name'] ?? $b['branding_code'] ?? '' }}
                        @if(!empty($b['colours']) && $b['colours'] > 0) &middot; {{ $b['colours'] }} colour(s) @endif
                        @if(($b['placements'] ?? 1) > 1) &middot; x{{ $b['placements'] }} @endif
                        @if(!empty($b['inclusive']) && ! str_contains(strtolower($b['method_name'] ?? ''), 'inclusive')) &middot; branding included @endif
                        @if($costMode && !empty($b['priced_code'])) &middot; code {{ $b['print_code'] ?? $b['priced_code'] }} @endif
                    </div>
                @endforeach
            </td>
            <td class="num">{{ number_format($line->quantity) }}</td>
            @if($costMode)
                <td class="num">{{ $money($line->unit_cost) }}</td>
                @if($showSetup)<td class="num">{{ $money($line->setup_cost) }}</td>@endif
            @else
                <td class="num">{{ $money($line->unit_price) }}</td>
                @if($showSetup)<td class="num">{{ $money($line->setup_price) }}</td>@endif
            @endif
            <td class="num">{{ $money($line->line_total) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<p class="muted" style="font-size:9px; margin:6px 0 0">All unit, setup and line prices exclude VAT.</p>
<table class="totals" style="width:40%; margin-left:60%; margin-top:6px">
    <tr><td>Subtotal (excl. VAT)</td><td class="num">{{ $money($doc->subtotal) }}</td></tr>
    <tr><td>VAT ({{ rtrim(rtrim(number_format($company->vat_percent, 2), '0'), '.') }}%)</td><td class="num">{{ $money($doc->vat_total) }}</td></tr>
    <tr class="grand"><td>Total</td><td class="num">{{ $money($doc->total) }}</td></tr>
</table>
