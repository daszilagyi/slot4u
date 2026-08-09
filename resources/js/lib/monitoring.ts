/**
 * Browser error reporting (SLO-153, docs/17).
 *
 * A crashed React tree renders a blank page and reports nothing: the server sees
 * a 200 and the visitor sees white. This is the other half of the picture the
 * Laravel SDK cannot see.
 *
 * Loaded with a dynamic import on purpose. Sentry's browser SDK is ~30 kB
 * gzipped, and a static import would put it in the entry chunk of every page —
 * including the public booking flow, which is the one page whose load time is a
 * business metric. With the import inside the guard, Vite emits it as a separate
 * chunk that is fetched only where a DSN is actually configured; a build without
 * one never even downloads it.
 */
export function startErrorReporting(): void {
    const dsn = import.meta.env.VITE_SENTRY_DSN;

    if (!dsn || typeof window === 'undefined') {
        return;
    }

    void import('@sentry/react').then((Sentry) => {
        Sentry.init({
            dsn,
            environment: import.meta.env.VITE_SENTRY_ENVIRONMENT || 'production',
            release: import.meta.env.VITE_APP_RELEASE || undefined,

            // Never. The same rule as the server side: an id may identify the
            // account, nothing may describe the person.
            sendDefaultPii: false,

            // Errors only. Tracing and session replay are where a monitoring tool
            // starts recording what customers typed, and neither answers the
            // question this issue asks.
            integrations: [],
            tracesSampleRate: 0,

            beforeSend(event) {
                // A booking URL can carry a search term, and the SDK reports the
                // URL a page was on.
                if (event.request?.url) {
                    event.request.url = redact(event.request.url);
                }

                if (event.user) {
                    // Keep only the id the server already knows about.
                    event.user = event.user.id ? { id: event.user.id } : undefined;
                }

                for (const value of event.exception?.values ?? []) {
                    if (value.value) {
                        value.value = redact(value.value);
                    }
                }

                if (event.message) {
                    event.message = redact(event.message);
                }

                return event;
            },

            beforeBreadcrumb(breadcrumb) {
                if (breadcrumb.message) {
                    breadcrumb.message = redact(breadcrumb.message);
                }

                if (typeof breadcrumb.data?.url === 'string') {
                    breadcrumb.data.url = redact(breadcrumb.data.url);
                }

                return breadcrumb;
            },
        });
    });
}

const EMAIL = /[\p{L}\p{N}._%+-]+@[\p{L}\p{N}.-]+\.[\p{L}]{2,}/gu;
const PHONE = /\+\d[\d\s().-]{5,20}\d/g;

/** Mirrors the server's PiiRedactor — the same two identifiers, the same labels. */
function redact(value: string): string {
    return value.replace(EMAIL, '[redacted-email]').replace(PHONE, '[redacted-phone]');
}
