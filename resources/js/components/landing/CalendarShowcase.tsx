import {
    BarChart3,
    Bookmark,
    Briefcase,
    Calendar,
    Settings,
    User,
    Users,
} from 'lucide-react';

import { Art, CheckDot, Reveal } from '@/components/landing/primitives';
import { BRAND_NAME } from '@/lib/brand';
import { useTranslations, useTranslationTree } from '@/lib/i18n';

const NAV_ICONS = [
    Calendar,
    Bookmark,
    Users,
    Briefcase,
    User,
    BarChart3,
    Settings,
];
const HOURS = ['9:00', '10:00', '11:00', '12:00', '13:00'];

/**
 * A booking on the mock week: which day column it starts in (0-based, may be
 * fractional), which hour row, and which palette token paints it.
 */
const EVENTS = [
    { key: 'massage', day: 0, row: 0, tone: 'brand', time: '09:00 – 10:00' },
    { key: 'consult', day: 0, row: 1, tone: 'ok', time: '10:00 – 11:00' },
    { key: 'training', day: 1.55, row: 1, tone: 'warn', time: '10:00 – 11:00' },
    { key: 'yoga', day: 3.2, row: 2, tone: 'teal', time: '11:00 – 12:00' },
] as const;

const TONES: Record<(typeof EVENTS)[number]['tone'], string> = {
    brand: 'border-brand bg-brand-200',
    ok: 'border-ok bg-ok/15',
    warn: 'border-warn bg-warn/15',
    teal: 'border-teal bg-teal-100',
};

const ROW_HEIGHT = 44;

/**
 * "Átlátható naptár" (SLO-229) — a drawn week of the admin calendar beside four
 * things it really does.
 *
 * ⚠️ A drawing, not a screenshot, and labelled as one to assistive tech: the
 * names and times are illustration. The checklist beside it is the claim, and
 * each line is a shipped feature (day/week views, per-staff columns, drag to
 * move, realtime updates).
 */
