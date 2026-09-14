import { Link, usePage } from '@inertiajs/react';
import {
    AlarmClock,
    ArrowDown,
    ArrowRight,
    CalendarDays,
    CalendarSearch,
    Check,
    CircleCheck,
    Clock,
    Flower2,
    Footprints,
    Hand,
    Heart,
    Leaf,
    MapPin,
    Menu,
    Phone,
    Scissors,
    ShieldCheck,
    Sparkles,
    Users,
    Wallet,
    X,
    Zap,
} from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';

import { CookieSettingsLink } from '@/components/CookieConsent';
import type { LandingImage } from '@/components/landing/landingArt';
import { Illustration } from '@/components/landing/primitives';
import { GLAM_FIXED, glamPhoto } from '@/components/tenant-landing/glamArt';
import { BRAND_NAME } from '@/lib/brand';
import { formatMoney } from '@/lib/format';
import { useTranslations } from '@/lib/i18n';
import { usePublicAccountLinks } from '@/lib/publicAccountLinks';
import type {
    GlamLandingData,
    LandingIcon,
    PublicHomeCategory,
    PublicHomeProfile,
    PublicHomeService,
    PublicLanding,
} from '@/types';

/*
 * The glam landing template (SLO-241, docs/26) — drawn for a salon with a team:
 * hair, nails, beauty. A dark room, pink neon, Playfair for headings, and the
 * booking one tap away from every card.
 *
 * What comes from where:
 *   - the salon's real data: categories, services (price, duration), the team
 *     (name, title), address, phone, opening hours, and the next free times;
 *   - the tenant's landing content (`tenants.landing`): headline, neon signs,
 *     sticker, the photo and line of copy each category, featured service and
 *     team member gets — matched to the real records by name;
 *   - the lang file: the template's own labels.
 * A section with nothing real behind it is not drawn.
 */

type Props = {
    profile: PublicHomeProfile;
    categories: PublicHomeCategory[];
    landing: PublicLanding;
    glam: GlamLandingData;
};

/** How many service cards the "popular" row shows. */
const POPULAR_COUNT = 4;

const pinkButton =
    'inline-flex items-center justify-center gap-2 rounded-full bg-(--glam-pink) font-semibold text-(--glam-on-pink) transition-colors duration-200 hover:bg-(--glam-pink-hover) focus-visible:ring-2 focus-visible:ring-(--glam-pink) focus-visible:ring-offset-2 focus-visible:ring-offset-(--glam-ink) focus-visible:outline-none';

const textLink =
    'font-medium text-(--glam-pink) underline decoration-(--glam-pink)/60 underline-offset-4 transition-colors hover:text-(--glam-pink-hover)';

const card =
    'overflow-hidden rounded-[20px] border border-(--glam-line) bg-(--glam-surface)';

export default function GlamLanding({
    profile,
    categories,
    landing,
    glam,
}: Props) {
    const services = categories.flatMap((category) => category.services);
    const popular = popularServices(services, landing);

    return (
        <div className="theme-glam overflow-hidden">
            <GlamHeader profile={profile} landing={landing} />
            <GlamHero landing={landing} />
            {categories.length > 0 && (
                <GlamCategories categories={categories} landing={landing} />
            )}
            {popular.length > 0 && <GlamPopular popular={popular} />}
            {glam.team.length > 0 && <GlamTeam team={glam.team} />}
            {glam.quick !== null && <GlamQuick quick={glam.quick} />}
            <GlamSteps />
            <GlamContact profile={profile} landing={landing} />
            <GlamFinalCta />
            <GlamFooter profile={profile} landing={landing} />
        </div>
    );
}

// --- Data --------------------------------------------------------------------

type PopularCard = {
    service: PublicHomeService;
    badge: string | null;
    description: string | null;
    photo: LandingImage | null;
};

/**
 * The featured services the content names, in its order, matched to the real
 * catalogue — or, with nothing named, the first few services there are.
 */
function popularServices(
    services: PublicHomeService[],
    landing: PublicLanding,
): PopularCard[] {
    const byName = new Map(services.map((service) => [service.name, service]));

    const featured = landing.featured
        .map((item): PopularCard | null => {
            const service = byName.get(item.name);

            return service
                ? {
                      service,
                      badge: item.badge,
                      description: service.description ?? item.description,
                      photo: glamPhoto(item.photo),
                  }
                : null;
        })
        .filter((item) => item !== null);

    if (featured.length > 0) {
        return featured.slice(0, POPULAR_COUNT);
    }

    return services.slice(0, POPULAR_COUNT).map((service) => ({
        service,
        badge: null,
        description: service.description,
        photo: null,
    }));
}

function brand(profile: PublicHomeProfile, landing: PublicLanding) {
    return {
        title: landing.brand_title ?? profile.name,
        subtitle: landing.brand_title !== null ? landing.brand_subtitle : null,
    };
}

