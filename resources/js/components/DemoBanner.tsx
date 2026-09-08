import { usePage } from '@inertiajs/react';

import { useTranslations } from '@/lib/i18n';

/**
 * The thin "DEMO · fictional data" bar on every page of a demo tenant
 * (SLO-192, docs/21 §2.1).
 *
 * ⚠️ This exists so nobody mistakes a fixture for a real business. The demo
 * tenants are named like real companies, priced like real companies and booked
 * like real companies — that realism is the point of them, and it is exactly why
 * the page has to say out loud what it is. A visitor who books here gets no
 * email, and a phone number on one of these pages belongs to nobody.
 *
 * Driven by the `tenant.is_demo` shared prop rather than a per-page prop: the
 * visitor usually arrives inside the marketing site's iframe and can click
 * anywhere from there, so a bar that one controller remembers to send is a bar
 * that vanishes on the second page.
 *
 * Warn amber on navy text — the one place in the identity where `warn` is a
 * surface rather than an icon colour, because a warning nobody notices is not
 * one (docs/21 §1).
 */
export default function DemoBanner() {
    const t = useTranslations();
    const { tenant } = usePage().props;

    if (tenant?.is_demo !== true) {
        return null;
    }

    return (
        <div
            // `role="note"`, not `alert`: it is true for the whole visit rather
            // than the response to an action, and an assertive live region would
            // interrupt a screen-reader user mid-sentence on every page load.
            role="note"
            className="bg-warn px-4 py-1.5 text-center text-[13px] font-medium text-navy"
        >
            {t('tenant.demo.banner')}
        </div>
    );
}
