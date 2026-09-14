{{--
    The body of every MailMessage notification (SLO-244). Same structure as the
    framework's, with its English strings ("Hello!", "Regards,", the subcopy)
    moved to lang keys, and the sender's name — the tenant on a tenant's mail —
    handed to the frame and used in the sign-off.
--}}
@php($senderName = $tenantName ?? config('app.name'))
<x-mail::message :tenant-name="$tenantName ?? null">
{{-- Greeting --}}
@if (! empty($greeting))
# {{ $greeting }}
@else
@if ($level === 'error')
# {{ __('app.mail.layout.greeting_error') }}
@else
# {{ __('app.mail.layout.greeting') }}
@endif
@endif

{{-- Intro Lines --}}
@foreach ($introLines as $line)
{{ $line }}

@endforeach

{{-- Action Button --}}
@isset($actionText)
<?php
    $color = match ($level) {
        'success', 'error' => $level,
        default => 'primary',
    };
?>
<x-mail::button :url="$actionUrl" :color="$color">
{{ $actionText }}
</x-mail::button>
@endisset

{{-- Outro Lines --}}
@foreach ($outroLines as $line)
{{ $line }}

@endforeach

{{-- Salutation --}}
@if (! empty($salutation))
{{ $salutation }}
@else
{{ __('app.mail.layout.salutation') }}<br>
{{ $senderName }}
@endif

{{-- Subcopy --}}
@isset($actionText)
<x-slot:subcopy>
{{ __('app.mail.layout.subcopy', ['actionText' => $actionText]) }} <span class="break-all">[{{ $displayableActionUrl }}]({{ $actionUrl }})</span>
</x-slot:subcopy>
@endisset
</x-mail::message>
