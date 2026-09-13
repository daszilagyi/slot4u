import { Head } from '@inertiajs/react';

import AudienceStrip from '@/components/landing/AudienceStrip';
import CalendarShowcase from '@/components/landing/CalendarShowcase';
import ClosingCta from '@/components/landing/ClosingCta';
import Faq from '@/components/landing/Faq';
import FlexibleModes from '@/components/landing/FlexibleModes';
import HowItWorks from '@/components/landing/HowItWorks';
import { SLOTH_FULL } from '@/components/landing/heroSlothAssets';
import LandingHero from '@/components/landing/LandingHero';
import Pricing, { type CommissionTerms } from '@/components/landing/Pricing';
import Tools from '@/components/landing/Tools';
import TryItLive from '@/components/landing/TryItLive';
import MarketingLayout from '@/Layouts/MarketingLayout';
import { useTranslations } from '@/lib/i18n';
import type { DemoPersona } from '@/types';

/** Slot => URL of the sloth illustration, or null until it exists (MarketingArt). */
type Art = {
    'step-register': string | null;
    'step-setup': string | null;
    'step-bookings': string | null;
    sofa: string | null;
    peek: string | null;
    cta: string | null;
};

type Props = {
    commission: CommissionTerms | null;
    demo_url: string | null;
    /**
     * The demo tenants a visitor can walk into (SLO-192). Empty on an
     * installation with nothing seeded — the section then renders nothing at all
     * rather than a dead first click.
     */
    demo_personas: DemoPersona[];
    art: Art;
    /** Absolute URL of the link-preview card — see HomeController. */
    og_image: string;
};

/**
 * The slot4u.hu home page, drawn from the "Slot4u Landing" Claude Design
 * (SLO-229, docs/21 2026-09-13).
 *
 * The design's section order, with two deliberate departures (Daniel,
 * 2026-09-13): the pricing section stays — the design has none, the product's
 * whole pitch is its price — and the live demo keeps its running iframe instead
 * of the design's static mock with invented prices. The FAQ stays too; its
 * structured data is what search shows under the result.
 */
export default function Welcome({
    commission,
    demo_url,
    demo_personas,
    art,
    og_image,
}: Props) {
    const t = useTranslations();

    // "See it working" goes to the live demo section when there is one to scroll
    // to, and straight to the demo tenant otherwise.
    const demoHref = demo_personas.length > 0 ? '#demo' : demo_url;

    return (
        <MarketingLayout>
            <Head>
                <title>{t('welcome.meta_title')}</title>
                <meta
                    name="description"
                    content={t('welcome.meta_description')}
                />
                {/* Open Graph, so a link pasted into a chat renders as a card
                    rather than a bare URL (SLO-170). */}
                <meta property="og:type" content="website" />
                <meta property="og:title" content={t('welcome.meta_title')} />
                <meta
                    property="og:description"
                    content={t('welcome.meta_description')}
                />
                <meta property="og:image" content={og_image} />
                <meta property="og:image:width" content="1200" />
                <meta property="og:image:height" content="630" />
                <meta name="twitter:card" content="summary_large_image" />
                <meta name="twitter:image" content={og_image} />
                {/* The hero sloth's composite is the largest image above the
                    fold: fetch it with the document, not after the CSS
                    (docs/23 §4). `type` lets a browser without WebP skip it. */}
                <link
                    rel="preload"
                    as="image"
                    type="image/webp"
                    href={SLOTH_FULL.webp}
                    fetchPriority="high"
                />
            </Head>

            <LandingHero demoHref={demoHref} />
            <AudienceStrip />
            <HowItWorks
                art={{
                    register: art['step-register'],
                    setup: art['step-setup'],
                    bookings: art['step-bookings'],
                }}
            />
            <Tools art={art.sofa} />
            <FlexibleModes demoHref={demoHref} />
            <CalendarShowcase art={art.peek} />
            <Pricing commission={commission} />
            <TryItLive personas={demo_personas} />
            <Faq />
            <ClosingCta art={art.cta} />
        </MarketingLayout>
    );
}
