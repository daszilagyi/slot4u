@props(['url', 'name' => null])
{{--
    The navy header band (SLO-244). A tenant's mail names the tenant — the
    customer should see at a glance who writes (Daniel, SLO-243) — while slot4u's
    own mail carries the sloth tile and the wordmark.
--}}
@php($brand = app(\App\Support\Mail\MailBrand::class))
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
@if ($name === null && $brand->logoUrl !== null)
<img src="{{ $brand->logoUrl }}" class="logo" width="40" height="40" alt="">
@endif
<span class="brand-name">{{ $name ?? config('app.name') }}</span>
</a>
</td>
</tr>
