import { ArrowRight, Check } from 'lucide-react';

import { SLOTH_BEANBAG } from '@/components/landing/landingArt';
import {
    HandNote,
    highlightButton,
    Illustration,
} from '@/components/landing/primitives';
import { useTranslations } from '@/lib/i18n';

/**
 * The cream closing band (SLO-229): sloth, the ask, and four reassurances.
 *
 * The beanbag sloth sits left of the heading; on a phone it moves above it
 * (docs/24 §2.4). It does not float — one moving mascot per page is the hero's.
 */
export default function ClosingCta() {
    const t = useTranslations();

    return (
        <section className="bg-cream">
            <div className="mx-auto grid w-full max-w-[1440px] items-center gap-8 px-4 py-12 sm:px-8 lg:grid-cols-[300px_1.2fr_1fr] lg:gap-10 lg:px-14">
                <Illustration
                    image={SLOTH_BEANBAG}
                    className="mx-auto block w-full max-w-[240px] lg:max-w-[300px]"
                    imgClassName="w-full"
                />

                <div>
                    <h2 className="mb-3 text-2xl leading-tight font-black text-navy sm:text-[30px]">
                        {t('welcome.cta_band.title')}
                    </h2>
                    <p className="mb-5 text-[15px] text-ink-muted">
                        {t('welcome.cta_band.lead')}
                    </p>
                    <a
                        href="/register"
                        className={`${highlightButton} rounded-xl px-6 py-3.5 text-[15px] shadow-[0_6px_16px_rgba(15,37,71,.12)]`}
                    >
                        {t('welcome.hero.cta_primary')}
                        <ArrowRight className="size-[18px]" aria-hidden />
                    </a>
                </div>

                <div className="flex items-center gap-8">
                    <ul className="grid gap-3 text-sm font-bold text-navy">
                        {(
                            [
                                'check_quick',
                                'check_fee',
                                'check_card',
                                'check_eu',
                            ] as const
                        ).map((key) => (
                            <li key={key} className="flex items-center gap-2.5">
                                <Check
                                    className="size-4 text-gold"
                                    strokeWidth={3}
                                    aria-hidden
                                />
                                {t(`welcome.cta_band.${key}`)}
                            </li>
                        ))}
                    </ul>
                    <HandNote
                        lead={t('welcome.cta_band.note_lead')}
                        tail={t('welcome.cta_band.note_tail')}
                        mark={<span className="text-err">♥</span>}
                        className="hidden -rotate-[8deg] text-[30px] text-navy lg:block"
                    />
                </div>
            </div>
        </section>
    );
}
