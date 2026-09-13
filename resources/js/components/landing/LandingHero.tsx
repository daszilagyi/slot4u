import { ArrowRight, Check, Play } from 'lucide-react';

import HeroClouds from '@/components/landing/HeroClouds';
import HeroSloth from '@/components/landing/HeroSloth';
import { HandNote, highlightButton } from '@/components/landing/primitives';
import { useTranslations } from '@/lib/i18n';

/** The slot grid in the hero card. Illustration, not live availability. */
const SLOTS = ['09:00', '09:30', '10:00', '10:30', '11:00', '11:30'];
const PICKED = '10:00';

type Props = {
    /** Where "see it working" goes: the live demo section, or null for none. */
    demoHref: string | null;
};

/**
 * Hero (SLO-229, "Slot4u Landing" design): navy with a soft radial light, the
 * headline on the left, a tilted slot card with a "booking done" sticker on the
 * right, and a wave into the audience strip.
 *
 * ⚠️ The headline is server-rendered text and waits for no image. The sloth
 * (HeroSloth, docs/23) sits in a fixed-ratio box behind the card, so it cannot
 * move the layout when it arrives.
 */
export default function LandingHero({ demoHref }: Props) {
    const t = useTranslations();

    return (
        <section
            className="relative isolate overflow-hidden text-white"
            style={{
                background:
                    'radial-gradient(ellipse at 70% 30%, var(--navy-soft) 0%, var(--navy) 45%, var(--navy-deep) 100%)',
            }}
        >
            {/* Drifting clouds behind everything, so the sloth reads as
                flying (docs/23 §3b). First in the DOM, under the content. */}
            <HeroClouds />

            <div className="relative z-10 mx-auto grid w-full max-w-[1440px] gap-10 px-4 pt-12 sm:px-8 sm:pt-16 lg:grid-cols-[1.05fr_1fr] lg:px-14">
                <div className="pb-16 lg:pb-28">
                    <p className="inline-flex items-center gap-2 text-xs font-bold tracking-[0.12em] text-mist uppercase">
                        <span
                            className="size-2 rounded-full bg-highlight"
                            aria-hidden
                        />
                        {t('welcome.hero.eyebrow')}
                    </p>

                    <h1 className="mt-4 mb-5 text-[44px] leading-[1.05] font-black tracking-normal text-pretty sm:text-6xl lg:text-[64px]">
                        {t('welcome.hero.title_lead')}
                        <br />
                        {t('welcome.hero.title_middle')}
                        <br />
                        <span className="text-highlight">
                            {t('welcome.hero.title_accent')}
                        </span>
                    </h1>

                    <p className="mb-8 max-w-[440px] text-[17px] leading-relaxed text-mist-200">
                        {t('welcome.hero.lead')}
                        <br />
                        <strong className="text-white">
                            {t('welcome.hero.lead_strong')}
                        </strong>
                    </p>

                    <div className="flex flex-wrap gap-3.5">
                        {/* Plain anchors: /register is a Fortify route outside the
                            Inertia page graph. */}
                        <a
                            href="/register"
                            className={`${highlightButton} rounded-xl px-6 py-3.5 text-[15px]`}
                        >
                            {t('welcome.hero.cta_primary')}
                            <ArrowRight className="size-[18px]" aria-hidden />
                        </a>
                        {demoHref !== null && (
                            <a
                                href={demoHref}
                                className="inline-flex items-center gap-2.5 rounded-xl border-2 border-white/35 px-6 py-3 text-[15px] font-bold text-white transition-colors duration-200 hover:border-white focus-visible:ring-2 focus-visible:ring-white focus-visible:outline-none"
                            >
                                <span
                                    className="grid size-[26px] place-items-center rounded-full bg-white text-navy"
                                    aria-hidden
                                >
                                    <Play className="size-3 fill-current" />
                                </span>
                                {t('welcome.hero.cta_secondary')}
                            </a>
                        )}
                    </div>

                    <ul className="mt-8 flex flex-wrap gap-x-7 gap-y-2 text-sm font-semibold text-mist-100">
                        {(
                            [
                                'check_trial',
                                'check_card',
                                'check_quick',
                            ] as const
                        ).map((key) => (
                            <li key={key} className="flex items-center gap-2">
                                <Check
                                    className="size-4 text-highlight"
                                    strokeWidth={3}
                                    aria-hidden
                                />
                                {t(`welcome.hero.${key}`)}
                            </li>
                        ))}
                    </ul>
                </div>

                <HeroVisual />
            </div>

            {/* The wave into the audience strip, in that strip's colour. */}
            <svg
                aria-hidden
                viewBox="0 0 1440 120"
                preserveAspectRatio="none"
                className="absolute inset-x-0 -bottom-px z-0 block h-16 w-full sm:h-[120px]"
            >
                <path
                    d="M0,80 C150,20 250,110 420,70 C560,40 640,110 800,80 C960,50 1040,120 1200,80 C1320,50 1400,90 1440,70 L1440,120 L0,120 Z"
                    fill="var(--brand-100)"
                />
            </svg>
        </section>
    );
}

function HeroVisual() {
    const t = useTranslations();

    return (
        <div className="relative mx-auto mb-24 h-[610px] w-full max-w-[520px] sm:h-[440px] lg:mb-0 lg:h-[520px] lg:max-w-none">
            {/* The sloth behind the card (docs/23): ~410px wide on desktop
                with the card over its fist; on a phone (~260px) it flies above
                the card instead, which would otherwise cover it whole. */}
            <HeroSloth
                priority
                className="absolute top-12 left-0 w-[260px] sm:top-0 sm:w-[340px] lg:top-[-10px] lg:w-[420px]"
            />

            <div className="absolute top-0 right-6 z-20 flex -rotate-[4deg] items-center gap-2.5 rounded-[14px] bg-white px-4 py-3 text-base font-extrabold text-navy shadow-[0_12px_30px_rgba(0,0,0,.25)] sm:right-[120px]">
                <span
                    className="grid size-[26px] place-items-center rounded-full bg-navy text-white"
                    aria-hidden
                >
                    <Check className="size-3.5" strokeWidth={3} />
                </span>
                {t('welcome.hero.sticker')}
            </div>

            <div
                className="absolute top-[270px] right-2 z-10 w-[270px] -rotate-6 rounded-[22px] bg-white p-6 shadow-[0_24px_60px_rgba(0,0,0,.3)] sm:top-[110px] sm:right-5 sm:w-[290px]"
                role="img"
                aria-label={t('welcome.hero.slots_label')}
            >
                <div className="grid grid-cols-2 gap-3.5">
                    {SLOTS.map((time) => (
                        <span
                            key={time}
                            className={`rounded-xl border-2 px-4 py-3 text-center text-xl text-navy ${
                                time === PICKED
                                    ? // One pop, once the page has settled — the
                                      // card's only movement (docs/21 §2 row 1c).
                                      'animate-[landing-pop_600ms_ease-out_900ms_1_both] border-highlight bg-highlight font-black'
                                    : 'border-line font-extrabold'
                            }`}
                        >
                            {time}
                        </span>
                    ))}
                </div>
            </div>

            <HandNote
                lead={t('welcome.hero.note_lead')}
                tail={t('welcome.hero.note_tail')}
                mark={<span className="text-highlight">✔</span>}
                className="absolute right-4 bottom-0 z-20 -rotate-[8deg] text-right text-[28px] text-white sm:right-8 sm:bottom-8 sm:text-[32px]"
            />
        </div>
    );
}
