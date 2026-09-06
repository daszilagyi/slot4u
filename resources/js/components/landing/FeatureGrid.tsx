import {
    BarChart3,
    Bell,
    CalendarCheck,
    CreditCard,
    Globe,
    ShieldCheck,
} from 'lucide-react';

import { useTranslations } from '@/lib/i18n';
import { stagger, useInView, useReducedMotion } from '@/lib/motion';

/**
 * The feature grid (SLO-204, docs/21 §2 row 4): six blocks, 2×3, on the
 * `brand-100` band that separates it from the sections either side.
 *
 * Grown from the four this page already had rather than invented: the two
 * additions are features that shipped (conflict-free calendars across staff AND
 * rooms — SLO-200; online payment with invoicing — M6). A marketing grid padded
 * out with things that do not exist yet is the fastest way to lose the first
 * customer who tries one.
 *
 * ⚠️ Hover is one step and no more, exactly as the doc specifies: the icon box
 * moves to `brand-200` and the glyph grows 5%. A card that lifts, glows and
 * shadows on hover is six competing animations on one screen.
 */

const FEATURES = [
    { key: 'domain', Icon: Globe },
    { key: 'calendar', Icon: CalendarCheck },
    { key: 'notify', Icon: Bell },
    { key: 'payment', Icon: CreditCard },
    { key: 'privacy', Icon: ShieldCheck },
    { key: 'reports', Icon: BarChart3 },
] as const;

export default function FeatureGrid() {
    const t = useTranslations();
    const reduced = useReducedMotion();
    const [ref, seen] = useInView<HTMLDivElement>();

    const revealed = seen || reduced;

    return (
        <section className="border-y border-line bg-brand-100/50">
            <div className="mx-auto w-full max-w-5xl px-4 py-16 sm:px-6 sm:py-20">
                <h2 className="text-2xl font-semibold tracking-tight text-balance sm:text-3xl">
                    {t('welcome.trust_title')}
                </h2>

                <div ref={ref} className="mt-10 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    {FEATURES.map(({ key, Icon }, index) => (
                        <div
                            key={key}
                            className="group transition-all duration-500"
                            style={{
                                transitionDelay: `${stagger(index, 80, reduced)}ms`,
                                opacity: revealed ? 1 : 0,
                                transform: revealed ? 'none' : 'translateY(12px)',
                            }}
                        >
                            <div className="ease-brand flex size-10 items-center justify-center rounded-[10px] bg-brand-100 transition-colors duration-200 group-hover:bg-brand-200">
                                <Icon
                                    className="ease-brand size-5 text-brand transition-transform duration-200 group-hover:scale-105"
                                    strokeWidth={1.75}
                                    aria-hidden
                                />
                            </div>
                            <p className="mt-4 font-medium text-ink">
                                {t(`welcome.trust.${key}`)}
                            </p>
                            <p className="mt-1 text-sm text-ink-muted">
                                {t(`welcome.trust.${key}_hint`)}
                            </p>
                        </div>
                    ))}
                </div>
            </div>
        </section>
    );
}
