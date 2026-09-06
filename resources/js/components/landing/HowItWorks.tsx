import { EXAMPLE_HOST } from '@/lib/brand';
import { useTranslations } from '@/lib/i18n';
import { stagger, useInView, useReducedMotion } from '@/lib/motion';

/**
 * Three steps, from the provider's side (SLO-204, docs/21 §2 row 3).
 *
 * ⚠️ The illustrations are components, not screenshots — the same call the doc
 * makes for the hero widget, extended here for two reasons.
 *
 * The practical one: there is no browser in the build environment, so a
 * screenshot cannot be produced or refreshed by whoever changes this next.
 * The better one: a screenshot of an admin screen is a picture that goes stale
 * the first time that screen changes, silently, because nothing breaks when it
 * does. These are small enough to read at a glance, they follow the design
 * tokens, and they cost no image request on a page whose LCP budget is tight.
 *
 * A real screenshot can replace any of them later — the layout takes a fixed
 * 16:10 block either way.
 */

export default function HowItWorks() {
    const t = useTranslations();
    const reduced = useReducedMotion();
    const [ref, seen] = useInView<HTMLDivElement>();

    const revealed = seen || reduced;

    const steps = [
        { key: 'setup', Art: SetupArt },
        { key: 'share', Art: ShareArt },
        { key: 'run', Art: RunArt },
    ] as const;

    return (
        <section className="border-b border-line bg-canvas">
            <div className="mx-auto w-full max-w-5xl px-4 py-16 sm:px-6 sm:py-20">
                <h2 className="text-2xl font-semibold tracking-tight text-balance sm:text-3xl">
                    {t('welcome.steps_title')}
                </h2>
                <p className="mt-3 max-w-2xl text-ink-muted">
                    {t('welcome.steps_lead')}
                </p>

                <div ref={ref} className="mt-10 grid gap-6 md:grid-cols-3">
                    {steps.map(({ key, Art }, index) => (
                        <div
                            key={key}
                            className="rounded-[14px] border border-line bg-card p-5 transition-all duration-500"
                            style={{
                                transitionDelay: `${stagger(index, 80, reduced)}ms`,
                                opacity: revealed ? 1 : 0,
                                transform: revealed ? 'none' : 'translateY(12px)',
                            }}
                        >
                            {/* Fixed aspect, so the card never resizes when the
                                art inside it changes — and so a real screenshot
                                could drop in without moving the grid. */}
                            <div className="aspect-[16/10] overflow-hidden rounded-[10px] border border-line bg-canvas p-3">
                                <Art />
                            </div>

                            <p className="mt-4 flex items-baseline gap-2 font-medium">
                                <span className="font-mono text-sm text-brand">
                                    {index + 1}
                                </span>
                                {t(`welcome.steps.${key}`)}
                            </p>
                            <p className="mt-1 text-sm text-ink-muted">
                                {t(`welcome.steps.${key}_hint`)}
                            </p>
                        </div>
                    ))}
                </div>
            </div>
        </section>
    );
}

/** Step 1: a week of opening hours being filled in. */
function SetupArt() {
    return (
        <div className="flex h-full flex-col gap-1.5">
            {[0, 1, 2].map((row) => (
                <div key={row} className="flex flex-1 gap-1.5">
                    {[0, 1, 2, 3, 4].map((col) => (
                        <div
                            key={col}
                            className={`flex-1 rounded-[4px] ${
                                // A rota with a gap in it: three of the fifteen
                                // cells are closed, which is what a real week
                                // looks like and a full grid does not.
                                (row + col) % 7 === 3
                                    ? 'bg-line'
                                    : 'bg-brand-100'
                            }`}
                        />
                    ))}
                </div>
            ))}
        </div>
    );
}

/** Step 2: the link, out in the world. */
function ShareArt() {
    return (
        <div className="flex h-full flex-col justify-center gap-2">
            <div className="rounded-[6px] border border-line bg-card px-2 py-1.5">
                <p className="truncate font-mono text-[10px] text-ink-muted">
                    {EXAMPLE_HOST}
                </p>
            </div>
            <div className="flex gap-1.5">
                <div className="h-6 flex-1 rounded-[4px] bg-brand-100" />
                <div className="h-6 flex-1 rounded-[4px] bg-brand-100" />
                <div className="h-6 w-8 rounded-[4px] bg-brand" />
            </div>
        </div>
    );
}

/** Step 3: a booking arriving, and the reminder that follows it. */
function RunArt() {
    return (
        <div className="flex h-full flex-col justify-center gap-2">
            <div className="flex items-center gap-2 rounded-[6px] border border-line bg-card px-2 py-1.5">
                <span className="size-1.5 shrink-0 rounded-full bg-ok" />
                <span className="h-1.5 flex-1 rounded-full bg-brand-100" />
                <span className="font-mono text-[10px] text-ink-muted">
                    10:30
                </span>
            </div>
            <div className="flex items-center gap-2 rounded-[6px] border border-line bg-card px-2 py-1.5 opacity-70">
                <span className="size-1.5 shrink-0 rounded-full bg-brand-200" />
                <span className="h-1.5 flex-1 rounded-full bg-brand-100" />
                <span className="font-mono text-[10px] text-ink-muted">
                    13:00
                </span>
            </div>
            {/* The reminder, one line, in the accent that means "sent". */}
            <div className="flex items-center gap-2 px-2">
                <span className="size-1.5 shrink-0 rounded-full bg-highlight" />
                <span className="h-1.5 w-2/3 rounded-full bg-line" />
            </div>
        </div>
    );
}
