@props(['tenantName' => null])
@php($brand = app(\App\Support\Mail\MailBrand::class))
<x-mail::layout>
    {{-- Header --}}
    <x-slot:header>
        <x-mail::header :url="config('app.url')">
            {{ $tenantName ?? config('app.name') }}
        </x-mail::header>
    </x-slot:header>

    {{-- Body --}}
    {{ $slot }}

    {{-- Subcopy --}}
    @isset($subcopy)
        <x-slot:subcopy>
            <x-mail::subcopy>
                {{ $subcopy }}
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
