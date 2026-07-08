import { Head, Link } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { ClockIcon, MailIcon, MapPinIcon, PhoneIcon } from 'lucide-react';

import PublicLayout from '@/Layouts/PublicLayout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatMoney } from '@/lib/format';
import { useTranslations } from '@/lib/i18n';
import type {
    PublicHomeBranding,
    PublicHomeCategory,
    PublicHomeLocation,
    PublicHomeProfile,
} from '@/types';

type HomeProps = {
    profile: PublicHomeProfile;
    branding: PublicHomeBranding;
    categories: PublicHomeCategory[];
    locations: PublicHomeLocation[];
};

function formatAddress(
    address: PublicHomeProfile['address'] | PublicHomeLocation['address'],
): string | null {
    if (!address) {
        return null;
    }
    const parts = [
        [address.postal_code, address.city].filter(Boolean).join(' '),
        address.line,
    ].filter(Boolean);

    return parts.length > 0 ? parts.join(', ') : null;
}

export default function TenantHome({
    profile,
    branding,
    categories,
    locations,
}: HomeProps) {
    const t = useTranslations();

    const metaDescription =
        profile.description ??
        t('tenant.home.meta_description', { name: profile.name });
    const hasServices = categories.length > 0;
    const socialEntries = Object.entries(profile.social);
    const profileAddress = formatAddress(profile.address);

    return (
        <PublicLayout>
            <Head title={profile.name}>
                <meta
                    head-key="description"
                    name="description"
                    content={metaDescription}
                />
                <meta
                    head-key="og:title"
                    property="og:title"
                    content={profile.name}
                />
                <meta
                    head-key="og:description"
                    property="og:description"
                    content={metaDescription}
                />
                <meta head-key="og:type" property="og:type" content="website" />
            </Head>

            {/* Hero */}
            <section className="relative overflow-hidden border-b border-border">
                {branding.cover_url ? (
                    <img
                        src={branding.cover_url}
                        alt=""
                        className="absolute inset-0 size-full object-cover opacity-20"
                    />
                ) : (
                    <div className="absolute inset-0 bg-gradient-to-br from-primary/15 via-transparent to-transparent" />
                )}
                <div className="relative mx-auto flex w-full max-w-5xl flex-col gap-4 px-4 py-16 sm:px-6 sm:py-24">
                    <h1 className="max-w-2xl text-3xl font-semibold tracking-tight sm:text-5xl">
                        {profile.name}
                    </h1>
                    {profile.description ? (
                        <p className="max-w-2xl text-lg text-muted-foreground">
                            {profile.description}
                        </p>
                    ) : (
                        <p className="max-w-2xl text-lg text-muted-foreground">
                            {t('tenant.home.subtitle')}
                        </p>
                    )}
                </div>
            </section>

            {/* Services */}
            <section className="mx-auto w-full max-w-5xl px-4 py-12 sm:px-6">
                <h2 className="text-xl font-semibold tracking-tight">
                    {t('tenant.home.services_title')}
                </h2>

                {hasServices ? (
                    <div className="mt-6 flex flex-col gap-10">
                        {categories.map((category) => (
                            <div
                                key={category.id ?? 'uncategorised'}
                                className="flex flex-col gap-4"
                            >
                                <h3 className="text-sm font-medium tracking-wide text-muted-foreground uppercase">
                                    {category.name ??
                                        t('tenant.home.other_services')}
                                </h3>
                                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                    {category.services.map((service, i) => (
                                        <motion.article
                                            key={service.id}
                                            initial={{ opacity: 0, y: 12 }}
                                            whileInView={{ opacity: 1, y: 0 }}
                                            viewport={{
                                                once: true,
                                                margin: '-40px',
                                            }}
                                            transition={{
                                                duration: 0.3,
                                                delay: Math.min(i * 0.04, 0.2),
                                            }}
                                            className="flex flex-col gap-3 rounded-xl border border-border bg-card p-5"
                                        >
                                            <div className="flex flex-col gap-1">
                                                <h4 className="font-semibold">
                                                    {service.name}
                                                </h4>
                                                {service.description ? (
                                                    <p className="line-clamp-3 text-sm text-muted-foreground">
                                                        {service.description}
                                                    </p>
                                                ) : null}
                                            </div>
                                            <div className="mt-auto flex flex-wrap items-center gap-2 pt-2">
                                                <span className="font-semibold text-primary">
                                                    {formatMoney(
                                                        service.price_minor,
                                                        service.currency,
                                                    )}
                                                </span>
                                                {service.duration_minutes ? (
                                                    <Badge
                                                        variant="outline"
                                                        className="gap-1"
                                                    >
                                                        <ClockIcon className="size-3" />
                                                        {t(
                                                            'tenant.home.minutes',
                                                            {
                                                                count: String(
                                                                    service.duration_minutes,
                                                                ),
                                                            },
                                                        )}
                                                    </Badge>
                                                ) : null}
                                            </div>
                                            <Button
                                                asChild
                                                size="sm"
                                                className="mt-1"
                                            >
                                                <Link
                                                    href={`/book?service=${service.id}`}
                                                >
                                                    {t('tenant.home.book_cta')}
                                                </Link>
                                            </Button>
                                        </motion.article>
                                    ))}
                                </div>
                            </div>
                        ))}
                    </div>
                ) : (
                    <p className="mt-6 rounded-xl border border-border bg-card px-4 py-10 text-center text-sm text-muted-foreground">
                        {t('tenant.home.services_empty')}
                    </p>
                )}
            </section>

            {/* Locations */}
            {locations.length > 0 ? (
                <section className="mx-auto w-full max-w-5xl px-4 py-12 sm:px-6">
                    <h2 className="text-xl font-semibold tracking-tight">
                        {t('tenant.home.locations_title')}
                    </h2>
                    <div className="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {locations.map((location) => {
                            const locationAddress = formatAddress(
                                location.address,
                            );

                            return (
                                <div
                                    key={location.id}
                                    className="flex flex-col gap-2 rounded-xl border border-border bg-card p-5"
                                >
                                    <h3 className="font-semibold">
                                        {location.name}
                                    </h3>
                                    {locationAddress ? (
                                        <p className="inline-flex items-start gap-1.5 text-sm text-muted-foreground">
                                            <MapPinIcon className="mt-0.5 size-3.5 shrink-0" />
                                            {locationAddress}
                                        </p>
                                    ) : null}
                                    {location.phone ? (
                                        <a
                                            href={`tel:${location.phone}`}
                                            className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
                                        >
                                            <PhoneIcon className="size-3.5" />
                                            {location.phone}
                                        </a>
                                    ) : null}
                                </div>
                            );
                        })}
                    </div>
                </section>
            ) : null}

            {/* Contact */}
            <section className="border-t border-border">
                <div className="mx-auto flex w-full max-w-5xl flex-col gap-4 px-4 py-12 sm:px-6">
                    <h2 className="text-xl font-semibold tracking-tight">
                        {t('tenant.home.contact_title')}
                    </h2>
                    <div className="flex flex-col gap-3 text-sm text-muted-foreground">
                        {profile.email ? (
                            <a
                                href={`mailto:${profile.email}`}
                                className="inline-flex items-center gap-2 hover:text-foreground"
                            >
                                <MailIcon className="size-4" />
                                {profile.email}
                            </a>
                        ) : null}
                        {profile.phone ? (
                            <a
                                href={`tel:${profile.phone}`}
                                className="inline-flex items-center gap-2 hover:text-foreground"
                            >
                                <PhoneIcon className="size-4" />
                                {profile.phone}
                            </a>
                        ) : null}
                        {profileAddress ? (
                            <span className="inline-flex items-center gap-2">
                                <MapPinIcon className="size-4" />
                                {profileAddress}
                            </span>
                        ) : null}
                        {profile.opening_hours ? (
                            <span className="inline-flex items-start gap-2">
                                <ClockIcon className="mt-0.5 size-4 shrink-0" />
                                <span className="whitespace-pre-line">
                                    {profile.opening_hours}
                                </span>
                            </span>
                        ) : null}
                    </div>

                    {socialEntries.length > 0 ? (
                        <div className="flex flex-wrap gap-2">
                            {socialEntries.map(([platform, url]) => (
                                <a
                                    key={platform}
                                    href={url}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="inline-flex items-center rounded-lg border border-border px-3 py-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
                                >
                                    {t(`admin.settings.social.${platform}`)}
                                </a>
                            ))}
                        </div>
                    ) : null}
                </div>
            </section>
        </PublicLayout>
    );
}
