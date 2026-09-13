import { Link, usePage } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { PropsWithChildren } from 'react';

import BrandLockup from '@/components/BrandLockup';
import { CookieConsent, CookieSettingsLink } from '@/components/CookieConsent';
import { highlightButton } from '@/components/landing/primitives';
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

    // A hairline and a soft shadow once the page has moved (SLO-229). Passive listener: this fires on every scroll frame, and the
    // browser must not have to wait to find out whether we cancel the scroll.
    const [scrolled, setScrolled] = useState(false);

    useEffect(() => {
        const onScroll = () => setScrolled(window.scrollY > 8);

        onScroll();
        window.addEventListener('scroll', onScroll, { passive: true });

        return () => window.removeEventListener('scroll', onScroll);
    }, []);

    const navLinks = [
        ...(homeLink ? [{ href: '/', label: t('welcome.nav.back_home') }] : []),
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
        <div className="theme-light theme-landing flex min-h-screen flex-col bg-white text-foreground">
            {/*
                The "Slot4u Landing" header (SLO-229): white, logo left, section
                links, then login and the yellow pill. `theme-landing` scopes the
                design's navy, yellow and Nunito to this shell only — the admin
                and the tenant pages keep the identity tokens (Daniel,
                2026-09-13).
            */}
            <header
                className={`sticky top-0 z-40 bg-white transition-shadow duration-200 ${
                    scrolled
                        ? 'shadow-[0_1px_0_var(--line),0_8px_24px_rgba(15,37,71,.06)]'
                        : ''
                }`}
            >
                <div className="mx-auto flex w-full max-w-[1440px] items-center gap-8 px-4 py-3.5 sm:px-8 lg:px-14">
                    <Link
                        href="/"
                        aria-label={BRAND_NAME}
                        className="text-navy"
                    >
                        <BrandLockup size={36} />
                    </Link>

                    {/*
                        Hidden below `md` rather than folded into a hamburger:
                        anchors to sections of THIS page do not earn a menu, and
                        the actions beside them stay reachable at every width.
                    */}
                    <nav className="ml-auto hidden items-center gap-6 text-sm font-semibold whitespace-nowrap text-ink-muted md:flex">
                        {navLinks.map((link) => (
                            <a
                                key={link.href}
                                href={link.href}
                                className="transition-colors hover:text-navy"
                            >
                                {link.label}
                            </a>
                        ))}
                    </nav>

                    {/*
                        No theme toggle here (SLO-208): this shell is pinned to
                        the light palette, so a switch would be a control that
                        visibly does nothing.
                    */}
                    <div className="ml-auto flex items-center gap-4 md:ml-10">
                        {auth.user === null || auth.user.is_demo_visitor ? (
                            <>
                                <a
                                    href="/login"
                                    className="inline-flex items-center gap-3 rounded-full text-sm font-bold text-navy focus-visible:ring-2 focus-visible:ring-brand focus-visible:ring-offset-2 focus-visible:outline-none"
                                >
                                    {/* The short word on a phone, where the
                                        pill beside it needs the room. */}
                                    <span className="sm:hidden">
                                        {t('welcome.login')}
                                    </span>
                                    <span className="hidden sm:inline">
                                        {t('welcome.nav_login')}
                                    </span>
                                    <span
                                        className="hidden size-8 place-items-center rounded-full border-2 border-navy sm:grid"
                                        aria-hidden
                                    >
                                        <ArrowRight className="size-4" />
                                    </span>
                                </a>
                                <a
                                    href="/register"
                                    className={`${highlightButton} rounded-full px-5 py-2.5 text-sm whitespace-nowrap`}
                                >
                                    {t('welcome.nav_signup')}
                                </a>
                            </>
                        ) : (
                            /*
                                A real customer, recognised across the shared
                                cookie: the way back into their own workspace,
                                which lives on their subdomain (SLO-215).
                            */
                            <a
                                href={auth.user.workspace_url ?? '/'}
                                className={`${highlightButton} rounded-full px-5 py-2.5 text-sm whitespace-nowrap`}
                            >
                                {t('welcome.workspace')}
                            </a>
                        )}
                    </div>
                </div>
            </header>

            <main className="flex-1">{children}</main>

            {/* Navy footer (SLO-229). Only links to pages that exist: the
                design's Blog, Karrier, Rólunk, Tudástár and social icons have
                nothing behind them yet. */}
            <footer className="bg-navy text-mist">
                <div className="mx-auto w-full max-w-[1440px] px-4 pt-14 pb-7 sm:px-8 lg:px-14">
                    <div className="grid gap-8 sm:grid-cols-2 lg:grid-cols-[1.3fr_1fr_1fr]">
                        <div>
                            <span className="text-white">
                                <BrandLockup size={38} />
                            </span>
                            <p className="mt-3.5 max-w-[240px] text-[13px] leading-normal">
                                {t('welcome.footer.tagline')}
                            </p>
                        </div>

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
                                {t('welcome.hero.cta_primary')}
                            </FooterLink>
                        </FooterColumn>

                        <FooterColumn title={t('welcome.footer.legal')}>
                            {/*
                                The platform's own documents, from the shared
                                `legal` prop (SLO-161) — the same versions a
                                company is asked to accept at sign-up.
                            */}
                            {documents.map((document) => (
                                <FooterLink
                                    key={document.id}
                                    href={document.href}
                                >
                                    {document.title}
                                </FooterLink>
                            ))}
                            <CookieSettingsLink className="text-left text-mist transition-colors hover:text-white" />
                        </FooterColumn>
                    </div>

                    <div className="mt-11 flex flex-wrap justify-between gap-4 border-t border-white/10 pt-5 text-xs">
                        <span>
                            {t('welcome.footer_rights', {
                                year: new Date().getFullYear(),
                            })}
                        </span>
                        <span className="font-bold text-white">
                            {t('welcome.footer_slogan')}
                        </span>
                    </div>
                </div>
            </footer>

            {auth.user === null && <CookieConsent />}
        </div>
    );
}

function FooterColumn({
    title,
    children,
}: PropsWithChildren<{ title: string }>) {
    return (
        <div className="grid content-start gap-2.5 text-sm">
            <p className="mb-1 font-bold text-white">{title}</p>
            {children}
        </div>
    );
}

function FooterLink({ href, children }: PropsWithChildren<{ href: string }>) {
    return (
        <a href={href} className="text-mist transition-colors hover:text-white">
            {children}
        </a>
    );
}
