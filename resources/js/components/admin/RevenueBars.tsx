import { useState } from 'react';

import { formatMoney } from '@/lib/format';
import { useTranslations } from '@/lib/i18n';
import { cn } from '@/lib/utils';

type Point = { date: string; revenue_minor: number; bookings: number };

type RevenueBarsProps = {
    series: Point[];
    currency: string;
};

/**
 * Daily revenue as bars (SLO-137). One series, so there is no legend — the panel
 * title names it — and only the peak day is direct-labelled; every other value is
 * on hover. Days with no revenue keep a flat stub rather than disappearing, so a
 * gap in the row reads as "nothing happened" and never as "missing data".
 *
 * Deliberately CSS rather than a charting dependency: a single-hue magnitude chart
 * is a flex row of divs, and the project keeps its dependency list short.
 */
export default function RevenueBars({ series, currency }: RevenueBarsProps) {
    const t = useTranslations();
    const [hovered, setHovered] = useState<Point | null>(null);

    const max = series.reduce((acc, p) => Math.max(acc, p.revenue_minor), 0);
    const peak = series.find((p) => p.revenue_minor === max && max > 0);
    const active = hovered ?? peak ?? null;

    if (max === 0) {
        return (
            <p className="py-8 text-center text-sm text-muted-foreground">
                {t('admin.reports.series.empty')}
            </p>
        );
    }

    return (
        <figure className="flex flex-col gap-3">
            <figcaption className="min-h-5 text-xs text-muted-foreground">
                {active
                    ? t('admin.reports.series.tooltip', {
                          date: active.date,
                          revenue: formatMoney(active.revenue_minor, currency),
                          count: String(active.bookings),
                      })
                    : null}
            </figcaption>

            <div className="overflow-x-auto">
                <div
                    // One image, not one per bar: a screen reader announcing 366
                    // bars is noise. The summary carries the shape, and the CSV
                    // export next to the panel title carries the exact table.
                    role="img"
                    aria-label={t('admin.reports.series.summary', {
                        days: String(series.length),
                        peak: peak
                            ? t('admin.reports.series.tooltip', {
                                  date: peak.date,
                                  revenue: formatMoney(
                                      peak.revenue_minor,
                                      currency,
                                  ),
                                  count: String(peak.bookings),
                              })
                            : '',
                    })}
                    className="flex h-40 min-w-full items-end gap-[2px]"
                    onMouseLeave={() => setHovered(null)}
                >
                    {series.map((point) => {
                        // Percent of the peak; the stub keeps a zero day visible.
                        const height =
                            point.revenue_minor > 0
                                ? Math.max(
                                      2,
                                      Math.round(
                                          (point.revenue_minor / max) * 100,
                                      ),
                                  )
                                : 0;

                        return (
                            <div
                                key={point.date}
                                className="flex h-full min-w-[6px] flex-1 items-end"
                                onMouseEnter={() => setHovered(point)}
                            >
                                <div
                                    title={t('admin.reports.series.tooltip', {
                                        date: point.date,
                                        revenue: formatMoney(
                                            point.revenue_minor,
                                            currency,
                                        ),
                                        count: String(point.bookings),
                                    })}
                                    style={
                                        height > 0
                                            ? { height: `${height}%` }
                                            : undefined
                                    }
                                    className={cn(
                                        'w-full rounded-t-[4px] transition-colors',
                                        height > 0
                                            ? 'bg-primary/80 hover:bg-primary'
                                            : 'h-[2px] bg-border',
                                    )}
                                />
                            </div>
                        );
                    })}
                </div>
            </div>

            <div className="flex justify-between border-t border-border pt-2 text-xs text-muted-foreground">
                <span>{series[0]?.date}</span>
                <span>{series[series.length - 1]?.date}</span>
            </div>
        </figure>
    );
}