function addressLine(profile: PublicHomeProfile): string | null {
    const address = profile.address;

    if (!address) {
        return null;
    }

    const line = [
        [address.postal_code, address.city].filter(Boolean).join(' '),
        address.line,
    ]
        .filter(Boolean)
        .join(', ');

    return line === '' ? null : line;
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
            className={`mx-auto w-full max-w-[1440px] px-5 sm:px-8 lg:px-16 xl:px-24 ${className}`}
        >
            {children}
        </div>
    );
}

function GlamIcon({
    icon,
    className = 'size-4',
}: {
    icon: LandingIcon;
    className?: string;
}) {
    const Icon = {
        scissors: Scissors,
        flower: Flower2,
        sparkles: Sparkles,
        hand: Hand,
        foot: Footprints,
        heart: Heart,
        calendar: CalendarDays,
        people: Users,
        shield: ShieldCheck,
        leaf: Leaf,
    }[icon];

    return <Icon className={className} strokeWidth={1.8} aria-hidden />;
}

/** A photo, or the dark gradient that stands in when the key names none. */
function Photo({
    image,
    className = '',
}: {
    image: LandingImage | null;
    className?: string;
}) {
    return image ? (
        <Illustration
            image={image}
            className={`block ${className}`}
            imgClassName="h-full w-full object-cover"
        />
    ) : (
        <div
            aria-hidden
            className={`bg-[radial-gradient(ellipse_at_70%_30%,rgb(243_184_198/.22),transparent_60%),linear-gradient(160deg,#241722,#120f16)] ${className}`}
        />
    );
}

function FlowerMark({ size = 44 }: { size?: number }) {
    return (
        <span className="block flex-none" style={{ height: size, width: size }}>
            <Illustration
                image={GLAM_FIXED.mark}
                className="block h-full w-full"
                imgClassName="h-full w-full object-contain"
            />
        </span>
    );
}

function Brand({
    profile,
    landing,
    size = 'lg',
}: {
    profile: PublicHomeProfile;
    landing: PublicLanding;
    size?: 'lg' | 'sm';
}) {
    const { title, subtitle } = brand(profile, landing);

    return (
        <span className="flex items-center gap-2.5 xl:gap-3">
            <FlowerMark size={size === 'lg' ? 40 : 32} />
            <span className="flex flex-col leading-none">
                <span
                    className={`font-(family-name:--glam-serif) text-(--glam-pink) ${
                        size === 'lg' ? 'text-2xl xl:text-[28px]' : 'text-xl'
                    }`}
                >
                    {title}
                </span>
                {subtitle !== null && (
                    <span className="mt-1 text-[9px] tracking-[0.32em] text-(--glam-muted) uppercase xl:text-[10px]">
                        {subtitle}
                    </span>
                )}
            </span>
        </span>
    );
}

// --- Header ------------------------------------------------------------------

function GlamHeader({
    profile,
    landing,
}: {
    profile: PublicHomeProfile;
    landing: PublicLanding;
}) {
    const t = useTranslations();
    const accountLinks = usePublicAccountLinks();
    const [open, setOpen] = useState(false);

    const nav = [
        { href: '#szolgaltatasok', label: t('tenant.glam.nav.services') },
        { href: '#szakemberek', label: t('tenant.glam.nav.team') },
        { href: '#rolunk', label: t('tenant.glam.nav.about') },
        { href: '#kapcsolat', label: t('tenant.glam.nav.contact') },
    ];

    return (
        <header className="relative z-20">
            <Container className="flex items-center justify-between gap-6 py-4 xl:py-6">
                <a href="#top" aria-label={profile.name}>
                    <Brand profile={profile} landing={landing} />
                </a>

                <nav className="hidden items-center gap-9 text-[15px] font-medium xl:flex">
                    {nav.map((link) => (
                        <a
                            key={link.href}
                            href={link.href}
                            className="text-(--glam-text) transition-colors hover:text-(--glam-pink)"
                        >
                            {link.label}
                        </a>
                    ))}
                    {accountLinks.slice(0, 1).map((link) => (
                        <Link
                            key={link.href}
                            href={link.href}
                            className="text-(--glam-muted) transition-colors hover:text-(--glam-pink)"
                        >
                            {link.label}
                        </Link>
                    ))}
                </nav>

                <div className="flex items-center gap-2">
                    <Link
                        href="/book"
                        className="inline-flex h-11 items-center rounded-full border border-(--glam-pink) bg-(--glam-pink)/[0.06] px-4 text-sm font-semibold text-(--glam-text) transition-colors hover:bg-(--glam-pink)/[0.16] xl:px-6"
                    >
                        <span className="xl:hidden">
                            {t('tenant.glam.book_short')}
                        </span>
                        <span className="hidden xl:inline">
                            {t('tenant.glam.book_cta')}
                        </span>
                    </Link>
                    <button
                        type="button"
                        onClick={() => setOpen((value) => !value)}
                        aria-expanded={open}
                        aria-label={t('tenant.glam.nav.menu')}
                        className="grid size-11 place-items-center rounded-full border border-(--glam-line) bg-(--glam-surface) text-(--glam-pink) xl:hidden"
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
                <nav className="border-y border-(--glam-line) bg-(--glam-surface-2) px-5 py-2 sm:px-8 xl:hidden">
                    {[...nav, ...accountLinks].map((link) => (
                        <a
                            key={link.href}
                            href={link.href}
                            onClick={() => setOpen(false)}
                            className="block border-b border-(--glam-line) py-3.5 text-base font-medium text-(--glam-text) last:border-b-0"
                        >
                            {link.label}
                        </a>
                    ))}
                </nav>
            )}
        </header>
    );
}

