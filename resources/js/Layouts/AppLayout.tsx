import { usePage } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';

import { CookieConsent } from '@/components/CookieConsent';
import ImpersonationBanner from '@/components/ImpersonationBanner';

export default function AppLayout({ children }: PropsWithChildren) {
    const { auth } = usePage().props;

    return (
        <div className="flex min-h-screen flex-col bg-background text-foreground">
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
