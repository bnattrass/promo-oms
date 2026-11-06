@extends('pdf.layout')

@section('title', 'SALES ORDER')

@section('meta')
    Date: {{ $doc->order_date?->format('j M Y') }}<br>
    @if($doc->customer_po_number) Your order no: {{ $doc->customer_po_number }}<br>@endif
    @if($doc->reference) Reference: {{ $doc->reference }}<br>@endif
    @if($doc->required_by) Required by: {{ $doc->required_by->format('j M Y') }}@endif
@endsection

@section('party')
    <table><tr>
        <td class="box" style="width:48%">
            <strong>Customer</strong><br>
            {{ $doc->customer?->name }}<br>
            @if($doc->contact) Attn: {{ $doc->contact->name }}<br>@endif
            {!! nl2br(e($doc->customer?->billing_address)) !!}
        </td>
        <td style="width:4%"></td>
        <td class="box" style="width:48%">
            <strong>Deliver to</strong><br>
            {!! nl2br(e($doc->delivery_address ?: $doc->customer?->delivery_address)) !!}
        </td>
    </tr></table>
@endsection

@section('lines')
    @include('pdf._lines', ['lines' => $doc->lines, 'showSetup' => true, 'costMode' => false])
    @if((float) $doc->deposit_required > 0)
        <table class="totals" style="width:40%; margin-left:60%">
            <tr><td>Deposit required</td><td class="num">{{ $money($doc->deposit_required) }}</td></tr>
            <tr><td>Deposit received</td><td class="num">{{ $money($doc->deposit_received) }}</td></tr>
        </table>
    @endif
@endsection

@section('footer')
    @if($doc->notes)<p>{!! nl2br(e($doc->notes)) !!}</p>@endif
    @if($company->bank_details)<p><strong>Banking details</strong><br>{!! nl2br(e($company->bank_details)) !!}</p>@endif
@endsection
