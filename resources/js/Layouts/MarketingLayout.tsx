import { Link, usePage } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';

import BrandLockup from '@/components/BrandLockup';
import { CookieConsent, CookieSettingsLink } from '@/components/CookieConsent';
import ThemeToggle from '@/components/ThemeToggle';
import { Button } from '@/components/ui/button';
import { BRAND_NAME, PLATFORM_ACCENT_STYLE } from '@/lib/brand';
import { useTranslations } from '@/lib/i18n';

/**
 * The shell for the central slot4u.hu marketing pages (SLO-50).
 *
 * Its own layout rather than AppLayout: that one centres a single block in the
 * viewport, which is right for a login card and wrong for a page you scroll.
 * AppLayout also carries the superadmin panel, so widening it for marketing
 * would change a screen nobody asked to change.
 *
 * The footer links the platform's own legal documents from the shared `legal`
 * prop (SLO-161) — the same versions a company is asked to accept at sign-up, so
 * they can be read before rather than during.
 */
export default function MarketingLayout({ children }: PropsWithChildren) {
    const t = useTranslations();
    const { auth, legal } = usePage().props;
    const documents = legal?.documents ?? [];

    return (
        <div
            style={PLATFORM_ACCENT_STYLE}
            className="flex min-h-screen flex-col bg-background text-foreground"
        >
            <header className="border-b border-border">
                <div className="mx-auto flex w-full max-w-5xl items-center justify-between gap-4 px-4 py-4 sm:px-6">
                    <Link href="/" aria-label={BRAND_NAME}>
                        <BrandLockup size={36} />
                    </Link>

                    <div className="flex items-center gap-2">
                        <ThemeToggle />
                        {auth.user === null ? (
                            <>
                                <Button asChild variant="ghost" size="sm">
                                    <a href="/login">{t('welcome.login')}</a>
                                </Button>
                                <Button asChild size="sm">
                                    <a href="/register">
                                        {t('welcome.cta_primary')}
                                    </a>
                                </Button>
                            </>
                        ) : (
                            <Button asChild size="sm">
                                <a href="/">{t('welcome.login')}</a>
                            </Button>
                        )}
                    </div>
                </div>
            </header>

            <main className="flex-1">{children}</main>

            <footer className="border-t border-border">
                <div className="mx-auto flex w-full max-w-5xl flex-col gap-3 px-4 py-8 text-sm text-muted-foreground sm:flex-row sm:items-center sm:justify-between sm:px-6">
                    <span className="flex items-center gap-2">
                        <BrandLockup size={20} markOnly />
                        {t('welcome.footer_rights', {
                            year: new Date().getFullYear(),
                        })}
                    </span>
                    <div className="flex flex-wrap items-center gap-x-4 gap-y-2 text-xs">
                        {documents.map((document) => (
                            <a
                                key={document.id}
                                href={document.href}
                                className="underline underline-offset-2 hover:text-foreground"
                            >
                                {document.title}
                            </a>
                        ))}
                        <CookieSettingsLink />
                    </div>
                </div>
            </footer>

            {auth.user === null && <CookieConsent />}
        </div>
    );
}
