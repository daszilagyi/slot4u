import { useInView } from 'framer-motion';
import { useRef } from 'react';
import type { CSSProperties } from 'react';

import { useDocumentVisible } from '@/lib/motion';

/*
 * The clouds behind the hero sloth (SLO-234, docs/23 §3b): white shapes drifting
 * right to left in two parallax layers, so the sloth reads as flying while it
 * only floats in place.
 *
 * Inline SVG and a CSS transform, nothing else — no image request, no JS per
 * frame. Each layer is a strip holding the same 1920px drawing twice; it slides
 * by exactly half its width and starts over, at which point the second copy
 * stands where the first began. That is the whole seamless loop, and why the two
 * copies must never differ.
 *
 * ⚠️ The drift is `motion-safe:` only: for anyone who asked for less motion the
 * clouds stand still but stay visible, so the picture does not lose its sky.
 */

/** The one cloud shape (from Daniel's cloud.svg): four ellipses and a base. */
function CloudShape() {
    return (
        <symbol id="hero-cloud" viewBox="0 0 200 80">
            <ellipse cx="40" cy="56" rx="40" ry="22" />
            <ellipse cx="82" cy="42" rx="34" ry="30" />
            <ellipse cx="124" cy="48" rx="38" ry="28" />
            <ellipse cx="160" cy="58" rx="36" ry="20" />
            <rect x="20" y="52" width="160" height="26" rx="13" />
        </symbol>
    );
}

type Cloud = { x: number; y: number; width: number };

/**
 * One copy of a strip: a fixed 1920 × 640 px band anchored to the hero's bottom
 * edge — a pixel size rather than a scaled one, so a phone and a desktop see the
 * same clouds at the same size, only more or less of them.
 *
 * ⚠️ Every cloud stays wholly inside 0…1920. One that crossed the edge would be
 * clipped by its own copy's box and show a straight cut where the copies meet.
 * 1920 is also the widest screen the loop covers without a gap on the right.
 */
const BOX = { width: 1920, height: 640 };

const BACK: Cloud[] = [
    { x: 60, y: 90, width: 170 },
    { x: 520, y: 40, width: 140 },
    { x: 980, y: 150, width: 200 },
    { x: 1460, y: 70, width: 160 },
];

/** The big ones rest along the hero's bottom edge, over the wave's top. */
const FRONT: Cloud[] = [
    { x: 20, y: 470, width: 420 },
    { x: 640, y: 500, width: 340 },
    { x: 1260, y: 450, width: 460 },
];

function Strip({
    clouds,
    seconds,
    opacity,
    running,
    className = 'flex',
}: {
    clouds: Cloud[];
    seconds: number;
    /** Opacity classes — phones get more, where the strip shows a narrow slice. */
    opacity: string;
    running: boolean;
    className?: string;
}) {
    const drawing = (copy: number) => (
        <svg
            key={copy}
            viewBox={`0 0 ${BOX.width} ${BOX.height}`}
            width={BOX.width}
            height={BOX.height}
            // The <use> clones inherit this, and it resolves to the strip's
            // white — so a layer's colour is one class away.
            fill="currentColor"
            className="flex-none"
        >
            {clouds.map((cloud) => (
                <use
                    key={`${cloud.x}-${cloud.y}`}
                    href="#hero-cloud"
                    x={cloud.x}
                    y={cloud.y}
                    width={cloud.width}
                    height={(cloud.width * 80) / 200}
                />
            ))}
        </svg>
    );

    return (
        <div
            data-cloud-strip
            className={`absolute bottom-0 left-0 w-max text-white ${opacity} motion-safe:animate-[hero-cloud-drift_var(--drift)_linear_infinite] ${className}`}
            style={
                {
                    '--drift': `${seconds}s`,
                    animationPlayState: running ? 'running' : 'paused',
                } as CSSProperties
            }
        >
            {drawing(0)}
            {drawing(1)}
        </div>
    );
}

export default function HeroClouds() {
    const ref = useRef<HTMLDivElement>(null);
    const inView = useInView(ref, { amount: 0.2 });
    const visible = useDocumentVisible();

    // Paused, not removed: the clouds stay where they are and pick up from
    // there, rather than jumping when the hero comes back into view.
    const running = inView && visible;

    return (
        <div
            ref={ref}
            aria-hidden
            data-hero-clouds
            className="pointer-events-none absolute inset-0 z-0 overflow-hidden"
        >
            <svg className="absolute size-0" focusable="false">
                <defs>
                    <CloudShape />
                </defs>
            </svg>

            <Strip
                clouds={BACK}
                seconds={70}
                // docs/23 §3b hides this layer on phones and keeps both faint;
                // on production that left the sky nearly empty on a phone,
                // where the strip shows a narrow slice at a time (2026-09-13).
                // Phones get both layers and more opacity; desktop keeps the doc.
                opacity="opacity-[0.16] md:opacity-10"
                running={running}
            />
            <Strip
                clouds={FRONT}
                seconds={38}
                opacity="opacity-20 md:opacity-[0.16]"
                running={running}
            />
        </div>
    );
}
