import { useEffect, useRef, useState } from 'react';
import { reducedMotion } from '@/lib/device-class';

/**
 * Movexa intro: original logo animation + short local sound, ≤ 3 s, once per session after choosing a profile.
 * Skippable by any tap/key; the page loads behind it. Muted if autoplay with sound is blocked; static & silent
 * with prefers-reduced-motion. Can be turned off per profile.
 */
export function MovexaIntro({ enabled, profileId }: { enabled: boolean; profileId: number }) {
    const key = `mx-intro-${profileId}`;
    const [show, setShow] = useState(() => {
        try { return enabled && !sessionStorage.getItem(key); } catch { return false; }
    });
    const audio = useRef<HTMLAudioElement | null>(null);
    useEffect(() => {
        if (!show) return;
        try { sessionStorage.setItem(key, '1'); } catch { /* private mode */ }
        const still = reducedMotion();
        if (!still) {
            audio.current = new Audio('/intro/movexa-intro.m4a');
            audio.current.volume = 0.6;
            audio.current.play().catch(() => { /* autoplay with sound blocked → silent */ });
        }
        const done = () => { audio.current?.pause(); setShow(false); };
        const id = setTimeout(done, still ? 1200 : 2900);
        window.addEventListener('keydown', done, { once: true });
        window.addEventListener('pointerdown', done, { once: true });
        return () => { clearTimeout(id); window.removeEventListener('keydown', done); window.removeEventListener('pointerdown', done); audio.current?.pause(); };
    }, [show, key]);
    if (!show) return null;
    const still = reducedMotion();
    return (
        <div data-testid="intro" className="fixed inset-0 z-[100] flex items-center justify-center bg-[#07070a]" role="presentation">
            <svg viewBox="0 0 512 512" className={still ? 'size-40' : 'mx-intro-logo size-48'} aria-label="Movexa">
                <path d="M112 400V112h64l80 120 80-120h64v288h-64V224l-80 112-80-112v176z" fill={still ? '#e50914' : 'none'} stroke="#e50914" strokeWidth="14" strokeLinejoin="round" />
                {!still && <path d="M112 400V112h64l80 120 80-120h64v288h-64V224l-80 112-80-112v176z" fill="#e50914" opacity="0"><animate attributeName="opacity" from="0" to="1" begin="1.1s" dur=".6s" fill="freeze" /></path>}
            </svg>
        </div>
    );
}
