import { Link, usePage } from '@inertiajs/react';
import {
    CalendarDays,
    Clock,
    Mail,
    MapPin,
    Menu,
    Phone,
    X,
} from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';

import { CookieSettingsLink } from '@/components/CookieConsent';
import { Illustration } from '@/components/landing/primitives';
import CalmIcon from '@/components/tenant-landing/CalmIcon';
import { CALM_ART } from '@/components/tenant-landing/calmArt';
import type { CalmArtSlot } from '@/components/tenant-landing/calmArt';
import { BRAND_NAME } from '@/lib/brand';
import { formatMoney } from '@/lib/format';
import { useTranslations } from '@/lib/i18n';
import { usePublicAccountLinks } from '@/lib/publicAccountLinks';
import type {
    PublicHomeCategory,
    PublicHomeProfile,
    PublicHomeService,
    PublicLanding,
} from '@/types';

/*
 * The calm landing template (SLO-238, docs/25) — drawn for a solo practice: a
 * therapist, a coach, a dietitian. Warm paper, deep sage, a serif for headings,
 * and nothing that hurries the visitor.
 *
 * What comes from where:
 *   - the practice's real data: services (with prices, durations, the approval
 *     badge), address, phone, email, opening hours;
 *   - the tenant's landing content (`tenants.landing`): tagline, highlights,
 *     "why us", the about block, testimonials, FAQ — shown as written;
 *   - the lang file: the template's own labels.
 * A section whose content is empty is not drawn, so a practice with no quotes
 * gets no empty testimonials heading.
 */

type Props = {
    profile: PublicHomeProfile;
    categories: PublicHomeCategory[];
    landing: PublicLanding;
};

/** How many services the landing shows before "all services". */
const SERVICE_PREVIEW = 4;

/** The soft glyph discs on the service cards, cycled in order. */
const SERVICE_DECOR = [
    { glyph: '☺', tint: 'bg-(--calm-blush)' },
    { glyph: '◐', tint: 'bg-(--calm-mint)' },
    { glyph: '◎', tint: 'bg-[#e4e9dc]' },
    { glyph: '▤', tint: 'bg-[#f6dcdc]' },
];

const sageButton =
    'inline-flex items-center justify-center gap-2.5 rounded-[14px] bg-(--calm-sage) font-semibold text-white transition-colors duration-200 hover:bg-(--calm-sage-deep) focus-visible:ring-2 focus-visible:ring-(--calm-sage) focus-visible:ring-offset-2 focus-visible:outline-none';

export default function CalmLanding({ profile, categories, landing }: Props) {
    const services = categories.flatMap((category) => category.services);

    return (
        <div className="theme-calm">
            <CalmHeader profile={profile} landing={landing} />
            <CalmHero profile={profile} landing={landing} />
            {services.length > 0 && <CalmServices services={services} />}
            {landing.why_items.length > 0 && <CalmWhy landing={landing} />}
            {landing.about.name !== null && <CalmAbout landing={landing} />}
            {landing.testimonials.length > 0 && (
                <CalmTestimonials landing={landing} />
            )}
            {landing.faq.length > 0 && <CalmFaq landing={landing} />}
            <CalmContact profile={profile} />
            <CalmFinalCta />
            <CalmFooter profile={profile} landing={landing} />
        </div>
    );
}

// --- Pieces ------------------------------------------------------------------

function Container({
    children,
    className = '',
}: {
    children: ReactNode;
    className?: string;
}) {
    return (
        <div
            className={`mx-auto w-full max-w-[1440px] px-5 sm:px-8 lg:px-16 ${className}`}
        >
            {children}
        </div>
    );
}

function Eyebrow({ children }: { children: ReactNode }) {
    return (
        <p className="text-[11px] font-bold tracking-[0.18em] text-(--calm-muted) uppercase sm:text-[13px]">
            {children}
        </p>
    );
}

function Bubble({
    children,
    tail = 'left',
    className = '',
}: {
    children: ReactNode;
    tail?: 'left' | 'right';
    className?: string;
}) {
    return (
        <p
            aria-hidden
            className={`pointer-events-none border border-(--calm-mint) bg-white px-4 py-2.5 font-hand text-[20px] leading-tight text-(--calm-sage) shadow-[0_6px_16px_rgba(47,93,74,.08)] sm:text-[22px] ${
                tail === 'left'
                    ? 'rounded-[18px_18px_18px_4px]'
                    : 'rounded-[18px_18px_4px_18px]'
            } ${className}`}
        >
            {children}
        </p>
    );
}

