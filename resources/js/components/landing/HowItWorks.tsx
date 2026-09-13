import {
    ILLUS_CALENDAR,
    ILLUS_LAPTOP,
    SLOTH_CHEER,
} from '@/components/landing/landingArt';
import {
    HandNote,
    Illustration,
    Reveal,
} from '@/components/landing/primitives';
import { useTranslations } from '@/lib/i18n';

/**
 * "Hogyan működik?" (SLO-229) — three steps, each a soft card with its number,
 * a line, and its illustration along the bottom (SLO-236, docs/24 §2.1).
 *
 * The cards stretch to one height and the picture is pushed to the bottom, so
 * three pictures of different proportions still end on the same line. The
 * cheering sloth stays inside its card — the design lets it spill over the
 * corner, which falls apart on a phone.
 */
export default function HowItWorks() {
    const t = useTranslations();

    const steps = [
        { key: 'register', image: ILLUS_LAPTOP },
        { key: 'setup', image: ILLUS_CALENDAR },
        { key: 'bookings', image: SLOTH_CHEER },
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

                <ol className="mt-9 grid items-stretch gap-6 md:grid-cols-3">
                    {steps.map(({ key, image }, index) => (
                        <li key={key} className="h-full">
                            <Reveal
                                index={index}
                                className="flex h-full flex-col rounded-[20px] bg-canvas px-7 pt-7 pb-6"
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
                                <Illustration
                                    image={image}
                                    className="mt-auto flex justify-center pt-5"
                                    imgClassName="max-h-[120px] w-auto object-contain md:max-h-[150px]"
                                />
                            </Reveal>
                        </li>
                    ))}
                </ol>
            </div>
        </section>
    );
}
