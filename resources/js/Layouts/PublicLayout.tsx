import { Link, usePage } from '@inertiajs/react';
import { motion } from 'framer-motion';
import type { CSSProperties, PropsWithChildren } from 'react';

import ImpersonationBanner from '@/components/ImpersonationBanner';
import ThemeToggle from '@/components/ThemeToggle';
import { Button } from '@/components/ui/button';
import { useFeatures } from '@/lib/features';
import { useTranslations } from '@/lib/i18n';

/**
 * Public tenant-facing shell (SLO-29): branded header + footer, mobile-first,
 * dark/light. The tenant's primary colour overrides the `--primary` design token
 * for the whole subtree, so CTAs/accents pick up the brand. Used by the public
 * homepage and the suspended status page.
 */
export default function PublicLayout({ children }: PropsWithChildren) {
    const t = useTranslations();
    const feature = useFeatures();
    const { tenant, auth } = usePage().props;

    const brandStyle = tenant
        ? ({ ['--primary']: tenant.primary_color } as CSSProperties)
        : undefined;

    // A guest is offered login; a signed-in customer (never staff — they belong
    // in the admin panel) gets their members-area links (SLO-33/SLO-96/SLO-98).
    // The waitlist/quote sections are feature-gated, so their links only appear
    // when the tenant has the feature on — a customer never sees a link that 403s.
    const accountLinks =
        auth.user && !auth.user.is_staff
            ? [
                  { href: '/my/bookings', label: t('tenant.nav.my_bookings') },
                  ...(feature('feature_waitlist')
                      ? [
                            {
                                href: '/my/waitlist',
                                label: t('tenant.nav.my_waitlist'),
                            },
                        ]
                      : []),
                  ...(feature('feature_quote_request')
                      ? [
                            {
                                href: '/my/quotes',
                                label: t('tenant.nav.my_quotes'),
                            },
                        ]
                      : []),
                  ...(feature('feature_online_payment')
                      ? [
                            {
                                href: '/my/payments',
                                label: t('tenant.nav.my_payments'),
                            },
                        ]
                      : []),
                  ...(feature('feature_invoicing')
                      ? [
                            {
                                href: '/my/invoices',
                                label: t('tenant.nav.my_invoices'),
                            },
                        ]
                      : []),
                  { href: '/my/profile', label: t('tenant.nav.my_profile') },
              ]
            : auth.user
              ? []
              : [{ href: '/login', label: t('tenant.nav.login') }];

    return (
        <div
            style={brandStyle}
            className="flex min-h-screen flex-col bg-background text-foreground"
        >
            <a
                href="#content"
                className="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-md focus:bg-primary focus:px-4 focus:py-2 focus:text-primary-foreground"
            >
                {t('admin.topbar.skip_to_content')}
            </a>

            <ImpersonationBanner />

            <header className="border-b border-border">
                <div className="mx-auto flex w-full max-w-5xl items-center justify-between gap-4 px-4 py-4 sm:px-6">
                    <div className="flex items-center gap-3">
                        {tenant?.logo_url ? (
                            <img
                                src={tenant.logo_url}
                                alt={tenant.name}
                                className="h-9 w-9 rounded-lg object-cover"
                            />
                        ) : (
                            <span className="flex size-9 items-center justify-center rounded-lg bg-primary text-sm font-semibold text-primary-foreground">
                                {(tenant?.name ?? 'S').charAt(0).toUpperCase()}
                            </span>
                        )}
                        <span className="text-base font-semibold tracking-tight">
                            {tenant?.name}
                        </span>
                    </div>

                    <div className="flex items-center gap-2">
                        {accountLinks.map((link) => (
                            <Button
                                key={link.href}
                                asChild
                                variant="ghost"
                                size="sm"
                            >
                                <Link href={link.href}>{link.label}</Link>
                            </Button>
                        ))}
                        <ThemeToggle />
                    </div>
                </div>
            </header>

            <motion.main
                id="content"
                initial={{ opacity: 0, y: 8 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ duration: 0.3, ease: 'easeOut' }}
                className="flex-1"
            >
                {children}
            </motion.main>

            <footer className="border-t border-border">
                <div className="mx-auto flex w-full max-w-5xl flex-col items-center justify-between gap-2 px-4 py-6 text-sm text-muted-foreground sm:flex-row sm:px-6">
                    <span>
                        © {tenant?.name}
                    </span>
                    <span className="text-xs">
                        {t('tenant.home.powered_by')}
                    </span>
                </div>
            </footer>
        </div>
    );
}