/** An illustration, or the soft shape that stands in until its file exists. */
function Art({
    slot,
    className,
    fallback,
    fit = 'object-contain',
    eager = false,
}: {
    slot: CalmArtSlot;
    className: string;
    fallback: ReactNode;
    /** `object-cover` for the photos, which fill their frame. */
    fit?: string;
    /** The hero picture is the largest paint: never lazy. */
    eager?: boolean;
}) {
    const image = CALM_ART[slot];

    return image ? (
        <Illustration
            image={image}
            className={`block ${className}`}
            imgClassName={`h-full w-full ${fit}`}
            eager={eager}
        />
    ) : (
        <div aria-hidden className={className}>
            {fallback}
        </div>
    );
}

function LeafMark({ size = 44 }: { size?: number }) {
    const mark = CALM_ART.mark;

    if (mark) {
        return (
            <span className="block flex-none" style={{ height: size }}>
                <Illustration
                    image={mark}
                    className="block h-full"
                    imgClassName="h-full w-auto"
                />
            </span>
        );
    }

    return (
        <span className="text-(--calm-sage)">
            <CalmIcon icon="leaf" size={size} />
        </span>
    );
}

function brand(profile: PublicHomeProfile, landing: PublicLanding) {
    return {
        title: landing.brand_title ?? profile.name,
        subtitle: landing.brand_title !== null ? landing.brand_subtitle : null,
    };
}

// --- Header ------------------------------------------------------------------

function CalmHeader({
    profile,
    landing,
}: {
    profile: PublicHomeProfile;
    landing: PublicLanding;
}) {
    const t = useTranslations();
    const accountLinks = usePublicAccountLinks();
    const [open, setOpen] = useState(false);
    const { title, subtitle } = brand(profile, landing);

    const nav = [
        { href: '#szolgaltatasok', label: t('tenant.calm.nav.services') },
        ...(landing.about.name !== null
            ? [{ href: '#rolunk', label: t('tenant.calm.nav.about') }]
            : []),
        ...(landing.faq.length > 0
            ? [{ href: '#gyik', label: t('tenant.calm.nav.faq') }]
            : []),
        { href: '#kapcsolat', label: t('tenant.calm.nav.contact') },
    ];

    return (
        <header className="border-b border-(--calm-rule) bg-(--calm-paper)">
            <Container className="flex items-center justify-between gap-6 py-3.5 xl:py-5">
                <a href="#top" className="flex items-center gap-2.5 xl:gap-3">
                    <span className="xl:hidden">
                        <LeafMark size={34} />
                    </span>
                    <span className="hidden xl:inline">
                        <LeafMark />
                    </span>
                    <span className="flex flex-col leading-[1.05]">
                        <span className="font-(family-name:--calm-serif) text-xl font-semibold text-(--calm-sage) xl:text-[26px]">
                            {title}
                        </span>
                        {subtitle !== null && (
                            <span className="text-[10px] font-medium text-(--calm-muted) xl:text-xs">
                                {subtitle}
                            </span>
                        )}
                    </span>
                </a>

                <nav className="hidden items-center gap-8 text-[15px] font-semibold xl:flex">
                    {nav.map((link) => (
                        <a
                            key={link.href}
                            href={link.href}
                            className="text-(--calm-text) transition-colors hover:text-(--calm-sage)"
                        >
                            {link.label}
                        </a>
                    ))}
                    {accountLinks.slice(0, 1).map((link) => (
                        <Link
                            key={link.href}
                            href={link.href}
                            className="text-(--calm-muted) transition-colors hover:text-(--calm-sage)"
                        >
                            {link.label}
                        </Link>
                    ))}
                </nav>

                <div className="flex items-center gap-2">
                    <Link
                        href="/book"
                        className={`${sageButton} h-11 px-3.5 text-[13px] xl:h-auto xl:px-5.5 xl:py-3.5 xl:text-[15px]`}
                    >
                        <CalendarDays
                            className="size-4 xl:size-[18px]"
                            aria-hidden
                        />
                        <span className="xl:hidden">
                            {t('tenant.calm.book_short')}
                        </span>
                        <span className="hidden xl:inline">
                            {t('tenant.calm.book')}
                        </span>
                    </Link>
                    <button
                        type="button"
                        onClick={() => setOpen((value) => !value)}
                        aria-expanded={open}
                        aria-label={t('tenant.calm.nav.menu')}
                        className="grid size-11 place-items-center rounded-xl border-[1.5px] border-(--calm-mint-line) bg-white text-(--calm-sage) xl:hidden"
                    >
                        {open ? (
                            <X className="size-5" aria-hidden />
                        ) : (
                            <Menu className="size-5" aria-hidden />
                        )}
                    </button>
                </div>
            </Container>

            {open && (
                <nav className="border-t border-(--calm-rule) bg-white px-4 py-2 xl:hidden">
                    {nav.map((link) => (
                        <a
                            key={link.href}
                            href={link.href}
                            onClick={() => setOpen(false)}
                            className="block border-b border-[#f0ebe0] px-1 py-3.5 text-base font-semibold text-(--calm-text) last:border-b-0"
                        >
                            {link.label}
                        </a>
                    ))}
                    {accountLinks.map((link) => (
                        <Link
                            key={link.href}
                            href={link.href}
                            className="block px-1 py-3.5 text-base font-semibold text-(--calm-muted)"
                        >
                            {link.label}
                        </Link>
                    ))}
                </nav>
            )}
        </header>
    );
}

