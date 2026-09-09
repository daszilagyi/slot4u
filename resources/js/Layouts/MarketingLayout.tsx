import { Link, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import type { PropsWithChildren } from 'react';

import BrandLockup from '@/components/BrandLockup';
import { CookieConsent, CookieSettingsLink } from '@/components/CookieConsent';
import { Button } from '@/components/ui/button';
import { BRAND_NAME } from '@/lib/brand';
import { useTranslations } from '@/lib/i18n';

type Props = {
    /**
     * Put "back to the home page" in the nav — what a vertical landing needs and
     * the home page cannot have (SLO-198, docs/22 §4 row 0).
     *
     * ⚠️ The logo is not that link on these pages. It goes home too, but a
     * visitor who arrived from an ad has never seen the home page and does not
     * read a logo as a way to somewhere else.
     */
    homeLink?: boolean;
};

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
export default function MarketingLayout({
    children,
    homeLink = false,
}: PropsWithChildren<Props>) {
    const t = useTranslations();
    const { auth, legal } = usePage().props;
    const documents = legal?.documents ?? [];

    // Transparent over the navy hero, solid once the page has moved (docs/21 §2
    // row 0). Passive listener: this fires on every scroll frame, and the
    // browser must not have to wait to find out whether we cancel the scroll.
    const [scrolled, setScrolled] = useState(false);

    useEffect(() => {
        const onScroll = () => setScrolled(window.scrollY > 8);

        onScroll();
        window.addEventListener('scroll', onScroll, { passive: true });

        return () => window.removeEventListener('scroll', onScroll);
    }, []);

    const navLinks = [
        ...(homeLink
            ? [{ href: '/', label: t('welcome.nav.back_home') }]
            : []),
        { href: '#funkciok', label: t('welcome.nav.features') },
        { href: '#arazas', label: t('welcome.nav.pricing') },
        // Points at the live demo section rather than straight out to a tenant
        // (SLO-192): from there the visitor picks which business to look at, and
        // the page they came for is still behind them.
        { href: '#demo', label: t('welcome.nav.demo') },
    ];

    return (
        // ⚠️ `theme-light` pins the marketing shell to the light palette
        // (SLO-208). The page is designed light with two navy bands (docs/21
        // §2) — there is no dark version of it — but `.dark` is the default on
        // <html> and leaves the brand tokens at their light values, which put
        // near-black ink on a near-black card. Scoping the palette here rather
        // than stripping `.dark` from <html> keeps the app's own dark mode, and
        // survives an Inertia navigation without a flash of the wrong theme.
        <div className="theme-light flex min-h-screen flex-col bg-background text-foreground">
            <header
                className={`sticky top-0 z-40 transition-colors duration-200 ${
                    scrolled
                        ? 'border-b border-line bg-canvas/95 backdrop-blur'
                        : 'border-b border-transparent'
                }`}
            >
                <div className="mx-auto flex w-full max-w-5xl items-center justify-between gap-4 px-4 py-4 sm:px-6">
                    <Link href="/" aria-label={BRAND_NAME}>
                        <BrandLockup size={36} />
                    </Link>

                    {/*
                        Hidden below `sm` rather than folded into a hamburger:
                        two anchors to sections of THIS page do not earn a menu,
                        and the actions beside them stay reachable at every width.
                        A drawer arrives with the sections that need one.
                    */}
                    <nav className="hidden items-center gap-6 text-sm sm:flex">
                        {navLinks.map((link) => (
                            <a
                                key={link.href}
                                href={link.href}
                                className="text-ink-muted transition-colors hover:text-foreground"
                            >
                                {link.label}
                            </a>
                        ))}
                    </nav>

                    {/*
                        No theme toggle here (SLO-208): this shell is pinned to
                        the light palette, so a switch would be a control that
                        visibly does nothing. The app's own surfaces keep theirs.
                    */}
                    <div className="flex items-center gap-2">
                        {auth.user === null ? (
                            <>
                                <Button asChild variant="ghost" size="sm">
                                    <a href="/login">{t('welcome.login')}</a>
                                </Button>
                                {/*
                                    ⚠️ Deliberately NOT the yellow. docs/21 allows
                                    one highlight CTA per screen, and on the home
                                    page that is the hero's primary button — the
                                    one a visitor is actually looking at. A yellow
                                    header button would compete with it on every
                                    scroll position, and the rule would be a rule
                                    nobody could point at.
                                */}
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

            {/*
                Navy, closing the page the way the hero opens it (docs/21 §2 row
                11) — with the ice hairline on top that separates it from the
                canvas above without a hard border.
            */}
            <footer className="border-t border-ice/20 bg-navy text-canvas">
                <div className="mx-auto w-full max-w-5xl px-4 py-12 sm:px-6">
                    <div className="flex flex-col gap-8 sm:flex-row sm:justify-between">
                        <div className="max-w-xs">
                            <BrandLockup size={28} />
                            <p className="mt-3 text-sm text-canvas/70">
                                {t('welcome.footer.tagline')}
                            </p>
                        </div>

                        <div className="flex flex-col gap-8 text-sm sm:flex-row sm:gap-12">
                            <FooterColumn title={t('welcome.footer.product')}>
                                <FooterLink href="#funkciok">
                                    {t('welcome.nav.features')}
                                </FooterLink>
                                <FooterLink href="#arazas">
                                    {t('welcome.nav.pricing')}
                                </FooterLink>
                                <FooterLink href="#demo">
                                    {t('welcome.nav.demo')}
                                </FooterLink>
                                <FooterLink href="/register">
                                    {t('welcome.cta_primary')}
                                </FooterLink>
                            </FooterColumn>

                            <FooterColumn title={t('welcome.footer.legal')}>
                                {/*
                                    The platform's own documents, from the shared
                                    `legal` prop (SLO-161) — the same versions a
                                    company is asked to accept at sign-up, so they
                                    can be read before rather than during.
                                */}
                                {documents.map((document) => (
                                    <FooterLink
                                        key={document.id}
                                        href={document.href}
                                    >
                                        {document.title}
                                    </FooterLink>
                                ))}
                                <CookieSettingsLink />
                            </FooterColumn>
                        </div>
                    </div>

                    <p className="mt-10 border-t border-canvas/10 pt-6 text-xs text-canvas/60">
                        {t('welcome.footer_rights', {
                            year: new Date().getFullYear(),
                        })}
                    </p>
                </div>
            </footer>

            {auth.user === null && <CookieConsent />}
        </div>
    );
}

function FooterColumn({ title, children }: PropsWithChildren<{ title: string }>) {
    return (
        <div className="flex flex-col gap-3">
            <p className="text-xs font-semibold tracking-[0.12em] text-canvas/50 uppercase">
                {title}
            </p>
            {children}
        </div>
    );
}

function FooterLink({ href, children }: PropsWithChildren<{ href: string }>) {
    return (
        <a
            href={href}
            className="text-canvas/80 transition-colors hover:text-canvas"
        >
            {children}
        </a>
    );
}
