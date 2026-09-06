import { EXAMPLE_HOST } from '@/lib/brand';
import { useTranslations } from '@/lib/i18n';
import { useInView, useReducedMotion } from '@/lib/motion';

/**
 * The dashboard, in a browser frame, on the second navy band (SLO-204,
 * docs/21 §2 row 5).
 *
 * ⚠️ A component rather than the `dashboard-mock.png` the doc lists — which the
 * doc itself allows ("vagy élő komponens"). Same reasoning as the steps above:
 * no browser here to produce a screenshot, and a picture of a dashboard is a
 * picture that goes quietly out of date. This one also costs no image request
 * on a page that is trying to paint in under two seconds.
 *
 * The numbers are illustrative and deliberately modest — a marketing page
 * showing a million forints of daily revenue is a page nobody believes.
 */

/** Last week's bookings, as a share of the tallest day. The shape is the story. */
const WEEK = [0.45, 0.62, 0.55, 0.78, 0.94, 0.7, 0.25];

export default function ProductShowcase() {
    const t = useTranslations();
    const reduced = useReducedMotion();
    const [ref, seen] = useInView<HTMLDivElement>({ threshold: 0.25 });

    const revealed = seen || reduced;

    return (
        <section className="relative isolate overflow-hidden bg-navy text-canvas">
            <div
                aria-hidden
                className="pointer-events-none absolute inset-0 opacity-[0.07]"
                style={{
                    backgroundImage:
                        'linear-gradient(to right, var(--ice) 1px, transparent 1px), linear-gradient(to bottom, var(--ice) 1px, transparent 1px)',
                    backgroundSize: '32px 32px',
                }}
            />

            <div className="relative mx-auto w-full max-w-5xl px-4 py-16 sm:px-6 sm:py-24">
                <h2 className="max-w-2xl text-2xl font-semibold tracking-tight text-balance sm:text-3xl">
                    {t('welcome.showcase_title')}
                </h2>
                <p className="mt-3 max-w-2xl text-canvas/75">
                    {t('welcome.showcase_lead')}
                </p>

                <div
                    ref={ref}
                    className="ease-brand mt-10 transition-all duration-700"
                    style={{
                        opacity: revealed ? 1 : 0,
                        // The 4° tilt straightening as it arrives (docs/21 §2).
                        // Skipped entirely under reduced motion — a transform
                        // that lands flat is still a transform that moved.
                        transform: reduced
                            ? 'none'
                            : revealed
                              ? 'perspective(1200px) rotateX(0deg)'
                              : 'perspective(1200px) rotateX(4deg)',
                    }}
                >
                    <BrowserFrame>
                        <DashboardArt revealed={revealed} reduced={reduced} t={t} />
                    </BrowserFrame>
                </div>
            </div>
        </section>
    );
}

function BrowserFrame({ children }: { children: React.ReactNode }) {
    return (
        <div className="shadow-float overflow-hidden rounded-[14px] border border-canvas/15 bg-card">
            <div className="flex items-center gap-2 border-b border-line bg-canvas px-4 py-2.5">
                <span className="size-2.5 rounded-full bg-line" />
                <span className="size-2.5 rounded-full bg-line" />
                <span className="size-2.5 rounded-full bg-line" />
                <p className="ml-2 font-mono text-[11px] text-ink-muted">
                    {`${EXAMPLE_HOST}/dashboard`}
                </p>
            </div>
            {children}
        </div>
    );
}

type ArtProps = {
    revealed: boolean;
    reduced: boolean;
    t: (key: string) => string;
};

function DashboardArt({ revealed, reduced, t }: ArtProps) {
    const width = 640;
    const height = 160;
    const step = width / (WEEK.length - 1);

    const points = WEEK.map(
        (value, index) => `${index * step},${height - value * height * 0.8}`,
    ).join(' ');

    return (
        <div className="bg-card p-5 text-card-foreground">
            <div className="grid gap-3 sm:grid-cols-3">
                <Stat label={t('welcome.showcase.revenue')} value="184 500 Ft" />
                <Stat label={t('welcome.showcase.bookings')} value="12" />
                <Stat label={t('welcome.showcase.utilisation')} value="78%" />
            </div>

            <p className="mt-5 text-xs text-ink-muted">
                {t('welcome.showcase.chart_label')}
            </p>

            <svg
                viewBox={`0 0 ${width} ${height}`}
                className="mt-2 h-28 w-full"
                preserveAspectRatio="none"
                role="img"
                aria-label={t('welcome.showcase.chart_label')}
            >
                <polyline
                    points={points}
                    fill="none"
                    stroke="var(--brand)"
                    strokeWidth={3}
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    // The line draws itself in (docs/21 §2). Under reduced
                    // motion the dash offset is simply zero — the finished line,
                    // with nothing to watch.
                    style={{
                        strokeDasharray: 2000,
                        strokeDashoffset: reduced ? 0 : revealed ? 0 : 2000,
                        transition: reduced
                            ? 'none'
                            : 'stroke-dashoffset 900ms cubic-bezier(.2,.8,.2,1)',
                    }}
                />
            </svg>
        </div>
    );
}

function Stat({ label, value }: { label: string; value: string }) {
    return (
        <div className="rounded-[10px] border border-line px-4 py-3">
            <p className="text-xs text-ink-muted">{label}</p>
            {/* Figures wear the mono face and tabular digits (docs/21 §1). */}
            <p className="mt-1 font-mono text-lg">{value}</p>
        </div>
    );
}