// --- Hero ----------------------------------------------------------------------

function CalmHero({
    profile,
    landing,
}: {
    profile: PublicHomeProfile;
    landing: PublicLanding;
}) {
    const t = useTranslations();
    const { title, subtitle } = brand(profile, landing);
    const [firstBubble, secondBubble] = landing.bubbles;

    return (
        <section id="top" className="overflow-hidden">
            <div className="mx-auto grid w-full max-w-[1440px] items-center gap-6 pt-8 xl:grid-cols-[560px_minmax(0,1fr)] xl:gap-12 xl:pt-14 xl:pl-16">
                <div className="flex flex-col gap-4.5 px-5 sm:px-8 lg:px-16 xl:gap-6 xl:px-0 xl:pb-14">
                    {landing.tagline !== null && (
                        <p className="text-[11px] font-bold tracking-[0.16em] text-(--calm-text) uppercase lg:text-[13px] lg:tracking-[0.18em]">
                            {landing.tagline}
                        </p>
                    )}
                    <h1 className="text-[40px] leading-[1.1] font-semibold text-pretty text-(--calm-sage) lg:text-[60px] lg:leading-[1.08]">
                        {title}
                        {subtitle !== null && (
                            <>
                                <br />
                                <span className="text-[30px] font-normal lg:text-[50px]">
                                    {subtitle}
                                </span>
                            </>
                        )}
                    </h1>
                    {(landing.lead ?? profile.description) !== null && (
                        <p className="max-w-[500px] text-[17px] leading-relaxed text-pretty lg:text-[19px] lg:leading-[1.65]">
                            {landing.lead ?? profile.description}
                        </p>
                    )}

                    <div className="flex flex-col gap-2.5 sm:flex-row sm:flex-wrap sm:gap-3.5">
                        <Link
                            href="/book"
                            className={`${sageButton} h-[52px] px-6.5 text-base shadow-[0_8px_20px_rgba(47,93,74,.18)]`}
                        >
                            <CalendarDays className="size-[18px]" aria-hidden />
                            {t('tenant.calm.book_cta')}
                        </Link>
                        <a
                            href="#szolgaltatasok"
                            className="inline-flex h-[52px] items-center justify-center rounded-[14px] border-[1.5px] border-(--calm-mint-line) px-6 text-base font-semibold text-(--calm-sage) transition-colors hover:bg-[#f1ede3]"
                        >
                            {t('tenant.calm.services_cta')} →
                        </a>
                    </div>

                    {landing.highlights.length > 0 && (
                        <ul className="mt-1.5 flex justify-between gap-2 lg:mt-4 lg:justify-start lg:gap-10">
                            {landing.highlights.map((item) => (
                                <li
                                    key={item.label}
                                    className="flex flex-1 flex-col items-center gap-2 text-center text-xs leading-snug font-bold text-(--calm-sage) lg:w-[130px] lg:flex-none lg:items-start lg:gap-2.5 lg:text-left lg:text-[13px]"
                                >
                                    <CalmIcon icon={item.icon} size={30} />
                                    {item.label}
                                </li>
                            ))}
                        </ul>
                    )}
                </div>

                <div className="relative h-[300px] sm:h-[420px] xl:h-[560px]">
                    <div
                        aria-hidden
                        className="absolute inset-x-0 top-5 bottom-0 rounded-t-[150px] bg-(--calm-sand) xl:top-6 xl:right-0 xl:left-0 xl:rounded-[280px_0_0_280px]"
                    />
                    <Art
                        slot="hero"
                        className="absolute inset-x-0 top-10 bottom-0 xl:top-16"
                        fit="object-contain object-bottom"
                        eager
                        fallback={
                            <div className="absolute inset-0 grid place-items-center text-(--calm-mint-line)">
                                <CalmIcon icon="leaf" size={120} />
                            </div>
                        }
                    />
                    {firstBubble !== undefined && (
                        <Bubble className="absolute top-7 left-5 max-w-[240px] xl:top-11 xl:left-[120px]">
                            {firstBubble}{' '}
                            <span className="text-(--calm-rose)">♥</span>
                        </Bubble>
                    )}
                    {secondBubble !== undefined && (
                        <Bubble
                            tail="right"
                            className="absolute top-[220px] right-20 hidden max-w-[220px] xl:block"
                        >
                            {secondBubble}
                        </Bubble>
                    )}
                    {landing.motto !== null && (
                        <p
                            aria-hidden
                            className="absolute top-10 right-[90px] hidden h-[150px] w-[120px] place-items-center rounded-md border-[6px] border-[#e9dcc9] bg-(--calm-cream) p-2.5 text-center text-xs leading-snug font-semibold tracking-[0.06em] break-words hyphens-auto text-[#a8998a] uppercase min-[1400px]:right-[150px] xl:grid"
                        >
                            {landing.motto}
                        </p>
                    )}
                </div>
            </div>
        </section>
    );
}

