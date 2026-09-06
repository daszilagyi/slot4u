import { useInView, useReducedMotion, stagger } from '@/lib/motion';
import { useTranslations } from '@/lib/i18n';

/**
 * The booking widget in the hero (SLO-203, docs/21 §2 row 1).
 *
 * ⚠️ A component, not a screenshot — the doc is explicit about it, and the
 * reason is maintenance: a picture of a slot picker is a picture that goes
 * stale the first time the real one changes, and nobody notices because nothing
 * breaks. This is built from the same primitives as the real picker, so it
 * drifts only when the design system drifts.
 *
 * The DATA is invented, and that is fine: a marketing page has no tenant, no
 * service and no availability to query. What has to be true is the SHAPE — a
 * day, a grid of times in the mono face, one slot already gone, one chosen, and
 * the button naming the time it would book.
 */

/** The illustrated day. 09:45 is taken; 11:15 is the one being chosen. */
const SLOTS = [
    { time: '09:00', state: 'free' },
    { time: '09:45', state: 'taken' },
    { time: '10:30', state: 'free' },
    { time: '11:15', state: 'chosen' },
    { time: '12:00', state: 'free' },
    { time: '13:30', state: 'free' },
    { time: '14:15', state: 'taken' },
    { time: '15:00', state: 'free' },
    { time: '15:45', state: 'free' },
] as const;

const CHOSEN = SLOTS.find((slot) => slot.state === 'chosen')!.time;

export default function HeroSlotPreview() {
    const t = useTranslations();
    const reduced = useReducedMotion();
    const [ref, seen] = useInView<HTMLDivElement>({ threshold: 0.3 });

    // Above the fold, so this all but always fires immediately — the observer
    // is here so the entrance is not already over on a slow first paint.
    const revealed = seen || reduced;

    return (
        <div
            ref={ref}
            className="w-full max-w-sm rounded-[14px] border border-line bg-card p-5 text-card-foreground shadow-float"
        >
            <div className="flex items-baseline justify-between gap-2">
                <p className="text-sm font-medium">{t('welcome.widget.title')}</p>
                <p className="text-xs text-ink-muted">{t('welcome.widget.day')}</p>
            </div>

            <div className="mt-4 grid grid-cols-3 gap-2">
                {SLOTS.map((slot, index) => (
                    <SlotChip
                        key={slot.time}
                        time={slot.time}
                        state={slot.state}
                        revealed={revealed}
                        // 40ms apart (docs/21 §2), so the grid fills in rather
                        // than appearing all at once.
                        delay={stagger(index, 40, reduced)}
                        takenLabel={t('welcome.widget.taken')}
                    />
                ))}
            </div>

            {/*
                Not a <button>: nothing happens here, and a control that looks
                clickable and is not is worse than a picture of one. Keyboard
                users tabbing the hero would land on a dead stop.
            */}
            <div className="mt-4 rounded-[10px] bg-primary px-4 py-2.5 text-center text-sm font-medium text-primary-foreground">
                {t('welcome.widget.submit', { time: CHOSEN })}
            </div>
        </div>
    );
}

type SlotChipProps = {
    time: string;
    state: 'free' | 'taken' | 'chosen';
    revealed: boolean;
    delay: number;
    takenLabel: string;
};

function SlotChip({ time, state, revealed, delay, takenLabel }: SlotChipProps) {
    // Every time, price and number wears the mono face (docs/21 §1) — a column
    // of times that shifts sideways as the digits change is harder to scan.
    const base =
        'rounded-[10px] border py-1.5 text-center font-mono text-sm transition-opacity duration-500';

    const look =
        state === 'taken'
            ? 'border-line text-ink-muted line-through'
            : state === 'chosen'
              ? // The one yellow thing in the hero, and it is a ring rather than
                // a fill: the palette forbids yellow as a text or surface colour
                // outside a single CTA (docs/21 §1).
                'border-highlight bg-highlight/10 text-ink ring-1 ring-highlight'
              : 'border-line text-ink hover:border-brand-200';

    return (
        <div
            className={`${base} ${look} ${revealed ? 'opacity-100' : 'opacity-0'}`}
            style={{ transitionDelay: `${delay}ms` }}
            aria-label={state === 'taken' ? `${time} — ${takenLabel}` : time}
        >
            {time}
        </div>
    );
}
