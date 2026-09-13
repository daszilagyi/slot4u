import { motion, useInView } from 'framer-motion';
import { useEffect, useRef, useState, useSyncExternalStore } from 'react';

import {
    SLOTH_BODY,
    SLOTH_CAPE,
    SLOTH_EYES_CLOSED,
    SLOTH_FULL,
    type SlothImage,
} from '@/components/landing/heroSlothAssets';
import { useTranslations } from '@/lib/i18n';
import { useReducedMotion } from '@/lib/motion';

/** Where the cape hangs from: canvas point (490, 360) of 1024 × 920 (docs/23 §2). */
const CAPE_ORIGIN = '47.9% 39.1%';

const EASE_BRAND = [0.2, 0.8, 0.2, 1] as const;

/** One wink is the closed-eye layer shown for this long (docs/23 §3). */
const WINK_MS = 140;
const DOUBLE_WINK_GAP_MS = 120;
const FIRST_WINK_AFTER_ENTRANCE_MS = 1800;

/** 5–11 s, drawn afresh each time, so the rhythm never becomes a beat. */
function nextWinkDelay(): number {
    return 5000 + Math.random() * 6000;
}

type Props = {
    /** Size and position from outside — the box keeps the 1024 × 920 ratio. */
    className?: string;
    /** In the hero: fetch the placeholder at high priority (it is the LCP image). */
    priority?: boolean;
};

/**
 * The flying hero sloth (SLO-232, docs/23): three stacked layers — cape, body,
 * closed eye — on one canvas, so they line up with no offsets. The whole figure
 * floats, the cape sways from the shoulder a beat out of phase, and now and
 * then the sloth winks.
 *
 * ⚠️ The server and a visitor who asked for less motion see only the composite
 * image. The layers mount after hydration, fly in over it, and only then does
 * the composite fade — so there is no empty frame, no layout jump, and the LCP
 * element is the image that was there from the first byte.
 *
 * Everything stops when the hero is scrolled away or the tab is hidden: a loop
 * nobody can see is CPU somebody pays for.
 */