// --- Hero --------------------------------------------------------------------

function GlamHero({ landing }: { landing: PublicLanding }) {
    const t = useTranslations();
    const [accent, ...rest] = landing.headline;
    const [neon] = landing.neon;
    const [sticker] = landing.bubbles;

    return (
        <section id="top" className="relative">
            <div
                aria-hidden
                className="pointer-events-none absolute -top-32 right-0 size-[520px] rounded-full bg-[radial-gradient(circle,rgb(243_184_198/.22),transparent_65%)] xl:size-[820px]"
            />
            <Container className="relative grid items-center gap-10 pt-6 pb-12 xl:grid-cols-[minmax(0,560px)_minmax(0,1fr)] xl:pt-9 xl:pb-14">
                <div className="flex flex-col">
                    {landing.tagline !== null && (
                        <p className="mb-4 text-[11px] font-semibold tracking-[0.28em] text-(--glam-pink) uppercase xl:mb-6 xl:text-xs">
                            {landing.tagline}
                        </p>
                    )}
                    {accent !== undefined && (
                        <h1 className="mb-6 text-[46px] leading-[1.04] text-balance sm:text-[60px] xl:mb-7 xl:text-[80px] xl:leading-[1.02]">
                            <span className="block text-(--glam-pink)">
                                {accent}
                            </span>
                            {rest.map((line) => (
                                <span key={line} className="block">
                                    {line}
                                </span>
                            ))}
                        </h1>
                    )}
                    {landing.lead !== null && (
                        <p className="mb-8 max-w-[440px] text-[17px] leading-relaxed text-pretty text-(--glam-body) xl:text-lg">
                            {landing.lead}
                        </p>
                    )}
                    <div className="mb-9 flex flex-col items-start gap-5 sm:flex-row sm:items-center sm:gap-7">
                        <Link
                            href="/book"
                            className={`${pinkButton} h-14 px-8 text-base shadow-[0_0_40px_rgb(243_184_198/.35)]`}
                        >
                            {t('tenant.glam.book_cta')}
                            <ArrowRight className="size-4" aria-hidden />
                        </Link>
                        <a
                            href="#szolgaltatasok"
                            className="inline-flex items-center gap-1.5 text-base font-medium text-(--glam-text) underline decoration-(--glam-pink)/60 underline-offset-[5px]"
                        >
                            {t('tenant.glam.services_link')}
                            <ArrowDown className="size-4" aria-hidden />
                        </a>
                    </div>
                    {landing.highlights.length > 0 && (
                        <ul className="flex flex-col gap-3 text-sm text-(--glam-body) sm:flex-row sm:flex-wrap sm:gap-7">
                            {landing.highlights.map((item) => (
                                <li
                                    key={item.label}
                                    className="flex items-center gap-2"
                                >
                                    <span className="grid size-[18px] place-items-center rounded-full border-[1.5px] border-(--glam-pink) text-(--glam-pink)">
                                        <Check
                                            className="size-2.5"
                                            strokeWidth={3}
                                            aria-hidden
                                        />
                                    </span>
                                    {item.label}
                                </li>
                            ))}
                        </ul>
                    )}
                </div>

                <div className="relative mx-auto h-[380px] w-full max-w-[640px] sm:h-[500px] xl:mx-0 xl:h-[600px] xl:max-w-none">
                    <div className="absolute inset-0 overflow-hidden rounded-[28px] border border-(--glam-line) bg-[linear-gradient(180deg,#1a1016_0%,#2a171f_60%,#12090e_100%)]">
                        <div
                            aria-hidden
                            className="absolute inset-0 bg-[radial-gradient(ellipse_at_70%_30%,rgb(243_184_198/.28),transparent_55%),radial-gradient(ellipse_at_20%_80%,rgb(180_80_110/.25),transparent_50%)]"
                        />
                        {neon !== undefined && (
                            <>
                                <div
                                    aria-hidden
                                    className="absolute top-6 right-5 h-[190px] w-[140px] rounded-[80px] border-[3px] border-(--glam-pink)/50 shadow-[0_0_30px_rgb(243_184_198/.5),inset_0_0_40px_rgb(243_184_198/.25)] sm:top-10 sm:right-8 sm:h-[260px] sm:w-[200px] xl:top-12 xl:right-9 xl:h-[300px] xl:w-[230px] xl:rounded-[120px]"
                                />
                                <p
                                    aria-hidden
                                    className="glam-neon absolute top-10 right-5 w-[140px] text-center font-hand text-[28px] leading-[1.05] whitespace-pre-line sm:top-16 sm:right-8 sm:w-[200px] sm:text-[40px] xl:top-[82px] xl:right-9 xl:w-[230px] xl:text-[46px]"
                                >
                                    {neon}
                                </p>
                            </>
                        )}
                        <Illustration
                            image={GLAM_FIXED.hero}
                            eager
                            className="absolute bottom-[-6px] left-[-4%] block w-[96%] drop-shadow-[0_20px_40px_rgba(0,0,0,.5)] xl:w-[640px] xl:max-w-[105%]"
                            imgClassName="h-auto w-full"
                        />
                    </div>
                    {sticker !== undefined && (
                        <p
                            aria-hidden
                            className="absolute top-4 -left-2 grid size-[104px] -rotate-12 place-items-center rounded-full bg-(--glam-pink) p-3 text-center font-hand text-[15px] leading-[1.05] whitespace-pre-line text-(--glam-on-pink) shadow-[0_12px_30px_rgba(0,0,0,.4)] sm:size-[132px] sm:text-[19px] xl:top-[70px] xl:-left-[58px]"
                        >
                            {sticker}
                        </p>
                    )}
                </div>
            </Container>
        </section>
    );
}