// --- Services --------------------------------------------------------------------

function serviceCta(service: PublicHomeService): string {
    if (service.requires_approval) {
        return 'tenant.calm.cta_request';
    }

    switch (service.booking_mode) {
        case 'no_time_slot':
            return 'tenant.calm.cta_order';
        case 'quote_request':
            return 'tenant.calm.cta_quote';
        default:
            return 'tenant.calm.cta_book';
    }
}

function CalmServices({ services }: { services: PublicHomeService[] }) {
    const t = useTranslations();
    const shown = services.slice(0, SERVICE_PREVIEW);

    return (
        <section id="szolgaltatasok" className="scroll-mt-4">
            <Container className="flex flex-col gap-5 py-10 lg:gap-8 lg:pt-[72px]">
                <div className="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between lg:gap-6">
                    <div className="flex flex-col gap-3">
                        <Eyebrow>{t('tenant.calm.services_eyebrow')}</Eyebrow>
                        <h2 className="text-[32px] leading-[1.15] font-semibold text-(--calm-sage) lg:text-[42px]">
                            {t('tenant.calm.services_title')}
                        </h2>
                        <p className="hidden text-lg leading-relaxed lg:block">
                            {t('tenant.calm.services_lead')}
                        </p>
                    </div>
                    <Link
                        href="/book"
                        className="hidden border-b-[1.5px] border-(--calm-sage) pb-0.5 text-[15px] font-bold whitespace-nowrap text-(--calm-sage) lg:inline-block"
                    >
                        {t('tenant.calm.services_all')} →
                    </Link>
                </div>

                <ul className="grid gap-5 md:grid-cols-2 xl:grid-cols-4">
                    {shown.map((service, index) => {
                        const decor =
                            SERVICE_DECOR[index % SERVICE_DECOR.length];

                        return (
                            <li
                                key={service.id}
                                className="flex flex-col gap-3.5 rounded-[22px] border border-(--calm-mint) bg-white p-6 shadow-[0_6px_18px_rgba(47,93,74,.05)]"
                            >
                                <div className="flex items-start justify-between gap-3">
                                    <span
                                        aria-hidden
                                        className={`grid size-12 place-items-center rounded-full font-(family-name:--calm-serif) text-xl font-semibold text-(--calm-sage) lg:size-14 lg:text-[22px] ${decor.tint}`}
                                    >
                                        {decor.glyph}
                                    </span>
                                    {service.requires_approval && (
                                        <span className="rounded-full bg-(--calm-amber-soft) px-2.5 py-1.5 text-[11px] font-semibold tracking-[0.04em] text-(--calm-amber)">
                                            {t('tenant.calm.approval_badge')}
                                        </span>
                                    )}
                                </div>
                                <h3 className="text-xl leading-tight font-semibold text-(--calm-sage) lg:text-[21px]">
                                    {service.name}
                                </h3>
                                {service.description !== null && (
                                    <p className="flex-1 text-[15px] leading-relaxed text-pretty">
                                        {service.description}
                                    </p>
                                )}
                                <p className="flex items-center gap-3.5 text-lg font-bold text-(--calm-sage)">
                                    {formatMoney(
                                        service.price_minor,
                                        service.currency,
                                    )}
                                    <span className="inline-flex items-center gap-1.5 text-sm font-medium text-(--calm-muted)">
                                        <Clock
                                            className="size-[15px]"
                                            aria-hidden
                                        />
                                        {service.duration_minutes !== null
                                            ? t('tenant.calm.minutes', {
                                                  count: service.duration_minutes,
                                              })
                                            : t('tenant.calm.no_slot')}
                                    </span>
                                </p>
                                <Link
                                    href={`/book?service=${service.id}`}
                                    className={`${sageButton} min-h-12 w-full px-4 text-[15px]`}
                                >
                                    {t(serviceCta(service))}
                                </Link>
                            </li>
                        );
                    })}
                </ul>

                <Link
                    href="/book"
                    className="self-center text-[15px] font-bold text-(--calm-sage) lg:hidden"
                >
                    {t('tenant.calm.services_all')} →
                </Link>
            </Container>
        </section>
    );
}

