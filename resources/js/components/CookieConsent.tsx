import { router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    COOKIE_SETTINGS_EVENT,
    openCookieSettings,
} from '@/lib/cookie-consent';
import { useTranslations } from '@/lib/i18n';
import type { ConsentSharedProps } from '@/types';

/**
 * The cookie banner and its settings dialog (SLO-165).
 *
 * Whether it shows is decided by the server from a cookie, not by the browser
 * from localStorage: a client-side decision means the banner flashes onto every
 * server-rendered page before JavaScript catches up, and SSR would render the
 * opposite of what hydration produces.
 *
 * What the decision gates: slot4u's own GA4 tag on the marketing site
 * (SLO-172), and the tenant's own GA4 / Meta Pixel on its public pages
 * (SLO-56) — both emitted by the server, never by this component.
 *
 * Which is also why the banner does not appear everywhere: the server says
 * per request which categories gate anything here (`askable`, SLO-218), and
 * where nothing does, there is no question to put. See docs/19 §11.5.
 */

/**
 * Whether this page has a cookie question at all (SLO-218).
 *
 * Two ways it does: something here is actually gated by a category (`askable`,
 * decided on the server), or the visitor already answered and must be able to
 * change their mind. The second half is not symmetry for its own sake — GDPR
 * 7(3) wants withdrawing to be as easy as consenting, and a tenant switching
 * its measurement off must not strand a visitor with a stored yes and no way
 * back to it.
 */
function hasQuestion(consent: ConsentSharedProps): boolean {
    return consent.askable.length > 0 || consent.decided;
}

/** The "cookie settings" affordance for a footer. */
export function CookieSettingsLink() {
    const t = useTranslations();
    const { consent } = usePage().props;

    if (consent === undefined || consent === null || !hasQuestion(consent)) {
        return null;
    }

    return (
        <button
            type="button"
            onClick={openCookieSettings}
            className="text-xs underline underline-offset-2 hover:text-foreground"
        >
            {t('consent.settings')}
        </button>
    );
}

export function CookieConsent() {
    const t = useTranslations();
    const { consent } = usePage().props;
    const categories = consent?.categories ?? {};

    const [settingsOpen, setSettingsOpen] = useState(false);
    const [draft, setDraft] = useState<Record<string, boolean>>(categories);

    useEffect(() => {
        function open() {
            setDraft(categories);
            setSettingsOpen(true);
        }

        window.addEventListener(COOKIE_SETTINGS_EVENT, open);

        return () => window.removeEventListener(COOKIE_SETTINGS_EVENT, open);
    });

    if (consent === undefined || consent === null) {
        return null;
    }

    const names = Object.keys(categories);

    function save(values: Record<string, boolean>) {
        router.post('/cookie-consent', values, {
            preserveScroll: true,
            // A hard reload, not an Inertia re-render. The measurement tag is
            // written into the document <head> by the server (SLO-172), and an
            // Inertia visit only swaps the page component — the <head> it was
            // served with stays exactly as it was. Without this, accepting would
            // not start measurement until the visitor happened to hard-load a
            // page, and — the half that actually matters — withdrawing consent
            // would leave the already-loaded tag running for the rest of the
            // session.
            onSuccess: () => window.location.reload(),
            onFinish: () => setSettingsOpen(false),
        });
    }

    function all(value: boolean): Record<string, boolean> {
        return Object.fromEntries(names.map((name) => [name, value]));
    }

    return (
        <>
            {/* Undecided AND there is something to decide. The second condition
                is what keeps the embedded demo (SLO-192) showing the product
                instead of a cookie bar: nothing on a tenant page that measures
                nothing depends on the answer, so the question is noise — and
                inside the iframe it landed on the same screen as the marketing
                site's own banner, two consent bars deep before the visitor saw
                a single booking slot. */}
            {!consent.decided && consent.askable.length > 0 && (
                <div
                    role="region"
                    aria-label={t('consent.title')}
                    className="fixed inset-x-0 bottom-0 z-50 border-t border-border bg-card/95 backdrop-blur"
                >
                    <div className="mx-auto flex w-full max-w-5xl flex-col gap-3 px-4 py-4 text-sm sm:flex-row sm:items-center sm:justify-between sm:px-6">
                        <p className="text-muted-foreground">
                            {t('consent.intro')}
                        </p>
                        <div className="flex shrink-0 flex-wrap gap-2">
                            {/* Refusing is one click, exactly like accepting.
                                A "reject" buried two levels down is the pattern
                                that makes a banner worthless as consent. */}
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => setSettingsOpen(true)}
                            >
                                {t('consent.settings')}
                            </Button>
                            <Button
                                variant="secondary"
                                size="sm"
                                onClick={() => save(all(false))}
                            >
                                {t('consent.reject')}
                            </Button>
                            <Button size="sm" onClick={() => save(all(true))}>
                                {t('consent.accept')}
                            </Button>
                        </div>
                    </div>
                </div>
            )}

            <Dialog open={settingsOpen} onOpenChange={setSettingsOpen}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>{t('consent.title')}</DialogTitle>
                        <DialogDescription>
                            {t('consent.intro')}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="flex flex-col gap-3">
                        {/* Listed, not offered: the session cookie is what makes
                            a booking form work, and a toggle beside it would
                            suggest refusing it is a choice. */}
                        <div className="rounded-lg border border-border px-3 py-2.5">
                            <p className="text-sm font-medium">
                                {t('consent.category.necessary')}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                {t('consent.category.necessary_hint')}
                            </p>
                        </div>

                        {names.map((name) => (
                            <label
                                key={name}
                                className="flex items-start gap-2.5 rounded-lg border border-border px-3 py-2.5"
                            >
                                <input
                                    type="checkbox"
                                    checked={draft[name] ?? false}
                                    onChange={(event) =>
                                        setDraft({
                                            ...draft,
                                            [name]: event.target.checked,
                                        })
                                    }
                                    className="mt-0.5 size-4 shrink-0 rounded border-input"
                                />
                                <span>
                                    <span className="block text-sm font-medium">
                                        {t(`consent.category.${name}`)}
                                    </span>
                                    <span className="block text-xs text-muted-foreground">
                                        {t(`consent.category.${name}_hint`)}
                                    </span>
                                </span>
                            </label>
                        ))}
                    </div>

                    <DialogFooter className="gap-2 sm:gap-2">
                        <Button
                            variant="secondary"
                            onClick={() => save(all(false))}
                        >
                            {t('consent.reject')}
                        </Button>
                        <Button onClick={() => save(draft)}>
                            {t('consent.save')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