// --- Categories --------------------------------------------------------------

function GlamCategories({
    categories,
    landing,
}: {
    categories: PublicHomeCategory[];
    landing: PublicLanding;
}) {
    const t = useTranslations();
    const content = new Map(
        landing.categories.map((item) => [item.name, item]),
    );
    const named = categories.filter((category) => category.name !== null);

    if (named.length === 0) {
        return null;
    }

    return (
        <section id="szolgaltatasok" className="scroll-mt-4">
            <Container className="py-12 xl:pt-14 xl:pb-6">
                <SectionHeading
                    title={t('tenant.glam.categories_title')}
                    lead={t('tenant.glam.categories_lead')}
                />
                <ul
                    className={`grid gap-4 sm:grid-cols-2 xl:gap-5 ${
                        named.length === 3 ? 'lg:grid-cols-3' : 'xl:grid-cols-4'
                    }`}
                >
                    {named.map((category) => {
                        const item = content.get(category.name ?? '');
                        const first = category.services[0];

                        return (
                            <li key={category.id ?? category.name}>
                                <Link
                                    href={
                                        first
                                            ? `/book?service=${first.id}`
                                            : '/book'
                                    }
                                    className={`${card} group relative block h-[200px] xl:h-[220px]`}
                                >
                                    <Photo
                                        image={glamPhoto(item?.photo ?? null)}
                                        className="absolute inset-0 h-full w-full transition-transform duration-500 group-hover:scale-[1.03]"
                                    />
                                    <div
                                        aria-hidden
                                        className="absolute inset-0 bg-[linear-gradient(180deg,rgb(14_12_17/.15)_0%,rgb(14_12_17/.55)_50%,rgb(14_12_17/.92)_100%)]"
                                    />
                                    <span className="absolute top-5 left-5 grid size-9 place-items-center rounded-[10px] border border-(--glam-pink)/35 bg-(--glam-ink)/60 text-(--glam-pink)">
                                        <GlamIcon
                                            icon={item?.icon ?? 'sparkles'}
                                        />
                                    </span>
                                    <span className="absolute right-[70px] bottom-5 left-5">
                                        <span className="mb-1.5 block font-(family-name:--glam-serif) text-2xl text-(--glam-text)">
                                            {category.name}
                                        </span>
                                        <span className="block text-[13px] text-(--glam-body)">
                                            {item?.subtitle ||
                                                category.services
                                                    .slice(0, 3)
                                                    .map(
                                                        (service) =>
                                                            service.name,
                                                    )
                                                    .join(' · ')}
                                        </span>
                                    </span>
                                    <span className="absolute right-[18px] bottom-[18px] grid size-9 place-items-center rounded-full border border-(--glam-text)/50 text-(--glam-text)">
                                        <ArrowRight
                                            className="size-4"
                                            aria-hidden
                                        />
                                    </span>
                                </Link>
                            </li>
                        );
                    })}
                </ul>
            </Container>
        </section>
    );
}

function SectionHeading({
    title,
    lead,
    link,
}: {
    title: string;
    lead: string;
    link?: { href: string; label: string };
}) {
    return (
        <div className="mb-7 flex flex-col gap-3 xl:mb-8 xl:flex-row xl:items-end xl:justify-between">
            <div>
                <h2 className="mb-2.5 text-[32px] leading-tight xl:text-[40px]">
                    {title}
                </h2>
                <p className="text-base text-(--glam-muted)">{lead}</p>
            </div>
            {link && (
                <Link href={link.href} className={`${textLink} text-sm`}>
                    {link.label} →
                </Link>
            )}
        </div>
    );
}

