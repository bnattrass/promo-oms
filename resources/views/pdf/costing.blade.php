@php($pct = fn ($v) => number_format((float) $v, 1).'%')
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Costing {{ $doc->number }}</title>
<style>
    @page { margin: 24px 28px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #1f2933; }
    h1 { font-size: 16px; margin: 0; }
    .muted { color: #6b7280; }
    .internal { background:#fef3c7; color:#92400e; padding:4px 8px; font-weight:bold; display:inline-block; margin-top:4px; }
    table { width: 100%; border-collapse: collapse; }
    th { background: #111827; color: #fff; text-align: left; padding: 5px; font-size: 8px; text-transform: uppercase; }
    td { padding: 5px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
    .num { text-align: right; white-space: nowrap; }
    .sub { color:#4b5563; font-size:8px; }
    .tot td { font-weight: bold; border-top: 2px solid #111827; }
    .good { color:#047857; } .warn { color:#b45309; } .bad { color:#b91c1c; }
    .boxes td { border: none; padding: 0 12px 0 0; }
    .box { border:1px solid #e5e7eb; padding:8px; }
    .big { font-size: 14px; font-weight: bold; }
</style>
</head>
<body>
<table class="boxes"><tr>
    <td style="width:50%">
        <h1>Costing sheet - {{ $doc->number }}</h1>
        <div class="muted">{{ $doc->customer?->name }} @if($doc->reference) · {{ $doc->reference }} @endif</div>
        <div class="muted">Sales rep: {{ $doc->salesRep?->name ?? '-' }} · {{ now()->format('j M Y H:i') }}</div>
        <div class="internal">INTERNAL - NOT FOR CUSTOMERS</div>
    </td>
    <td style="width:50%; text-align:right">
        <div class="muted">Sell (excl. VAT)</div><div class="big">{{ $money($doc->subtotal) }}</div>
        <div class="muted" style="margin-top:4px">Cost {{ $money($doc->cost_total) }} · GP <b>{{ $money($doc->gp_amount) }}</b> ({{ $pct($doc->gp_percent) }}) · Commission {{ $money($doc->commission_total) }}</div>
    </td>
</tr></table>

<table style="margin-top:12px">
    <thead><tr>
        <th>Item</th>
        <th class="num">Qty</th>
        <th class="num">Item cost /u</th>
        <th class="num">Branding /u</th>
        <th class="num">Setup</th>
        <th class="num">Extras</th>
        <th class="num">Total cost</th>
        <th class="num">Sell /u</th>
        <th class="num">Setup sell</th>
        <th class="num">Line sell</th>
        <th class="num">GP</th>
        <th class="num">GP %</th>
        <th class="num">Comm.</th>
    </tr></thead>
    <tbody>
    @foreach($doc->lines as $line)
        @php($gpClass = $line->gp_percent < 20 ? 'bad' : ($line->gp_percent < 30 ? 'warn' : 'good'))
        <tr>
            <td>
                <b>{{ $line->item_code }}</b> {{ $line->description }}
                @foreach(($line->brandings ?? []) as $b)
                    <div class="sub">
                        {{ $b['position_code'] ?? '' }} {{ $b['method_name'] ?? $b['branding_code'] ?? '' }}
                        @if(!empty($b['colours']) && $b['colours'] > 0) · {{ $b['colours'] }}col @endif
                        @if(($b['placements'] ?? 1) > 1) · x{{ $b['placements'] }} @endif
                        · {{ $b['print_code'] ?? $b['priced_code'] ?? '' }}
                        · {{ $money($b['unit_cost'] ?? 0) }}/u + {{ $money($b['setup_cost'] ?? 0) }} setup
                        @if(empty($b['price_found']) && empty($b['inclusive']) && empty($b['method_inclusive']) && empty($b['unit_cost_override'])) <span class="bad">NO SUPPLIER PRICE</span> @endif
                    </div>
                @endforeach
            </td>
            <td class="num">{{ number_format($line->quantity) }}</td>
            <td class="num">{{ $money($line->unit_cost) }}</td>
            <td class="num">{{ $money($line->branding_unit_cost) }}</td>
            <td class="num">{{ $money($line->setup_cost) }}</td>
            <td class="num">{{ $money($line->extra_cost) }}</td>
            <td class="num">{{ $money($line->total_cost) }}</td>
            <td class="num">{{ $money($line->unit_price) }}</td>
            <td class="num">{{ $money($line->setup_price) }}</td>
            <td class="num">{{ $money($line->line_total) }}</td>
            <td class="num {{ $gpClass }}">{{ $money($line->gp_amount) }}</td>
            <td class="num {{ $gpClass }}">{{ $pct($line->gp_percent) }}</td>
            <td class="num">{{ $money($line->commission_amount) }}</td>
        </tr>
    @endforeach
    <tr class="tot">
        <td colspan="6">Totals (excl. VAT)</td>
        <td class="num">{{ $money($doc->cost_total) }}</td>
        <td colspan="2"></td>
        <td class="num">{{ $money($doc->subtotal) }}</td>
        <td class="num">{{ $money($doc->gp_amount) }}</td>
        <td class="num">{{ $pct($doc->gp_percent) }}</td>
        <td class="num">{{ $money($doc->commission_total) }}</td>
    </tr>
    </tbody>
</table>
<p class="muted" style="margin-top:8px">Costs are supplier costs excl. VAT at the time of quoting. GP = line sell - total cost. GP % = GP / line sell.</p>
</body>
</html>