export default function CalendarShowcase({ art }: { art: string | null }) {
    const t = useTranslations();
    const tree = useTranslationTree();

    const days = tree<string[]>('welcome.calendar.mock.days') ?? [];
    const nav = tree<string[]>('welcome.calendar.mock.nav') ?? [];

    return (
        <section className="bg-canvas">
            {/* The peeking sloth hangs over the section's bottom edge, so the
                padding only goes when it is there to fill it. */}
            <div
                className={`mx-auto grid w-full max-w-[1440px] items-center gap-12 px-4 pt-14 pb-16 sm:px-8 lg:grid-cols-[1.25fr_0.85fr] lg:px-14 ${
                    art !== null ? 'lg:pb-0' : ''
                }`}
            >
                <Reveal>
                    <div
                        role="img"
                        aria-label={
                            t('welcome.calendar.title_lead') +
                            ' ' +
                            t('welcome.calendar.title_tail')
                        }
                        className="grid overflow-hidden rounded-[22px] bg-navy shadow-[0_30px_60px_rgba(15,37,71,.2)] sm:min-h-[440px] sm:grid-cols-[150px_1fr]"
                    >
                        <div className="hidden content-start gap-2 px-4 py-5 text-xs font-bold text-mist sm:grid">
                            <p className="mb-3.5 flex items-center gap-2 text-base font-black text-white">
                                <span className="inline-block size-[22px] rounded-full bg-white" />
                                {BRAND_NAME}
                            </p>
                            {nav.map((label, index) => {
                                const Icon = NAV_ICONS[index] ?? Calendar;

                                return (
                                    <p
                                        key={label}
                                        className={`flex items-center gap-2 rounded-lg px-2.5 py-2 ${
                                            index === 0
                                                ? 'bg-brand text-white'
                                                : ''
                                        }`}
                                    >
                                        <Icon className="size-3.5" />
                                        {label}
                                    </p>
                                );
                            })}
                        </div>

                        <div className="grid grid-rows-[auto_auto_1fr] gap-3 bg-white px-5 py-4">
                            <div className="flex items-center gap-2.5">
                                <strong className="text-[15px] text-navy">
                                    {t('welcome.calendar.mock.month')}
                                </strong>
                                <span className="text-xs text-slate">▾</span>
                                <span className="ml-auto flex gap-1.5 text-[10px] font-bold">
                                    <span className="rounded-md border border-line px-2 py-0.5 text-ink-muted">
                                        {t('welcome.calendar.mock.week')}
                                    </span>
                                    <span className="rounded-md border border-line px-2 py-0.5 text-ink-muted">
                                        {t('welcome.calendar.mock.filter')}
                                    </span>
                                    <span className="rounded-md bg-navy px-2 py-0.5 text-white">
                                        {t('welcome.calendar.mock.new')}
                                    </span>
                                </span>
                            </div>

                            <div className="grid grid-cols-[36px_repeat(5,1fr)] text-center text-[10px] font-bold text-slate">
                                <span />
                                {days.map((day) => (
                                    <span key={day}>{day}</span>
                                ))}
                            </div>

                            <div
                                className="relative grid grid-cols-[36px_repeat(5,1fr)] border-t border-hairline text-[10px] text-slate"
                                style={{ gridAutoRows: `${ROW_HEIGHT}px` }}
                            >
                                {HOURS.flatMap((hour) => [
                                    <span
                                        key={hour}
                                        className="border-b border-hairline pt-1"
                                    >
                                        {hour}
                                    </span>,
                                    ...[0, 1, 2, 3, 4].map((col) => (
                                        <span
                                            key={`${hour}-${col}`}
                                            className="border-b border-l border-hairline"
                                        />
                                    )),
                                ])}

                                {EVENTS.map((event) => (
                                    <div
                                        key={event.key}
                                        className={`absolute rounded-md border-l-[3px] px-2 py-1 leading-snug font-extrabold text-navy ${TONES[event.tone]}`}
                                        style={{
                                            left: `calc(36px + (100% - 36px) * ${event.day} / 5 + 4px)`,
                                            top: `${event.row * (ROW_HEIGHT + 2) + 4}px`,
                                            width: 'calc((100% - 36px) / 5 * 1.3)',
                                        }}
                                    >
                                        {t(
                                            `welcome.calendar.mock.${event.key}`,
                                        )}
                                        <span className="hidden font-semibold sm:block">
                                            {event.time}
                                        </span>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </div>
                </Reveal>

                <div>
                    <h2 className="mb-4 text-3xl leading-[1.15] font-black text-navy sm:text-[34px]">
                        {t('welcome.calendar.title_lead')}
                        <br />
                        {t('welcome.calendar.title_tail')}
                    </h2>
                    <p className="mb-6 text-base leading-relaxed text-ink-muted">
                        {t('welcome.calendar.lead')}
                    </p>
                    <ul className="grid gap-3 text-[15px] font-bold text-navy">
                        {(
                            [
                                'check_views',
                                'check_resources',
                                'check_drag',
                                'check_realtime',
                            ] as const
                        ).map((key) => (
                            <li key={key} className="flex items-center gap-3">
                                <CheckDot />
                                {t(`welcome.calendar.${key}`)}
                            </li>
                        ))}
                    </ul>

                    {art !== null && (
                        <div className="relative mt-5 hidden items-end justify-end gap-3 lg:-mb-8 lg:flex">
                            <p
                                className="mb-[90px] rounded-full bg-white px-4 py-2 text-[13px] font-extrabold text-navy shadow-[0_8px_20px_rgba(0,0,0,.15)]"
                                aria-hidden
                            >
                                {t('welcome.calendar.cheer')}
                            </p>
                            <div className="relative z-10 h-40 w-[200px]">
                                <Art src={art} />
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </section>
    );
}
