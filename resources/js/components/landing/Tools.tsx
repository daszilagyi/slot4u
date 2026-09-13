import {
    BarChart3,
    Bell,
    CreditCard,
    MapPin,
    MonitorSmartphone,
} from 'lucide-react';

import { SLOTH_ARMCHAIR } from '@/components/landing/landingArt';
import {
    HandNote,
    Illustration,
    Reveal,
} from '@/components/landing/primitives';
import { useTranslations } from '@/lib/i18n';

const TOOLS = [
    { key: 'booking_page', Icon: MonitorSmartphone },
    { key: 'multi', Icon: MapPin },
    { key: 'notify', Icon: Bell },
    { key: 'payment', Icon: CreditCard },
    { key: 'reports', Icon: BarChart3 },
] as const;

/**
 * "Professzionális eszközök" (SLO-229) — the sloth in its armchair on the left,
 * five tools with yellow icon tiles on the right (SLO-236, docs/24 §2.2).
 *
 * On a phone the picture comes first, smaller and centred, with the handwritten
 * aside under it rather than over its corner.
 */
export default function Tools() {
    const t = useTranslations();

    const list = (
        <ul className="grid gap-[18px]">
            {TOOLS.map(({ key, Icon }, index) => (
                <li key={key}>
                    <Reveal index={index} className="flex items-start gap-4">
                        <span
                            className="grid size-11 shrink-0 place-items-center rounded-xl bg-highlight text-navy"
                            aria-hidden
                        >
                            <Icon className="size-[22px]" strokeWidth={2} />
                        </span>
                        <div>
                            <p className="text-base font-extrabold text-navy">
                                {t(`welcome.tools.${key}`)}
                            </p>
                            <p className="mt-0.5 text-sm text-ink-muted">
                                {t(`welcome.tools.${key}_hint`)}
                            </p>
                        </div>
                    </Reveal>
                </li>
            ))}
        </ul>
    );

    return (
        <section className="bg-gradient-to-b from-white to-canvas">
            <div className="mx-auto grid w-full max-w-[1440px] items-center gap-10 px-4 py-16 sm:px-8 lg:grid-cols-2 lg:gap-12 lg:px-14 lg:pb-24">
                <div className="relative mx-auto w-full max-w-[320px] lg:max-w-[520px]">
                    <Illustration
                        image={SLOTH_ARMCHAIR}
                        imgClassName="w-full"
                    />
                    <HandNote
                        lead={t('welcome.tools.note_lead')}
                        tail={t('welcome.tools.note_tail')}
                        mark={<span className="text-err">♥</span>}
                        className="mt-3 -rotate-6 text-center text-[28px] text-navy lg:absolute lg:right-2 lg:-bottom-14 lg:mt-0 lg:text-right lg:text-[30px]"
                    />
                </div>

                <div>
                    <p className="text-xs font-extrabold tracking-[0.12em] text-brand uppercase">
                        {t('welcome.tools.eyebrow')}
                    </p>
                    <h2 className="mt-3 mb-4 text-3xl leading-[1.15] font-black text-navy sm:text-4xl">
                        {t('welcome.tools.title_lead')}
                        <br />
                        {t('welcome.tools.title_tail')}
                    </h2>
                    <p className="mb-7 max-w-2xl text-base leading-relaxed text-ink-muted">
                        {t('welcome.tools.lead')}
                    </p>
                    {list}
                </div>
            </div>
        </section>
    );
}
