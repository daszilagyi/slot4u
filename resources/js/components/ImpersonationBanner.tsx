import { router, usePage } from '@inertiajs/react';

import { useTranslations } from '@/lib/i18n';

/**
 * Sticky bar shown while a superadmin is impersonating a tenant (SLO-79). The
 * `impersonation` shared prop is only set inside the impersonated tenant's
 * context, so the bar renders exactly there. Exit is a same-origin DELETE that
 * ends the session and returns the superadmin to the admin panel.
 */
export default function ImpersonationBanner() {
    const t = useTranslations();
    const { impersonation } = usePage().props;

    if (!impersonation) {
        return null;
    }

    function exit() {
        router.delete(impersonation!.stopUrl, { preserveScroll: false });
    }

    return (
        <div className="flex items-center justify-center gap-4 bg-amber-500 px-6 py-2 text-sm font-medium text-amber-950">
            <span>
                {t('impersonation.banner', {
                    tenant: impersonation.tenant.name,
                })}
            </span>
            <button
                type="button"
                onClick={exit}
                className="rounded-md bg-amber-950/10 px-3 py-1 font-semibold underline-offset-2 hover:bg-amber-950/20 hover:underline"
            >
                {t('impersonation.exit')}
            </button>
        </div>
    );
}
