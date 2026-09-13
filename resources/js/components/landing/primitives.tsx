import { Check } from 'lucide-react';
import type { CSSProperties, ReactNode } from 'react';

import type { LandingImage } from '@/components/landing/landingArt';
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
 * A decorative illustration (docs/24 §1): WebP with a PNG fallback, its real
 * size on the tag so the box is reserved before the file arrives, lazy because
 * every one of them is below the fold.
 *
 * `alt=""` and a hidden wrapper: the pictures illustrate what the text already
 * says, and a screen reader announcing a sloth in an armchair adds nothing.
 */
export function Illustration({
    image,
    className = '',
    imgClassName = '',
    eager = false,
}: {
    image: LandingImage;
    className?: string;
    imgClassName?: string;
    /** Above the fold: load it with the page, not on scroll. */
    eager?: boolean;
}) {
    return (
        <picture aria-hidden className={className}>
            <source type="image/webp" srcSet={image.webp} />
            <img
                src={image.png}
                alt=""
                width={image.width}
                height={image.height}
                loading={eager ? 'eager' : 'lazy'}
                decoding="async"
                draggable={false}
                className={`block h-auto max-w-full select-none ${imgClassName}`}
            />
        </picture>
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