// --- Popular services --------------------------------------------------------

function GlamPopular({ popular }: { popular: PopularCard[] }) {
    const t = useTranslations();

    return (
        <section>
            <Container className="py-12 xl:pt-14 xl:pb-6">
                <SectionHeading
                    title={t('tenant.glam.popular_title')}
                    lead={t('tenant.glam.popular_lead')}
                    link={{
                        href: '/book',
                        label: t('tenant.glam.services_all'),
                    }}
                />
                <ul className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4 xl:gap-5">
                    {popular.map(({ service, badge, description, photo }) => (
                        <li
                            key={service.id}
                            className={`${card} flex flex-col`}
                        >
                            <div className="relative h-[180px] xl:h-[190px]">
                                <Photo
                                    image={photo}
                                    className="absolute inset-0 h-full w-full"
                                />
                                {badge !== null && (
                                    <span className="absolute top-3.5 left-3.5 rounded-full bg-(--glam-pink) px-2.5 py-1 text-[10px] font-semibold tracking-[0.14em] text-(--glam-on-pink) uppercase">
                                        {badge}
                                    </span>
                                )}
                            </div>
                            <div className="flex flex-1 flex-col gap-2 p-5">
                                <h3 className="text-[22px] leading-tight">
                                    {service.name}
                                </h3>
                                {description !== null && (
                                    <p className="text-[13px] text-(--glam-muted)">
                                        {description}
                                    </p>
                                )}
                                <p className="mt-2 mb-4 flex gap-5 text-sm text-(--glam-body) tabular-nums">
                                    {service.duration_minutes !== null && (
                                        <span className="flex items-center gap-1.5">
                                            <Clock
                                                className="size-4 text-(--glam-pink)"
                                                aria-hidden
                                            />
                                            {t('tenant.glam.minutes', {
                                                count: service.duration_minutes,
                                            })}
                                        </span>
                                    )}
                                    <span className="flex items-center gap-1.5">
                                        <Wallet
                                            className="size-4 text-(--glam-pink)"
                                            aria-hidden
                                        />
                                        {formatMoney(
                                            service.price_minor,
                                            service.currency,
                                        )}
                                    </span>
                                </p>
                                <Link
                                    href={`/book?service=${service.id}`}
                                    className={`${pinkButton} mt-auto min-h-12 px-4 text-sm`}
                                >
                                    {t('tenant.glam.service_cta')}
                                    <ArrowRight
                                        className="size-4"
                                        aria-hidden
                                    />
                                </Link>
                            </div>
                        </li>
                    ))}
                </ul>
            </Container>
        </section>
    );
}

// --- Team --------------------------------------------------------------------

function GlamTeam({ team }: { team: GlamLandingData['team'] }) {
    const t = useTranslations();

    return (
        <section id="szakemberek" className="scroll-mt-4">
            <Container className="py-12 xl:pt-14 xl:pb-8">
                <SectionHeading
                    title={t('tenant.glam.team_title')}
                    lead={t('tenant.glam.team_lead')}
                    link={{ href: '/book', label: t('tenant.glam.team_all') }}
                />
                <ul className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4 xl:gap-5">
                    {team.map((member) => (
                        <li
                            key={member.id}
                            className={`${card} grid grid-cols-[92px_minmax(0,1fr)] gap-3.5 p-4`}
                        >
                            <div className="h-[150px] overflow-hidden rounded-[14px]">
                                <Photo
                                    image={glamPhoto(member.photo)}
                                    className="h-full w-full"
                                />
                            </div>
                            <div className="flex min-w-0 flex-col gap-1">
                                <h3 className="text-[22px] leading-tight">
                                    {member.name}
                                </h3>
                                {member.title !== null && (
                                    <p className="text-sm text-(--glam-body) first-letter:uppercase">
                                        {member.title}
                                    </p>
                                )}
                                {member.skills !== null && (
                                    <p className="mt-1 text-xs text-pretty text-(--glam-muted)">
                                        {member.skills}
                                    </p>
                                )}
                                <Link
                                    href={`/book?staff=${member.id}`}
                                    className={`${pinkButton} mt-auto self-start px-3 py-2 text-xs whitespace-nowrap`}
                                >
                                    {t('tenant.glam.team_cta')}
                                </Link>
                            </div>
                        </li>
                    ))}
                    <li className="flex flex-col items-center justify-center gap-2 rounded-[20px] border border-dashed border-(--glam-pink)/55 bg-(--glam-pink)/[0.05] p-6 text-center">
                        <Users
                            className="size-6 text-(--glam-pink)"
                            aria-hidden
                        />
                        <p className="text-[15px] font-semibold">
                            {t('tenant.glam.anyone_title')}
                        </p>
                        <p className="text-sm text-pretty text-(--glam-body)">
                            {t('tenant.glam.anyone_lead')}
                        </p>
                        <Link
                            href="/book"
                            className="mt-2 text-sm font-semibold text-(--glam-pink) hover:text-(--glam-pink-hover)"
                        >
                            {t('tenant.glam.anyone_cta')} →
                        </Link>
                    </li>
                </ul>
            </Container>
        </section>
    );
}

