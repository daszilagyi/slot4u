import {
    BarChart3,
    Bell,
    CreditCard,
    MapPin,
    MonitorSmartphone,
} from 'lucide-react';

import { Art, HandNote, Reveal } from '@/components/landing/primitives';
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
 * five tools with yellow icon tiles on the right.
 *
 * ⚠️ Without the illustration the left half would be a 520px hole, so the list
 * takes the whole width instead, in two columns. The handwritten aside goes with
 * the illustration: it annotates the picture, not the list.
 */
export default function Tools({ art }: { art: string | null }) {
    const t = useTranslations();

    const list = (
        <ul
            className={`grid gap-[18px] ${art === null ? 'md:grid-cols-2 md:gap-x-12' : ''}`}
        >
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
            <div
                className={`mx-auto grid w-full max-w-[1440px] items-center gap-12 px-4 py-16 sm:px-8 lg:px-14 ${
                    art !== null ? 'lg:grid-cols-2' : ''
                }`}
            >
                {art !== null && (
                    <div className="relative">
                        <div className="h-[320px] sm:h-[520px]">
                            <Art src={art} />
                        </div>
                        <HandNote
                            lead={t('welcome.tools.note_lead')}
                            tail={t('welcome.tools.note_tail')}
                            mark={<span className="text-err">♥</span>}
                            className="absolute right-5 -bottom-2.5 -rotate-[8deg] text-[30px] text-navy"
                        />
                    </div>
                )}

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