// --- Why us --------------------------------------------------------------------------

function CalmWhy({ landing }: { landing: PublicLanding }) {
    const t = useTranslations();

    return (
        <section>
            <Container className="py-4 lg:py-8">
                <div className="grid items-center gap-8 overflow-hidden rounded-[28px] bg-(--calm-mint) px-6 py-8 lg:gap-10 lg:px-12 lg:py-10 xl:grid-cols-[400px_minmax(0,1fr)]">
                    <div className="relative h-[220px] lg:h-[300px]">
                        <Art
                            slot="why"
                            className="h-full w-[66%]"
                            fit="object-contain object-left-bottom"
                            fallback={
                                <div className="grid h-full w-full place-items-center rounded-[20px] bg-white/50 text-(--calm-sage)">
                                    <CalmIcon icon="heart" size={96} />
                                </div>
                            }
                        />
                        {landing.why_quote !== null && (
                            <Bubble className="absolute top-2 right-0 max-w-[210px] xl:-right-2">
                                {landing.why_quote}
                            </Bubble>
                        )}
                    </div>
                    <div className="flex flex-col gap-7 lg:gap-8">
                        <h2 className="text-[30px] leading-[1.15] font-semibold text-(--calm-sage) lg:text-[40px]">
                            {t('tenant.calm.why_title')}
                        </h2>
                        <ul className="grid grid-cols-2 gap-6 lg:grid-cols-4">
                            {landing.why_items.map((item) => (
                                <li
                                    key={item.title}
                                    className="flex flex-col items-center gap-3 text-center"
                                >
                                    <span className="text-(--calm-sage)">
                                        <CalmIcon icon={item.icon} size={40} />
                                    </span>
                                    <span className="text-[15px] font-bold text-(--calm-sage)">
                                        {item.title}
                                    </span>
                                    {item.text !== '' && (
                                        <span className="text-sm leading-normal">
                                            {item.text}
                                        </span>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </div>
                </div>
            </Container>
        </section>
    );
}

// --- About -------------------------------------------------------------------------------

function CalmAbout({ landing }: { landing: PublicLanding }) {
    const t = useTranslations();
    const { about } = landing;
    const initials = (about.name ?? '')
        .split(' ')
        .filter((part) => part.length > 0 && part !== 'dr.')
        .map((part) => part.charAt(0))
        .slice(0, 2)
        .join('');

    return (
        <section id="rolunk" className="scroll-mt-4">
            <Container className="grid items-center gap-8 py-10 lg:gap-12 lg:pt-14 lg:pb-8 xl:grid-cols-[minmax(0,1fr)_440px]">
                <div className="flex flex-col gap-6 sm:flex-row sm:items-start sm:gap-7">
                    <Art
                        slot="portrait"
                        className="size-[120px] flex-none overflow-hidden rounded-full lg:size-[140px]"
                        fit="object-cover"
                        fallback={
                            <span className="grid size-full place-items-center rounded-full bg-(--calm-mint) font-(family-name:--calm-serif) text-4xl font-semibold text-(--calm-sage)">
                                {initials}
                            </span>
                        }
                    />
                    <div className="flex flex-col gap-3.5">
                        <Eyebrow>{t('tenant.calm.about_eyebrow')}</Eyebrow>
                        <h2 className="text-[30px] leading-[1.15] font-semibold text-(--calm-sage) lg:text-4xl">
                            {about.name}
                            {about.title !== null && (
                                <span className="font-(family-name:--calm-serif) text-xl font-normal text-(--calm-muted) italic lg:text-2xl">
                                    {' '}
                                    · {about.title}
                                </span>
                            )}
                        </h2>
                        {about.bio !== null && (
                            <p className="text-base leading-[1.7] text-pretty lg:text-[17px]">
                                {about.bio}
                            </p>
                        )}
                        {about.chips.length > 0 && (
                            <ul className="flex flex-wrap gap-2.5">
                                {about.chips.map((chip) => (
                                    <li
                                        key={chip}
                                        className="rounded-full bg-(--calm-mint) px-3.5 py-2 text-[13px] font-semibold text-(--calm-sage)"
                                    >
                                        {chip}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                </div>
                <Art
                    slot="about"
                    className="hidden h-[320px] overflow-hidden rounded-[24px] xl:block"
                    fit="object-cover"
                    fallback={
                        <div className="grid h-full w-full place-items-center rounded-[24px] bg-(--calm-sand) text-(--calm-mint-line)">
                            <CalmIcon icon="leaf" size={96} />
                        </div>
                    }
                />
            </Container>
        </section>
    );
}

// --- Testimonials ---------------------------------------------------------------------------

function CalmTestimonials({ landing }: { landing: PublicLanding }) {
    const t = useTranslations();
    const [start, setStart] = useState(0);
    const reviews = landing.testimonials;
    const count = reviews.length;
    const visible = Math.min(3, count);
    const shown = Array.from(
        { length: visible },
        (_, i) => reviews[(start + i) % count],
    );
    const canPage = count > visible;

    return (
        <section>
            <Container className="flex flex-col gap-7 py-10">
                <div className="flex items-end justify-between gap-4">
                    <div className="flex flex-col gap-3">
                        <Eyebrow>
                            {t('tenant.calm.testimonials_eyebrow')}
                        </Eyebrow>
                        <h2 className="text-[30px] leading-[1.15] font-semibold text-(--calm-sage) lg:text-[40px]">
                            {t('tenant.calm.testimonials_title')}
                        </h2>
                    </div>
                    {canPage && (
                        <div className="flex gap-2.5">
                            {[
                                {
                                    label: 'tenant.calm.testimonials_prev',
                                    step: -1,
                                    arrow: '←',
                                },
                                {
                                    label: 'tenant.calm.testimonials_next',
                                    step: 1,
                                    arrow: '→',
                                },
                            ].map((button) => (
                                <button
                                    key={button.label}
                                    type="button"
                                    onClick={() =>
                                        setStart(
                                            (value) =>
                                                (value + button.step + count) %
                                                count,
                                        )
                                    }
                                    aria-label={t(button.label)}
                                    className="grid size-12 place-items-center rounded-full border-[1.5px] border-(--calm-mint-line) bg-white text-lg font-semibold text-(--calm-sage) transition-colors hover:bg-(--calm-mint)"
                                >
                                    {button.arrow}
                                </button>
                            ))}
                        </div>
                    )}
                </div>
                <ul className="grid gap-5 lg:grid-cols-3" aria-live="polite">
                    {shown.map((review, index) => (
                        <li
                            key={`${start}-${index}`}
                            className="flex flex-col gap-4.5 rounded-[22px] border border-(--calm-mint) bg-white p-7 shadow-[0_6px_18px_rgba(47,93,74,.05)]"
                        >
                            <p className="flex-1 text-[17px] leading-[1.65] text-pretty">
                                „{review.text}”
                            </p>
                            <p className="flex items-center gap-3.5">
                                <span
                                    aria-hidden
                                    className="text-lg tracking-[2px] text-(--calm-star)"
                                >
                                    ★★★★★
                                </span>
                                {review.name !== '' && (
                                    <span className="text-sm font-bold text-(--calm-sage)">
                                        {review.name}
                                    </span>
                                )}
                            </p>
                        </li>
                    ))}
                </ul>
            </Container>
        </section>
    );
}

// --- FAQ ------------------------------------------------------------------------------------------

function CalmFaq({ landing }: { landing: PublicLanding }) {
    const t = useTranslations();
    const [open, setOpen] = useState<number | null>(0);

    return (
        <section id="gyik" className="scroll-mt-4">
            <Container className="grid gap-8 py-10 lg:grid-cols-[400px_minmax(0,1fr)] lg:gap-16">
                <div className="flex flex-col gap-3.5">
                    <Eyebrow>{t('tenant.calm.faq_eyebrow')}</Eyebrow>
                    <h2 className="text-[30px] leading-[1.15] font-semibold text-pretty text-(--calm-sage) lg:text-[40px]">
                        {t('tenant.calm.faq_title')}
                    </h2>
                    <p className="text-base leading-[1.65] lg:text-[17px]">
                        {t('tenant.calm.faq_lead')}
                    </p>
                </div>
                <div className="flex flex-col gap-3">
                    {landing.faq.map((item, index) => {
                        const isOpen = open === index;

                        return (
                            <div
                                key={item.q}
                                className="overflow-hidden rounded-[18px] border border-(--calm-mint) bg-white"
                            >
                                <button
                                    type="button"
                                    onClick={() =>
                                        setOpen(isOpen ? null : index)
                                    }
                                    aria-expanded={isOpen}
                                    className="flex min-h-14 w-full items-center justify-between gap-4 px-6 py-5 text-left text-base font-semibold text-(--calm-sage) lg:text-[17px]"
                                >
                                    {item.q}
                                    <span
                                        aria-hidden
                                        className="grid size-7 flex-none place-items-center rounded-full bg-(--calm-mint) text-lg leading-none"
                                    >
                                        {isOpen ? '−' : '+'}
                                    </span>
                                </button>
                                {/* Rendered either way and only hidden, so the answers are in
                                    the server HTML for anyone who reads the page without JS. */}
                                <p
                                    hidden={!isOpen}
                                    className="px-6 pb-5.5 text-base leading-[1.65] text-pretty"
                                >
                                    {item.a}
                                </p>
                            </div>
                        );
                    })}
                </div>
            </Container>
        </section>
    );
}

// --- Contact -----------------------------------------------------------------------------------------

function CalmContact({ profile }: { profile: PublicHomeProfile }) {
    const t = useTranslations();
    const address = profile.address
        ? [
              [profile.address.postal_code, profile.address.city]
                  .filter(Boolean)
                  .join(' '),
              profile.address.line,
          ]
              .filter(Boolean)
              .join(', ')
        : null;

    const lines = [
        address !== null && address !== ''
            ? { Icon: MapPin, content: address }
            : null,
        profile.phone !== null
            ? {
                  Icon: Phone,
                  content: <a href={`tel:${profile.phone}`}>{profile.phone}</a>,
              }
            : null,
        profile.email !== null
            ? {
                  Icon: Mail,
                  content: (
                      <a href={`mailto:${profile.email}`}>{profile.email}</a>
                  ),
              }
            : null,
        profile.opening_hours !== null
            ? { Icon: Clock, content: profile.opening_hours }
            : null,
    ].filter((line) => line !== null);

    return (
        <section id="kapcsolat" className="scroll-mt-4">
            <Container className="grid gap-4 pt-6 lg:grid-cols-[1.1fr_0.9fr_1fr] lg:gap-5 lg:pt-10">
                <div className="flex flex-col gap-4 rounded-[22px] bg-(--calm-mint) p-6 lg:rounded-[24px] lg:p-8">
                    <h3 className="mb-1 text-[22px] font-semibold text-(--calm-sage) lg:text-[26px]">
                        {t('tenant.calm.contact_title')}
                    </h3>
                    {lines.map(({ Icon, content }, index) => (
                        <p
                            key={index}
                            className="flex items-start gap-3.5 text-[15px] leading-snug font-medium lg:text-base"
                        >
                            <Icon
                                className="mt-0.5 size-5 flex-none text-(--calm-sage)"
                                strokeWidth={1.8}
                                aria-hidden
                            />
                            <span className="[&_a]:text-(--calm-text) [&_a:hover]:text-(--calm-sage)">
                                {content}
                            </span>
                        </p>
                    ))}
                </div>
                <Art
                    slot="contact"
                    className="h-[200px] overflow-hidden rounded-[22px] bg-(--calm-sand) p-4 lg:h-[260px] lg:self-center lg:rounded-[24px]"
                    fallback={
                        <div className="grid h-full min-h-[180px] w-full place-items-center rounded-[22px] bg-(--calm-sand) text-(--calm-mint-line) lg:min-h-[260px] lg:rounded-[24px]">
                            <CalmIcon icon="leaf" size={80} />
                        </div>
                    }
                />
                {profile.email !== null && (
                    <div className="relative flex flex-col justify-center gap-3 rounded-[22px] border border-(--calm-mint) bg-white p-6 lg:gap-4 lg:rounded-[24px] lg:p-8">
                        <Art
                            slot="leaves"
                            className="pointer-events-none absolute -right-4 -bottom-3 hidden w-[120px] xl:block"
                            fallback={null}
                        />
                        <h3 className="text-[22px] font-semibold text-(--calm-sage) lg:text-[26px]">
                            {t('tenant.calm.question_title')}
                        </h3>
                        <p className="text-[15px] leading-relaxed text-pretty lg:text-base">
                            {t('tenant.calm.question_lead')}
                        </p>
                        <a
                            href={`mailto:${profile.email}`}
                            className={`${sageButton} h-[50px] self-stretch px-5.5 text-[15px] lg:self-start`}
                        >
                            <Mail className="size-[18px]" aria-hidden />
                            {t('tenant.calm.question_cta')}
                        </a>
                    </div>
                )}
            </Container>
        </section>
    );
}

// --- Final CTA and footer ----------------------------------------------------------------------------------

function CalmFinalCta() {
    const t = useTranslations();

    return (
        <section>
            <Container className="py-6 lg:py-14">
                <div className="relative flex flex-col items-center gap-4 overflow-hidden rounded-[22px] bg-(--calm-sage) px-6 py-7 text-center lg:flex-row lg:justify-between lg:gap-8 lg:rounded-[28px] lg:px-16 lg:py-14 lg:text-left">
                    <div
                        aria-hidden
                        className="absolute -top-16 -right-16 size-[260px] rounded-full bg-white/[0.06]"
                    />
                    <div className="relative flex flex-col gap-2.5">
                        <h2 className="text-[26px] leading-tight font-semibold text-white lg:text-[40px]">
                            {t('tenant.calm.final_title')}
                        </h2>
                        <p className="hidden text-[17px] leading-relaxed text-(--calm-mint) lg:block">
                            {t('tenant.calm.final_lead')}
                        </p>
                    </div>
                    <Link
                        href="/book"
                        className="relative inline-flex h-[52px] w-full flex-none items-center justify-center gap-2.5 rounded-[14px] bg-white px-8 text-base font-bold text-(--calm-sage) transition-colors hover:bg-(--calm-mint) lg:h-auto lg:w-auto lg:rounded-2xl lg:py-5 lg:text-lg"
                    >
                        <CalendarDays className="size-5" aria-hidden />
                        {t('tenant.calm.book_cta')}
                    </Link>
                </div>
            </Container>
        </section>
    );
}

function CalmFooter({
    profile,
    landing,
}: {
    profile: PublicHomeProfile;
    landing: PublicLanding;
}) {
    const t = useTranslations();
    const { legal } = usePage().props;
    const { title, subtitle } = brand(profile, landing);

    return (
        <footer className="border-t border-(--calm-rule)">
            <Container className="flex flex-col items-center gap-3 py-5 text-[13px] font-medium lg:flex-row lg:justify-between lg:py-7 lg:text-sm">
                <p className="hidden items-center gap-2.5 lg:flex">
                    <LeafMark size={26} />
                    <span className="font-(family-name:--calm-serif) text-[17px] font-semibold text-(--calm-sage)">
                        {title}
                    </span>
                    {subtitle !== null && <span>{subtitle}</span>}
                </p>
                <nav className="flex flex-wrap justify-center gap-5 lg:gap-7">
                    {(legal?.documents ?? []).map((document) => (
                        <a
                            key={document.id}
                            href={document.href}
                            className="text-(--calm-text) hover:text-(--calm-sage)"
                        >
                            {document.title}
                        </a>
                    ))}
                    <a
                        href="#kapcsolat"
                        className="text-(--calm-text) hover:text-(--calm-sage)"
                    >
                        {t('tenant.calm.nav.contact')}
                    </a>
                    <CookieSettingsLink className="text-(--calm-text) hover:text-(--calm-sage)" />
                </nav>
                <p className="text-(--calm-muted)">
                    {t('tenant.calm.powered_by')}{' '}
                    <strong className="text-(--calm-sage)">{BRAND_NAME}</strong>
                </p>
            </Container>
        </footer>
    );
}
