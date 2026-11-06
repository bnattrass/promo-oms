{{-- $lines, $money, $showSetup (bool), $costMode (bool: purchase order shows cost) --}}
<table class="lines">
    <thead>
    <tr>
        <th style="width:14%">Code</th>
        <th>Description</th>
        <th class="num" style="width:8%">Qty</th>
        <th class="num" style="width:13%">Unit</th>
        @if($showSetup)<th class="num" style="width:12%">Setup</th>@endif
        <th class="num" style="width:14%">Total</th>
    </tr>
    </thead>
    <tbody>
    @foreach($lines as $line)
        <tr>
            <td>{{ $line->item_code }}</td>
            <td>
                {{ $line->description }}
                @foreach(($line->brandings ?? []) as $b)
                    <div class="branding">
                        {{ $b['position_name'] ?? $b['position_code'] ?? '' }}:
                        {{ $b['method_name'] ?? $b['branding_code'] ?? '' }}
                        @if(!empty($b['colours']) && $b['colours'] > 0) &middot; {{ $b['colours'] }} colour(s) @endif
                        @if(($b['placements'] ?? 1) > 1) &middot; x{{ $b['placements'] }} @endif
                        @if(!empty($b['inclusive'])) &middot; inclusive branding @endif
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

<table class="totals" style="width:40%; margin-left:60%; margin-top:10px">
    <tr><td>Subtotal (excl. VAT)</td><td class="num">{{ $money($doc->subtotal) }}</td></tr>
    <tr><td>VAT ({{ rtrim(rtrim(number_format($company->vat_percent, 2), '0'), '.') }}%)</td><td class="num">{{ $money($doc->vat_total) }}</td></tr>
    <tr class="grand"><td>Total</td><td class="num">{{ $money($doc->total) }}</td></tr>
</table>
