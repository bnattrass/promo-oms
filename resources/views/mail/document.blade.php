<!DOCTYPE html>
<html><body style="font-family: Arial, sans-serif; font-size: 14px; color: #1f2933; line-height: 1.5">
{!! nl2br(e($body)) !!}
<hr style="border:none; border-top:1px solid #e5e7eb; margin:24px 0">
<div style="font-size:12px; color:#6b7280">{{ $company->name }}@if($company->phone) &middot; {{ $company->phone }}@endif @if($company->email) &middot; {{ $company->email }}@endif</div>
</body></html>