// --- Quick booking -----------------------------------------------------------

function GlamQuick({
    quick,
}: {
    quick: NonNullable<GlamLandingData['quick']>;
}) {
    const t = useTranslations();
    const dateHref = `/book?service=${quick.service_id}&date=${quick.date}`;
    const when = quick.is_today
        ? t('tenant.glam.quick_today')
        : quick.is_tomorrow
          ? t('tenant.glam.quick_tomorrow')
          : t('tenant.glam.quick_later', {
                date: new Date(`${quick.date}T12:00:00`).toLocaleDateString(
                    'hu-HU',
                    { month: 'long', day: 'numeric', weekday: 'long' },
                ),
            });

    return (
        <section>
            <Container className="pt-4 pb-12">
                <div className="flex flex-col gap-5 rounded-3xl bg-(--glam-pink) px-6 py-6 text-(--glam-on-pink) xl:flex-row xl:items-center xl:justify-between xl:gap-6 xl:px-7">
                    <div className="flex items-center gap-4">
                        <span className="grid size-14 flex-none place-items-center rounded-full bg-(--glam-berry)">
                            <AlarmClock className="size-7" aria-hidden />
                        </span>
                        <div>
                            <h2 className="text-[24px] leading-tight xl:text-[28px]">
                                {quick.duration_minutes !== null
                                    ? t('tenant.glam.quick_title', {
                                          count: quick.duration_minutes,
                                      })
                                    : quick.service_name}
                            </h2>
                            <p className="mt-1 text-sm text-(--glam-on-pink-soft)">
                                {when}
                            </p>
                        </div>
                    </div>
                    <div className="flex flex-wrap items-center gap-2.5 xl:gap-3">
                        {quick.times.map((time) => (
                            <Link
                                key={time}
                                href={dateHref}
                                aria-label={t('tenant.glam.quick_time', {
                                    service: quick.service_name,
                                    time,
                                })}
                                className="rounded-full bg-[#1b1219] px-5 py-3 text-[15px] font-semibold text-(--glam-text) tabular-nums transition-colors hover:bg-[#2a1a24] xl:px-[22px] xl:py-3.5"
                            >
                                {time}
                            </Link>
                        ))}
                        <Link
                            href={dateHref}
                            className="rounded-full border border-white/15 bg-[#1b1219] px-5 py-3 text-sm font-semibold text-(--glam-pink) transition-colors hover:bg-[#2a1a24] xl:ml-3 xl:px-[22px] xl:py-3.5"
                        >
                            {t('tenant.glam.quick_all')} →
                        </Link>
                    </div>
                </div>
            </Container>
        </section>
    );
}

// --- Three steps -------------------------------------------------------------

function GlamSteps() {
    const t = useTranslations();
    const steps = [
        { key: 'service', icon: Scissors },
        { key: 'time', icon: CalendarSearch },
        { key: 'done', icon: CircleCheck },
    ];

    return (
        <section className="relative border-y border-(--glam-pink)/10 bg-[linear-gradient(180deg,var(--glam-ink),#14101a_40%,var(--glam-ink))]">
            <Container className="relative grid items-center gap-8 py-10 xl:grid-cols-[220px_minmax(0,1fr)_560px] xl:gap-8 xl:py-0">
                <div className="relative hidden h-[250px] xl:block">
                    <Illustration
                        image={GLAM_FIXED.phone}
                        className="absolute bottom-0 -left-6 block w-[240px]"
                        imgClassName="h-auto w-full"
                    />
                </div>
                <div>
                    <h2 className="mb-3 text-[28px] leading-tight text-pretty xl:text-[34px]">
                        {t('tenant.glam.steps_title')}
                    </h2>
                    <p className="text-base text-(--glam-muted)">
                        {t('tenant.glam.steps_lead')}
                    </p>
                </div>
                <ol className="grid grid-cols-3 gap-3 xl:gap-4 xl:pr-28">
                    {steps.map(({ key, icon: Icon }, index) => (
                        <li
                            key={key}
                            className="flex flex-col items-center gap-2.5 text-center"
                        >
                            <span className="grid size-14 place-items-center rounded-full border border-(--glam-pink)/60 bg-(--glam-pink)/[0.06] text-(--glam-pink) xl:size-16">
                                <Icon
                                    className="size-6"
                                    strokeWidth={1.6}
                                    aria-hidden
                                />
                            </span>
                            <span className="text-xs font-semibold tracking-[0.2em] text-(--glam-pink) tabular-nums">
                                {String(index + 1).padStart(2, '0')}
                            </span>
                            <span className="text-sm leading-snug text-balance">
                                {t(`tenant.glam.steps.${key}`)}
                            </span>
                        </li>
                    ))}
                </ol>
                <p
                    aria-hidden
                    className="absolute top-1/2 right-6 hidden w-[140px] -translate-y-1/2 -rotate-10 text-center font-hand text-[26px] leading-[1.05] text-(--glam-pink) xl:block"
                >
                    {t('tenant.glam.steps_hand', { brand: BRAND_NAME })}
                </p>
            </Container>
        </section>
    );
}

