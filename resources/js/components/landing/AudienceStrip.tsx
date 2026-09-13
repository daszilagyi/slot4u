import { Camera, Dumbbell, HeartPulse, Scissors, Users } from 'lucide-react';

import { Reveal } from '@/components/landing/primitives';
import { useTranslations } from '@/lib/i18n';

const AUDIENCES = [
    { key: 'salon', Icon: Scissors },
    { key: 'trainer', Icon: Dumbbell },
    { key: 'health', Icon: HeartPulse },
    { key: 'coach', Icon: Users },
    { key: 'other', Icon: Camera },
] as const;

/**
 * Who it is for (SLO-229) — the light-blue strip the hero's wave pours into.
 *
 * Trades, not customer logos: the design lists kinds of business, which is true
 * without a single reference customer (docs/21 §2 on why logos stay out).
 */
export default function AudienceStrip() {
    const t = useTranslations();

    return (
        <section
            aria-label={t('welcome.audience_strip.label')}
            className="bg-brand-100"
        >
            <ul className="mx-auto grid w-full max-w-[1440px] grid-cols-2 gap-6 px-4 pt-6 pb-11 text-center text-[13px] font-extrabold text-navy sm:grid-cols-3 sm:px-8 lg:grid-cols-5 lg:px-14">
                {AUDIENCES.map(({ key, Icon }, index) => (
                    <li key={key}>
                        <Reveal
                            index={index}
                            className="grid justify-items-center gap-3"
                        >
                            <Icon
                                className="size-9"
                                strokeWidth={1.75}
                                aria-hidden
                            />
                            <span>
                                {t(`welcome.audience_strip.${key}`)}
                                {key === 'other' && (
                                    <span className="block font-semibold text-ink-muted">
                                        {t('welcome.audience_strip.other_hint')}
                                    </span>
                                )}
                            </span>
                        </Reveal>
                    </li>
                ))}
            </ul>
        </section>
    );
}
