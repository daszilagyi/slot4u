{{--
    Measurement tags (SLO-172 platform, SLO-56 tenant).

    Emitted by the server or not at all. Each id below survived three checks
    already — configured, right host, and the visitor's consent for that vendor's
    category — so there is nothing left to decide here. Gating in the browser
    would download the vendor script first and decide afterwards; by then the
    visit has been reported.

    ⚠️ The platform and tenant GA4 ids are mutually exclusive by construction:
    the platform's only loads on the central marketing host, the tenant's only on
    a tenant host, because on a tenant host the tenant is the data controller and
    slot4u merely the processor (docs/19 §2). `$ga4` is therefore one value, not
    a list — if that ever needs to become two, it is a policy change, not a
    template change.
--}}
@php
    $ga4 = $analytics->platform->measurementId ?? $analytics->tenant->ga4MeasurementId;
    $pixel = $analytics->tenant->metaPixelId;
@endphp

@if ($ga4 !== null)
    {{--
        `send_page_view: false` is deliberate. slot4u is an Inertia SPA: gtag's
        automatic view fires once, on the hard load, and never again as the
        visitor moves through the booking flow. Every view is reported from
        `resources/js/lib/analytics.ts` instead — including the first, so there
        is no double count.
    --}}
    <script async src="https://www.googletagmanager.com/gtag/js?id={{ urlencode($ga4) }}"></script>
    <script nonce="{{ Illuminate\Support\Facades\Vite::cspNonce() }}">
        window.dataLayer = window.dataLayer || [];
        function gtag() { dataLayer.push(arguments); }
        gtag('js', new Date());
        gtag('config', @js($ga4), { send_page_view: false });
    </script>
@endif

@if ($pixel !== null)
    {{--
        Meta's own loader, verbatim apart from the missing `fbq('track',
        'PageView')`: that one is fired from the client module for the same
        reason GA4's automatic view is off, so a visitor moving from the service
        list to the booking form is counted twice rather than once.

        The loader inserts its <script> element at runtime with no nonce, which
        is fine — connect.facebook.net is in script-src on exactly this request,
        and only on a request that got here.
    --}}
    <script nonce="{{ Illuminate\Support\Facades\Vite::cspNonce() }}">
        !function (f, b, e, v, n, t, s) {
            if (f.fbq) return; n = f.fbq = function () {
                n.callMethod ? n.callMethod.apply(n, arguments) : n.queue.push(arguments)
            };
            if (!f._fbq) f._fbq = n; n.push = n; n.loaded = !0; n.version = '2.0';
            n.queue = []; t = b.createElement(e); t.async = !0;
            t.src = v; s = b.getElementsByTagName(e)[0];
            s.parentNode.insertBefore(t, s)
        }(window, document, 'script', 'https://connect.facebook.net/en_US/fbevents.js');
        fbq('init', @js($pixel));
    </script>
    {{-- The no-JavaScript path. Nothing else on this page reports for it, and it
         cannot fire the SPA events either, so this one image is its whole story. --}}
    <noscript><img height="1" width="1" style="display:none" alt=""
        src="https://www.facebook.com/tr?id={{ urlencode($pixel) }}&amp;ev=PageView&amp;noscript=1"></noscript>
@endif