export default function HeroSloth({ className = '', priority = false }: Props) {
    const t = useTranslations();
    const reduced = useReducedMotion();

    const ref = useRef<HTMLDivElement>(null);
    const inView = useInView(ref, { amount: 0.2 });
    const visible = useDocumentVisible();

    // Client-only from here on: the layers never exist in server-rendered HTML.
    const mounted = useIsClient();
    // All three layers decoded — otherwise the figure would fly in with its cape
    // arriving a moment later.
    const [ready, setReady] = useState(false);
    // The entrance has landed; the composite underneath can go.
    const [arrived, setArrived] = useState(false);
    const [winking, setWinking] = useState(false);

    const animated = mounted && ready && !reduced;
    const active = animated && inView && visible;

    useEffect(() => {
        if (!mounted || reduced) {
            return;
        }

        let cancelled = false;
        const layers = [SLOTH_CAPE, SLOTH_BODY, SLOTH_EYES_CLOSED].map(
            ({ webp }) => {
                const image = new Image();
                image.src = webp;

                // `decode()` rejects on a browser that cannot decode WebP; the
                // <picture> falls back to PNG there, so "failed" still means "go".
                return image.decode().catch(() => undefined);
            },
        );

        Promise.all(layers).then(() => {
            if (!cancelled) {
                setReady(true);
            }
        });

        return () => {
            cancelled = true;
        };
    }, [mounted, reduced]);

    // The wink: a chain of timeouts rather than an interval, so every gap is
    // drawn anew. It only runs while the sloth can actually be seen.
    useEffect(() => {
        if (!active || !arrived) {
            return;
        }

        const timers: number[] = [];
        const later = (fn: () => void, ms: number) => {
            timers.push(window.setTimeout(fn, ms));
        };

        const wink = (then: () => void) => {
            setWinking(true);
            later(() => {
                setWinking(false);
                then();
            }, WINK_MS);
        };

        const schedule = (delay: number) => {
            later(() => {
                // One in five is a double wink.
                const double = Math.random() < 0.2;

                wink(() => {
                    if (double) {
                        later(
                            () => wink(() => schedule(nextWinkDelay())),
                            DOUBLE_WINK_GAP_MS,
                        );
                    } else {
                        schedule(nextWinkDelay());
                    }
                });
            }, delay);
        };

        schedule(FIRST_WINK_AFTER_ENTRANCE_MS);

        return () => {
            timers.forEach((timer) => window.clearTimeout(timer));
            setWinking(false);
        };
    }, [active, arrived]);

    return (
        <div ref={ref} className={`relative aspect-[1024/920] ${className}`}>
            <picture>
                <source type="image/webp" srcSet={SLOTH_FULL.webp} />
                <img
                    src={SLOTH_FULL.png}
                    alt={t('welcome.hero.sloth_alt')}
                    width={1024}
                    height={920}
                    decoding="async"
                    fetchPriority={priority ? 'high' : undefined}
                    // Faded, never removed: the box keeps its size and the LCP
                    // element stays the one the browser already painted.
                    className={`absolute inset-0 h-full w-full transition-opacity duration-150 ${
                        animated && arrived ? 'opacity-0' : 'opacity-100'
                    }`}
                />
            </picture>

            {animated && (
                <motion.div
                    aria-hidden
                    data-hero-sloth-layers
                    className="absolute inset-0"
                    initial={{ x: 40, y: 24, opacity: 0 }}
                    animate={{ x: 0, y: 0, opacity: 1 }}
                    transition={{ duration: 0.6, ease: EASE_BRAND }}
                    onAnimationComplete={() => setArrived(true)}
                >
                    {/* The float lives on its own wrapper, so it never fights
                        the entrance for the same `y`. */}
                    <motion.div
                        className="absolute inset-0"
                        animate={
                            active && arrived ? { y: [0, -6, 0] } : { y: 0 }
                        }
                        transition={
                            active && arrived
                                ? {
                                      duration: 4,
                                      ease: 'easeInOut',
                                      repeat: Infinity,
                                  }
                                : { duration: 0.4 }
                        }
                    >
                        <motion.div
                            className="absolute inset-0"
                            style={{ transformOrigin: CAPE_ORIGIN }}
                            animate={
                                active && arrived
                                    ? {
                                          rotate: [0, -2.5, 0, 2.5, 0],
                                          skewX: [0, 1.5, 0, -1.5, 0],
                                      }
                                    : { rotate: 0, skewX: 0 }
                            }
                            transition={
                                active && arrived
                                    ? {
                                          duration: 3.2,
                                          ease: 'easeInOut',
                                          repeat: Infinity,
                                          delay: 0.4,
                                      }
                                    : { duration: 0.4 }
                            }
                        >
                            <Layer image={SLOTH_CAPE} />
                        </motion.div>

                        <Layer image={SLOTH_BODY} />

                        <motion.div
                            className="absolute inset-0"
                            initial={{ opacity: 0 }}
                            animate={{ opacity: winking ? 1 : 0 }}
                            transition={{ duration: 0.06 }}
                        >
                            <Layer image={SLOTH_EYES_CLOSED} />
                        </motion.div>
                    </motion.div>
                </motion.div>
            )}
        </div>
    );
}

/** One canvas-sized layer. `alt=""`: the composite carries the description. */
function Layer({ image }: { image: SlothImage }) {
    return (
        <picture>
            <source type="image/webp" srcSet={image.webp} />
            <img
                src={image.png}
                alt=""
                width={1024}
                height={920}
                decoding="async"
                draggable={false}
                className="absolute inset-0 h-full w-full select-none"
            />
        </picture>
    );
}

/** Whether the tab is in front. False while hidden, so loops can stop. */
function useDocumentVisible(): boolean {
    const [visible, setVisible] = useState(true);

    useEffect(() => {
        const update = () => setVisible(document.visibilityState !== 'hidden');

        update();
        document.addEventListener('visibilitychange', update);

        return () => document.removeEventListener('visibilitychange', update);
    }, []);

    return visible;
}

const noSubscription = () => () => {};

/**
 * False on the server and during hydration, true once running in the browser —
 * without a state update inside an effect, and without a hydration mismatch.
 */
function useIsClient(): boolean {
    return useSyncExternalStore(
        noSubscription,
        () => true,
        () => false,
    );
}