// --- Contact -----------------------------------------------------------------

function GlamContact({
    profile,
    landing,
}: {
    profile: PublicHomeProfile;
    landing: PublicLanding;
}) {
    const t = useTranslations();
    const address = addressLine(profile);
    const [, neon] = landing.neon;
    const mapsHref =
        address !== null
            ? `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(address)}`
            : null;
    const contactHref =
        profile.phone !== null
            ? `tel:${profile.phone.replace(/\s+/g, '')}`
            : profile.email !== null
              ? `mailto:${profile.email}`
              : null;

    return (
        <section
            id="kapcsolat"
            className="grid scroll-mt-4 xl:min-h-[420px] xl:grid-cols-2"
        >
            <div
                id="rolunk"
                className="relative h-[260px] scroll-mt-4 overflow-hidden bg-(--glam-surface) sm:h-[340px] xl:h-auto"
            >
                <Illustration
                    image={GLAM_FIXED.salon}
                    className="absolute inset-0 block h-full w-full"
                    imgClassName="h-full w-full object-cover"
                />
                <div
                    aria-hidden
                    className="absolute inset-0 bg-[linear-gradient(90deg,rgb(14_12_17/.1)_40%,rgb(14_12_17/.75))]"
                />
                {neon !== undefined && (
                    <p
                        aria-hidden
                        className="glam-neon absolute top-1/2 right-6 w-[180px] -translate-y-1/2 -rotate-8 text-center font-hand text-[34px] leading-[1.05] sm:right-14 sm:w-[220px] sm:text-[44px]"
                    >
                        {neon}
                    </p>
                )}
            </div>
            <div className="grid gap-8 bg-(--glam-surface-2) px-5 py-10 sm:px-8 sm:py-12 lg:grid-cols-[minmax(0,1fr)_200px] lg:px-14">
                <div>
                    <h2 className="mb-5 text-[30px] text-(--glam-pink) xl:text-[34px]">
                        {profile.name}
                    </h2>
                    <dl className="flex flex-col gap-3.5 text-[15px] text-(--glam-body)">
                        {address !== null && (
                            <ContactLine icon={MapPin}>{address}</ContactLine>
                        )}
                        {profile.phone !== null && (
                            <ContactLine icon={Phone}>
                                <a
                                    href={`tel:${profile.phone.replace(/\s+/g, '')}`}
                                    className="text-(--glam-body) tabular-nums hover:text-(--glam-pink)"
                                >
                                    {profile.phone}
                                </a>
                            </ContactLine>
                        )}
                        {profile.opening_hours !== null && (
                            <ContactLine icon={Clock}>
                                {profile.opening_hours}
                            </ContactLine>
                        )}
                    </dl>
                    <div className="mt-7 flex flex-wrap gap-3">
                        {mapsHref !== null && (
                            <a
                                href={mapsHref}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="rounded-full border border-(--glam-pink)/60 px-[18px] py-2.5 text-[13px] font-medium text-(--glam-text) transition-colors hover:bg-(--glam-pink)/[0.12]"
                            >
                                {t('tenant.glam.directions')}
                            </a>
                        )}
                        {contactHref !== null && (
                            <a
                                href={contactHref}
                                className="rounded-full border border-(--glam-pink)/60 px-[18px] py-2.5 text-[13px] font-medium text-(--glam-text) transition-colors hover:bg-(--glam-pink)/[0.12]"
                            >
                                {t('tenant.glam.contact_button')}
                            </a>
                        )}
                    </div>
                </div>
                {mapsHref !== null && address !== null && (
                    <a
                        href={mapsHref}
                        target="_blank"
                        rel="noopener noreferrer"
                        aria-label={t('tenant.glam.map_label', { address })}
                        className="relative hidden h-[200px] overflow-hidden rounded-2xl border border-(--glam-pink)/25 bg-[#1a1720] bg-[linear-gradient(rgb(243_184_198/.08)_1px,transparent_1px),linear-gradient(90deg,rgb(243_184_198/.08)_1px,transparent_1px),linear-gradient(35deg,transparent_46%,rgb(243_184_198/.14)_47%,rgb(243_184_198/.14)_53%,transparent_54%),linear-gradient(-55deg,transparent_46%,rgb(243_184_198/.1)_47%,rgb(243_184_198/.1)_53%,transparent_54%)] bg-[length:24px_24px,24px_24px,100%_100%,100%_100%] lg:block"
                    >
                        <span
                            aria-hidden
                            className="absolute top-1/2 left-1/2 size-[22px] -translate-x-1/2 -translate-y-[90%] -rotate-45 rounded-[50%_50%_50%_0] bg-(--glam-pink) shadow-[0_0_18px_rgb(243_184_198/.7)]"
                        />
                    </a>
                )}
            </div>
        </section>
    );
}

