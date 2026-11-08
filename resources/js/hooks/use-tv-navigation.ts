import { useEffect } from 'react';

/**
 * Spatial navigation for smart-TV browsers / remotes: arrow keys move focus to the nearest
 * focusable element in that direction (rows, cards, buttons). Inputs keep their own arrows.
 */
export function useTvNavigation() {
    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            const dirs: Record<string, [number, number]> = { ArrowRight: [1, 0], ArrowLeft: [-1, 0], ArrowDown: [0, 1], ArrowUp: [0, -1] };
            const d = dirs[e.key];
            const active = document.activeElement as HTMLElement | null;
            if (!d || e.altKey || e.ctrlKey || e.metaKey) return;
            if (active?.closest('input,textarea,select,[role=slider],[role=menu],[role=listbox],[cmdk-root],[role=tablist]')) return;
            const all = Array.from(document.querySelectorAll<HTMLElement>('a[href],button:not([disabled]),[tabindex="0"]')).filter((el) => el.offsetParent !== null && el !== active && !el.closest('[aria-hidden=true]'));
            if (!active || active === document.body) { all[0]?.focus(); e.preventDefault(); return; }
            const a = active.getBoundingClientRect();
            const ax = a.left + a.width / 2, ay = a.top + a.height / 2;
            let best: HTMLElement | null = null, bestScore = Infinity;
            for (const el of all) {
                const r = el.getBoundingClientRect();
                const dx = r.left + r.width / 2 - ax, dy = r.top + r.height / 2 - ay;
                const along = dx * d[0] + dy * d[1];
                if (along <= 4) continue;
                const across = Math.abs(dx * d[1]) + Math.abs(dy * d[0]);
                const score = along + across * 2.5;
                if (score < bestScore) { bestScore = score; best = el; }
            }
            if (best) {
                e.preventDefault();
                best.focus();
                best.scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: 'smooth' });
            }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, []);
}
