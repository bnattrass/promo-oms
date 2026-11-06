<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>{{ $doc->number }}</title>
<style>
    @page { margin: 28px 32px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1f2933; }
    h1 { font-size: 20px; margin: 0; letter-spacing: 1px; }
    .muted { color: #6b7280; }
    .header td { vertical-align: top; }
    table { width: 100%; border-collapse: collapse; }
    .lines th { background: #111827; color: #fff; text-align: left; padding: 6px; font-size: 9px; text-transform: uppercase; }
    .lines td { padding: 6px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
    .num { text-align: right; white-space: nowrap; }
    .branding { color: #4b5563; font-size: 9px; margin-top: 2px; }
    .totals td { padding: 4px 6px; }
    .totals .grand td { font-weight: bold; font-size: 12px; border-top: 2px solid #111827; }
    .box { border: 1px solid #e5e7eb; padding: 8px; }
    .footer { margin-top: 18px; font-size: 9px; }
</style>
</head>
<body>
<table class="header">
    <tr>
        <td style="width:55%">
            @if($company->logo_path && file_exists(storage_path('app/public/'.$company->logo_path)))
                <img src="{{ storage_path('app/public/'.$company->logo_path) }}" style="max-height:60px; max-width:200px"><br>
            @else
                <h1>{{ $company->name }}</h1>
            @endif
            <div class="muted" style="margin-top:6px">
                {!! nl2br(e($company->address)) !!}<br>
                @if($company->phone) Tel: {{ $company->phone }}<br>@endif
                @if($company->email) {{ $company->email }}<br>@endif
                @if($company->vat_number) VAT no: {{ $company->vat_number }}<br>@endif
                @if($company->registration_number) Reg no: {{ $company->registration_number }}@endif
            </div>
        </td>
        <td style="width:45%; text-align:right">
            <h1>@yield('title')</h1>
            <div style="font-size:12px; margin-top:4px"><strong>{{ $doc->number }}</strong></div>
            <div class="muted" style="margin-top:6px">@yield('meta')</div>
        </td>
    </tr>
</table>

<div style="margin:16px 0">@yield('party')</div>

@yield('lines')

<div class="footer">@yield('footer')</div>
</body>
</html>
