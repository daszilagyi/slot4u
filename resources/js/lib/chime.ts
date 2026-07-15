/**
 * Play a short two-note chime for a live notification (SLO-118). Synthesised with
 * the Web Audio API so no audio asset needs bundling. Silently no-ops when audio
 * is unavailable or still blocked by the browser's autoplay policy (before the
 * first user gesture) — the toast is the primary signal, the sound is a bonus.
 */
export function playChime(): void {
    if (typeof window === 'undefined') {
        return;
    }

    const AudioCtx =
        window.AudioContext ??
        (window as unknown as { webkitAudioContext?: typeof AudioContext })
            .webkitAudioContext;

    if (!AudioCtx) {
        return;
    }

    try {
        const ctx = new AudioCtx();
        const now = ctx.currentTime;
        const gain = ctx.createGain();
        gain.connect(ctx.destination);
        gain.gain.setValueAtTime(0.0001, now);
        gain.gain.exponentialRampToValueAtTime(0.12, now + 0.02);
        gain.gain.exponentialRampToValueAtTime(0.0001, now + 0.4);

        const osc = ctx.createOscillator();
        osc.type = 'sine';
        osc.frequency.setValueAtTime(660, now);
        osc.frequency.setValueAtTime(990, now + 0.12);
        osc.connect(gain);
        osc.start(now);
        osc.stop(now + 0.42);
        osc.onended = () => void ctx.close();
    } catch {
        // Autoplay blocked or audio unavailable — the toast still fires.
    }
}
