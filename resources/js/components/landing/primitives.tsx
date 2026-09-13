import { Check } from 'lucide-react';
import type { CSSProperties, ReactNode } from 'react';

import { stagger, useInView, useReducedMotion } from '@/lib/motion';

/*
 * The small pieces every section of the "Slot4u Landing" design repeats
 * (SLO-229). Kept together so a section cannot drift into its own idea of what
 * the yellow button or a handwritten aside looks like.
 */

/**
 * The yellow call to action. Radius, weight and hover come from the design; the
 * size is the caller's, because the header pill and the hero button differ.
 */
export const highlightButton =
    'inline-flex items-center justify-center gap-2.5 bg-highlight font-extrabold text-highlight-foreground transition-colors duration-200 hover:bg-highlight-hover focus-visible:ring-2 focus-visible:ring-brand focus-visible:ring-offset-2 focus-visible:outline-none';

/**
 * A handwritten aside ("Több idő a fontos dolgokra ✔").
 *
 * `aria-hidden`: every one of them repeats, in a friendlier voice, something
 * the section already says in its heading or lead — read aloud it is noise.
 */
export function HandNote({
    lead,
    tail,
    mark,
    className = '',
}: {
    lead: string;
    tail: string;
    /** The little flourish after the second line, in its own colour. */
    mark?: ReactNode;
    className?: string;
}) {
    return (
        <p
            aria-hidden
            className={`font-hand leading-none font-bold select-none ${className}`}
        >
            {lead}
            <br />
            {tail}
            {mark !== undefined && <> {mark}</>}
        </p>
    );
}

/** The yellow dot with a tick that leads a benefit line. */
export function CheckDot({ className = '' }: { className?: string }) {
    return (
        <span
            aria-hidden
            className={`grid size-[26px] shrink-0 place-items-center rounded-full bg-highlight text-highlight-foreground ${className}`}
        >
            <Check className="size-3.5" strokeWidth={3} />
        </span>
    );
}

/**
 * One of the sloth illustrations, or nothing.
 *
 * The server tells the page which files exist (MarketingArt); a slot without
 * one renders nothing at all, so no section ever ships a broken-image icon or
 * an empty frame while the artwork is still being made (SLO-202).
 */
export function Art({
    src,
    className = '',
    eager = false,
}: {
    src: string | null | undefined;
    className?: string;
    /** Only the hero's: everything else is below the fold. */
    eager?: boolean;
}) {
    if (!src) {
        return null;
    }

    return (
        <img
            src={src}
            // Decorative: the mascot illustrates, it never carries information.
            alt=""
            loading={eager ? 'eager' : 'lazy'}
            decoding="async"
            className={`block h-full w-full object-contain ${className}`}
        />
    );
}

/**
 * Fade-and-rise on scroll, staggered by index.
 *
 * Revealed immediately for anyone who asked for less motion — "no animation"
 * must mean "already there", never "never appears".
 */
export function Reveal({
    index = 0,
    className = '',
    style,
    children,
}: {
    index?: number;
    className?: string;
    style?: CSSProperties;
    children: ReactNode;
}) {
    const reduced = useReducedMotion();
    const [ref, seen] = useInView<HTMLDivElement>();
    const revealed = seen || reduced;

    return (
        <div
            ref={ref}
            className={`transition-all duration-500 ease-brand ${className}`}
            style={{
                ...style,
                transitionDelay: `${stagger(index, 80, reduced)}ms`,
                opacity: revealed ? 1 : 0,
                transform: revealed
                    ? style?.transform
                    : `translateY(12px) ${style?.transform ?? ''}`,
            }}
        >
            {children}
        </div>
    );
}