function ContactLine({
    icon: Icon,
    children,
}: {
    icon: typeof MapPin;
    children: ReactNode;
}) {
    return (
        <div className="flex items-start gap-3">
            <dt className="mt-0.5 text-(--glam-pink)">
                <Icon className="size-[18px]" strokeWidth={1.8} aria-hidden />
            </dt>
            <dd>{children}</dd>
        </div>
    );
}

// --- Final CTA and footer ----------------------------------------------------

function GlamFinalCta() {
    const t = useTranslations();

    return (
        <section className="relative overflow-hidden border-t border-(--glam-pink)/15 bg-[linear-gradient(90deg,#2a1520,#1a0f17_55%,#2a1520)]">
            <div
                aria-hidden
                className="absolute inset-0 bg-[radial-gradient(ellipse_at_50%_100%,rgb(243_184_198/.25),transparent_60%)]"
            />
            <div
                aria-hidden
                className="absolute -right-10 -bottom-16 size-[260px] rotate-20 rounded-[0_100%_0_100%] bg-(--glam-pink)/[0.14]"
            />
            <Container className="relative grid items-center gap-6 py-10 text-center xl:grid-cols-[220px_minmax(0,1fr)_auto] xl:gap-8 xl:py-0 xl:text-left">
                <div className="relative hidden h-[210px] xl:block">
                    <Illustration
                        image={GLAM_FIXED.dryer}
                        className="absolute bottom-0 -left-5 block w-[230px]"
                        imgClassName="h-auto w-full"
                    />
                </div>
                <div>
                    <h2 className="mb-2.5 text-[28px] leading-tight xl:text-4xl">
                        {t('tenant.glam.final_title')}
                    </h2>
                    <p className="text-base text-(--glam-body)">
                        {t('tenant.glam.final_lead')}
                    </p>
                </div>
                <Link
                    href="/book"
                    className={`${pinkButton} mx-auto h-14 px-7 text-base shadow-[0_0_40px_rgb(243_184_198/.4)] xl:mx-0`}
                >
                    {t('tenant.glam.final_cta')}
                    <ArrowRight className="size-4" aria-hidden />
                </Link>
            </Container>
        </section>
    );
}

function GlamFooter({
    profile,
    landing,
}: {
    profile: PublicHomeProfile;
    landing: PublicLanding;
}) {
    const t = useTranslations();
    const { legal, tenant } = usePage().props;
    const social = [
        { key: 'instagram', label: 'Instagram', short: 'IG' },
        { key: 'facebook', label: 'Facebook', short: 'f' },
    ].flatMap((item) => {
        const href = (profile.social as Record<string, string | undefined>)[
            item.key
        ];

        return href ? [{ ...item, href }] : [];
    });

    return (
        <footer className="bg-(--glam-ink-deep)">
            <Container className="flex flex-col items-center gap-6 border-t border-(--glam-pink)/12 py-8 xl:flex-row xl:justify-between">
                <Brand profile={profile} landing={landing} size="sm" />
                <nav className="flex flex-wrap justify-center gap-x-6 gap-y-2 text-sm">
                    {(legal?.documents ?? []).map((document) => (
                        <a
                            key={document.id}
                            href={document.href}
                            className="text-(--glam-body) hover:text-(--glam-pink)"
                        >
                            {document.title}
                        </a>
                    ))}
                    <a
                        href="#kapcsolat"
                        className="text-(--glam-body) hover:text-(--glam-pink)"
                    >
                        {t('tenant.glam.nav.contact')}
                    </a>
                    <CookieSettingsLink className="text-(--glam-body) hover:text-(--glam-pink)" />
                </nav>
                {social.length > 0 && (
                    <div className="flex gap-3">
                        {social.map((item) => (
                            <a
                                key={item.key}
                                href={item.href}
                                target="_blank"
                                rel="noopener noreferrer"
                                aria-label={item.label}
                                className="grid size-9 place-items-center rounded-full border border-(--glam-pink)/35 text-xs font-semibold text-(--glam-text) hover:border-(--glam-pink)"
                            >
                                {item.short}
                            </a>
                        ))}
                    </div>
                )}
                <p className="flex items-center gap-2 text-[13px] text-(--glam-muted)">
                    {t('tenant.glam.powered_by')}
                    <Zap className="size-4 text-(--glam-pink)" aria-hidden />
                    <strong className="text-base font-semibold text-(--glam-text)">
                        {BRAND_NAME}
                    </strong>
                </p>
            </Container>
            {tenant?.is_demo && (
                <Container className="border-t border-(--glam-pink)/8 pt-4 pb-6">
                    <p className="text-xs leading-relaxed text-pretty text-(--glam-faint)">
                        {t('tenant.glam.demo_note', { brand: BRAND_NAME })}
                    </p>
                </Container>
            )}
        </footer>
    );
}
