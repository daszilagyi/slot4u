@props(['tenantName' => null])
{{--
    Every system email's frame (SLO-244). `tenantName` is set only on mail a
    tenant sends to its customers: the header then names the tenant and the
    footer says the letter came through slot4u. Without it, the mail is slot4u's
    own and both say slot4u.
--}}
@php($brand = app(\App\Support\Mail\MailBrand::class))
<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="config('app.url')" :name="$tenantName">
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
@if ($tenantName !== null)
{{ __('app.mail.layout.sent_by_tenant', ['tenant' => $tenantName, 'brand' => config('app.name')]) }}

@endif
@if ($brand->footerText !== null)
{{ $brand->footerText }}

@endif
© {{ date('Y') }} {{ config('app.name') }} · {{ __('app.mail.layout.tagline') }}
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
