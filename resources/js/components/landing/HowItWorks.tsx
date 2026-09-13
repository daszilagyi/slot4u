import { CalendarCog, Laptop, PartyPopper } from 'lucide-react';

import { Art, HandNote, Reveal } from '@/components/landing/primitives';
import { useTranslations } from '@/lib/i18n';

type Props = {
    art: {
        register: string | null;
        setup: string | null;
        bookings: string | null;
    };
};

/**
 * "Hogyan működik?" (SLO-229) — three steps, each a soft card with its number,
 * a line, and an illustration in the bottom corner.
 *
 * Until the illustrations exist (SLO-202) the corner holds the step's own icon,
 * so the three cards keep their shape instead of ending in a blank third.
 */
export default function HowItWorks({ art }: Props) {
    const t = useTranslations();

    const steps = [
        { key: 'register', Icon: Laptop, src: art.register },
        { key: 'setup', Icon: CalendarCog, src: art.setup },
        { key: 'bookings', Icon: PartyPopper, src: art.bookings },
    ] as const;

    return (
        <section id="funkciok" className="scroll-mt-20 bg-white">
            <div className="mx-auto w-full max-w-[1440px] px-4 pt-16 sm:px-8 lg:px-14">
                <div className="flex items-start justify-between gap-6">
                    <div>
                        <h2 className="mb-2.5 text-3xl font-black text-navy sm:text-[40px]">
                            {t('welcome.how.title')}
                        </h2>
                        <p className="text-[17px] text-ink-muted">
                            {t('welcome.how.lead')}
                        </p>
                    </div>
                    <HandNote
                        lead={t('welcome.how.note_lead')}
                        tail={t('welcome.how.note_tail')}
                        mark={<span className="inline-block rotate-90">↩</span>}
                        className="mr-10 hidden -rotate-[8deg] text-[30px] text-navy md:block"
                    />
                </div>

                <ol className="mt-9 grid gap-6 md:grid-cols-3">
                    {steps.map(({ key, Icon, src }, index) => (
                        <li key={key}>
                            <Reveal
                                index={index}
                                className="grid h-full grid-rows-[auto_1fr_auto] rounded-[20px] bg-canvas px-7 pt-7"
                            >
                                <h3 className="flex items-center gap-3 text-lg font-extrabold text-navy">
                                    <span
                                        className="grid size-8 shrink-0 place-items-center rounded-full bg-navy text-sm text-white"
                                        aria-hidden
                                    >
                                        {index + 1}
                                    </span>
                                    {t(`welcome.how.${key}`)}
                                </h3>
                                <p className="mt-3.5 ml-11 text-[15px] leading-relaxed text-ink-muted">
                                    {t(`welcome.how.${key}_hint`)}
                                </p>
                                <div className="mt-3 ml-14 flex h-[130px] items-end justify-end pb-5">
                                    {src !== null ? (
                                        <Art src={src} />
                                    ) : (
                                        <span
                                            className="grid size-20 place-items-center rounded-[20px] bg-brand-100 text-navy"
                                            aria-hidden
                                        >
                                            <Icon
                                                className="size-9"
                                                strokeWidth={1.5}
                                            />
                                        </span>
                                    )}
                                </div>
                            </Reveal>
                        </li>
                    ))}
                </ol>
            </div>
        </section>
    );
}
