{{--
    slot4u's own GA4 tag (SLO-172).

    Emitted by the server or not at all: `$analytics->loads()` is false unless a
    measurement id is configured, the host is the central marketing domain, and
    the visitor granted the analytics category (SLO-165). Gating this in the
    browser would download gtag.js first and decide afterwards — by then Google
    has already been told the visit happened.

    `send_page_view: false` is deliberate. This is an Inertia SPA: gtag's own
    automatic page view fires once, on the hard load, and never again as the
    visitor moves between pages. Turning it off and reporting every view from
    `resources/js/lib/analytics.ts` — including the first — gives exactly one
    event per page instead of one for the whole session.
--}}
@if ($analytics->loads())
    <script async src="https://www.googletagmanager.com/gtag/js?id={{ urlencode($analytics->measurementId) }}"></script>
    <script nonce="{{ Illuminate\Support\Facades\Vite::cspNonce() }}">
        window.dataLayer = window.dataLayer || [];
        function gtag() { dataLayer.push(arguments); }
        gtag('js', new Date());
        gtag('config', @js($analytics->measurementId), { send_page_view: false });
    </script>
@endif
