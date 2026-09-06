import { BadgeCheck, CreditCard, ShieldCheck, Wallet } from 'lucide-react';

import { useTranslations } from '@/lib/i18n';
import { stagger, useInView, useReducedMotion } from '@/lib/motion';

/**
 * The trust strip under the hero (SLO-204, docs/21 §2 row 2).
 *
 * ⚠️ Deliberately NOT customer logos, and not a count of them.
 *
 * The plan asked for "already used by X providers" behind a marquee of tenant
 * logos. Without customers both are invented — and the doc's own escape hatch,
 * "the demo tenants' logos will do", is the worse half: GlamZone and Premium
 * Fitness Studio are fixtures we wrote, so a prospect who recognises them from
 * the demo loses exactly the trust this strip exists to build.
 *
 * What is here instead is true with zero customers and does not age. Each claim
 * has something behind it: EU data residency is docs/19, no monthly fee is
 * docs/10, no card is what the sign-up flow actually does.
 *
 * ⚠️ No SLA figure. "99.9% uptime" is a contractual promise, and docs/17 is
 * monitoring — it would be the one line here nobody could stand behind.
 */

const CLAIMS = [
    { key: 'local', Icon: BadgeCheck },
    { key: 'eu', Icon: ShieldCheck },
    { key: 'no_fee', Icon: Wallet },
    { key: 'no_card', Icon: CreditCard },
] as const;

export default function AssuranceStrip() {
    const t = useTranslations();
    const reduced = useReducedMotion();
    const [ref, seen] = useInView<HTMLDivElement>();

    const revealed = seen || reduced;

    return (
        <section className="border-b border-line bg-canvas">
            <div
                ref={ref}
                className="mx-auto grid w-full max-w-5xl gap-6 px-4 py-10 sm:grid-cols-2 sm:px-6 lg:grid-cols-4"
            >
                {CLAIMS.map(({ key, Icon }, index) => (
                    <div
                        key={key}
                        className="flex gap-3 transition-all duration-500"
                        style={{
                            transitionDelay: `${stagger(index, 80, reduced)}ms`,
                            opacity: revealed ? 1 : 0,
                            // A short lift, not a slide: the strip sits directly
                            // under the hero, and anything larger reads as the
                            // page still loading.
                            transform: revealed ? 'none' : 'translateY(8px)',
                        }}
                    >
                        <Icon
                            className="mt-0.5 size-5 shrink-0 text-brand"
                            strokeWidth={1.75}
                            aria-hidden
                        />
                        <div>
                            <p className="text-sm font-medium text-ink">
                                {t(`welcome.assurance.${key}`)}
                            </p>
                            <p className="mt-1 text-sm text-ink-muted">
                                {t(`welcome.assurance.${key}_hint`)}
                            </p>
                        </div>
                    </div>
                ))}
            </div>
        </section>
    );
}
