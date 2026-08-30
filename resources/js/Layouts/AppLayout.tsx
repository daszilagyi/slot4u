import { usePage } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';

import { CookieConsent } from '@/components/CookieConsent';
import ImpersonationBanner from '@/components/ImpersonationBanner';
import { PLATFORM_ACCENT_STYLE } from '@/lib/brand';

type AppLayoutProps = PropsWithChildren<{
    /**
     * Paint the subtree in slot4u's own accent (SLO-170).
     *
     * Opt-in per page rather than a property of this shell, because the shell
     * carries screens with two different owners: the superadmin panel is ours,
     * while the auth cards are a company's staff signing in to their own
     * booking system, and their login is their brand, not ours. There is no
     * shared prop that separates the two either — Fortify's routes are
     * host-agnostic, so `tenant` is null on a tenant's login page as well.
     */
    platformAccent?: boolean;
}>;

export default function AppLayout({
    platformAccent = false,
    children,
}: AppLayoutProps) {
    const { auth } = usePage().props;

    return (
        <div
            style={platformAccent ? PLATFORM_ACCENT_STYLE : undefined}
            className="flex min-h-screen flex-col bg-background text-foreground"
        >
            <ImpersonationBanner />
            <main className="flex flex-1 items-center justify-center px-6 py-16">
                {children}
            </main>

            {/* Only for a visitor (SLO-165). This shell carries both the central
                landing page and the superadmin panel; someone already signed in
                and working is not browsing a marketing page, and a banner
                sitting across the bottom of every admin screen until dismissed
                is noise rather than a choice. The tenant's public surface shows
                it unconditionally — that is where analytics would run. */}
            {auth.user === null && <CookieConsent />}
        </div>
    );
}
