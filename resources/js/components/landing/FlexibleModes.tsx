import {
    ArrowRight,
    CalendarDays,
    DoorOpen,
    FileClock,
    MessagesSquare,
    ShoppingBag,
    Users,
} from 'lucide-react';

import { highlightButton, Reveal } from '@/components/landing/primitives';
import { useTranslations } from '@/lib/i18n';

/**
 * The five booking modes plus approval, in the engine's own terms (docs/04) —
 * every card is something the product does today.
 */
const MODES = [
    { key: 'duration_based', Icon: CalendarDays },
    { key: 'event_based', Icon: Users },
    { key: 'resource_rental', Icon: DoorOpen },
    { key: 'no_time_slot', Icon: ShoppingBag },
    { key: 'approval', Icon: FileClock },
    { key: 'quote_request', Icon: MessagesSquare },
] as const;

/** "Rugalmas minden szolgáltatástípushoz" (SLO-229). */
export default function FlexibleModes({
    demoHref,
}: {
    demoHref: string | null;
}) {
    const t = useTranslations();

    return (
        <section className="bg-brand-100">
            <div className="mx-auto grid w-full max-w-[1440px] items-center gap-12 px-4 py-16 sm:px-8 lg:grid-cols-[0.9fr_1.3fr] lg:px-14">
                <div>
                    <h2 className="mb-4 text-3xl leading-[1.15] font-black text-navy sm:text-[34px]">
                        {t('welcome.flexible.title_lead')}
                        <br />
                        {t('welcome.flexible.title_tail')}
                    </h2>
                    <p className="mb-7 text-base leading-relaxed text-ink-muted">
                        {t('welcome.flexible.lead')}
                    </p>
                    {demoHref !== null && (
                        <a
                            href={demoHref}
                            className={`${highlightButton} rounded-xl px-6 py-3.5 text-[15px]`}
                        >
                            {t('welcome.flexible.cta')}
                            <ArrowRight className="size-[18px]" aria-hidden />
                        </a>
                    )}
                </div>

                <ul className="grid grid-cols-2 gap-[18px] sm:grid-cols-3">
                    {MODES.map(({ key, Icon }, index) => (
                        <li key={key}>
                            <Reveal
                                index={index}
                                className="grid h-full justify-items-center gap-3.5 rounded-[18px] bg-white px-4 py-7 text-center text-[13px] font-extrabold text-navy shadow-[0_4px_16px_rgba(15,37,71,.06)]"
                            >
                                <Icon
                                    className="size-10"
                                    strokeWidth={1.5}
                                    aria-hidden
                                />
                                {t(`welcome.flexible.${key}`)}
                            </Reveal>
                        </li>
                    ))}
                </ul>
            </div>
        </section>
    );
}
