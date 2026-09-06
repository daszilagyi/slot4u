import { ChevronDown } from 'lucide-react';
import { useState } from 'react';

import { useTranslations } from '@/lib/i18n';

/**
 * The questions people actually ask (SLO-205, docs/21 §2 row 9).
 *
 * ⚠️ Every answer is a checked fact, and the uncomfortable ones are in.
 *
 * The cancellation entry is the example: a booking cancelled more than 24 hours
 * out is commission-free, but a no-show is not (docs/10 §3). Leaving that out
 * would make the page read better and the first invoice read worse — and a
 * surprise line on an invoice costs more trust than a plain sentence here.
 *
 * The custom-domain answer is careful for the same reason: the subdomain is
 * immediate, but `feature_custom_domain` is off by default on the base plan, so
 * the honest phrasing is "on request" rather than "included".
 */

const QUESTIONS = ['cost', 'card', 'cancel', 'domain', 'data'] as const;

export default function Faq() {
    const t = useTranslations();

    // Rendered open on the server and closed on the client after hydration.
    //
    // ⚠️ Deliberate: an accordion whose answers live only in React state is an
    // accordion whose answers a crawler never sees, and the FAQ is one of the
    // few parts of this page with real search value. The <details> element does
    // the work — it needs no JavaScript at all to open.
    const [open, setOpen] = useState<string | null>(QUESTIONS[0]);

    return (
        <section className="border-b border-line bg-canvas">
            <div className="mx-auto w-full max-w-3xl px-4 py-16 sm:px-6 sm:py-20">
                <h2 className="text-2xl font-semibold tracking-tight text-balance sm:text-3xl">
                    {t('welcome.faq_title')}
                </h2>

                {/*
                    FAQPage structured data, rendered beside the questions rather
                    than in <head> — Google accepts it anywhere in the document,
                    and next to the list is where a question added above cannot
                    be forgotten here: both read the same constant.
                */}
                <script
                    type="application/ld+json"
                    dangerouslySetInnerHTML={{ __html: faqJsonLd(t) }}
                />

                <div className="mt-8 divide-y divide-line border-y border-line">
                    {QUESTIONS.map((key) => {
                        const isOpen = open === key;

                        return (
                            <details
                                key={key}
                                open={isOpen}
                                onToggle={(event) =>
                                    setOpen(
                                        event.currentTarget.open ? key : null,
                                    )
                                }
                                className="group py-4"
                            >
                                {/*
                                    A real <summary>: focusable, operable with
                                    Enter and Space, and announced as a disclosure
                                    — none of which a div with an onClick gets for
                                    free, and all of which somebody would have to
                                    reimplement badly.
                                */}
                                <summary className="flex cursor-pointer list-none items-center justify-between gap-4 font-medium focus-visible:ring-2 focus-visible:ring-ice focus-visible:outline-none">
                                    {t(`welcome.faq.${key}`)}
                                    <ChevronDown
                                        className="ease-brand size-5 shrink-0 text-ink-muted transition-transform duration-200 group-open:rotate-180"
                                        strokeWidth={1.75}
                                        aria-hidden
                                    />
                                </summary>
                                <p className="mt-3 text-ink-muted">
                                    {t(`welcome.faq.${key}_answer`)}
                                </p>
                            </details>
                        );
                    })}
                </div>
            </div>
        </section>
    );
}

/** The questions as schema.org `FAQPage` JSON. */
function faqJsonLd(t: (key: string) => string): string {
    return JSON.stringify({
        '@context': 'https://schema.org',
        '@type': 'FAQPage',
        mainEntity: QUESTIONS.map((key) => ({
            '@type': 'Question',
            name: t(`welcome.faq.${key}`),
            acceptedAnswer: {
                '@type': 'Answer',
                text: t(`welcome.faq.${key}_answer`),
            },
        })),
    });
}
