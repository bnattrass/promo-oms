@extends('pdf.layout')

@section('title', 'PURCHASE ORDER')

@section('meta')
    Date: {{ $doc->order_date?->format('j M Y') }}<br>
    @if($doc->expected_date) Required by: {{ $doc->expected_date->format('j M Y') }}<br>@endif
    @if($doc->salesOrder) Our job: {{ $doc->salesOrder->number }}@endif
@endsection

@section('party')
    <div class="box" style="width:50%">
        <strong>Supplier</strong><br>
        {{ $doc->supplier?->name }}<br>
        @if($doc->supplier?->contact_person) Attn: {{ $doc->supplier->contact_person }}<br>@endif
        {!! nl2br(e($doc->supplier?->address)) !!}
    </div>
@endsection

@section('lines')
    @include('pdf._lines', ['lines' => $doc->lines, 'showSetup' => true, 'costMode' => true])
@endsection

@section('footer')
    @if($doc->notes)<p>{!! nl2br(e($doc->notes)) !!}</p>@endif
    <p class="muted">Please confirm this order and the expected dispatch date by return email, quoting {{ $doc->number }}. Artwork proofs to be approved before production.</p>
@endsection
