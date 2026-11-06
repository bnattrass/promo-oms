@extends('pdf.layout')

@section('title', 'QUOTATION')

@section('meta')
    Date: {{ $doc->quote_date?->format('j M Y') }}<br>
    @if($doc->valid_until) Valid until: {{ $doc->valid_until->format('j M Y') }}<br>@endif
    @if($doc->reference) Reference: {{ $doc->reference }}<br>@endif
    @if($doc->salesRep) Sales rep: {{ $doc->salesRep->name }}@endif
@endsection

@section('party')
    <div class="box" style="width:50%">
        <strong>Quote for</strong><br>
        {{ $doc->customer?->name }}<br>
        @if($doc->contact) Attn: {{ $doc->contact->name }}<br>@endif
        {!! nl2br(e($doc->customer?->billing_address)) !!}
    </div>
@endsection

@section('lines')
    @include('pdf._lines', ['lines' => $doc->lines, 'showSetup' => $doc->show_setup_separately, 'costMode' => false])
@endsection

@section('footer')
    @if($doc->required_by)<p><strong>Required by:</strong> {{ $doc->required_by->format('j M Y') }}</p>@endif
    @if($doc->notes)<p>{!! nl2br(e($doc->notes)) !!}</p>@endif
    @if($company->quote_terms)<p class="muted">{!! nl2br(e($company->quote_terms)) !!}</p>@endif
    @if($company->bank_details)<p><strong>Banking details</strong><br>{!! nl2br(e($company->bank_details)) !!}</p>@endif
@endsection
