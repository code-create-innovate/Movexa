// Capability heuristics used to turn off heavy visuals (poster wall motion, ambient glow, previews).
export const isTv = () => /Tizen|Web0S|webOS|SMART-TV|SmartTV|BRAVIA|Android TV|AFT\w|CrKey|HbbTV|VIDAA/i.test(navigator.userAgent);
export const isLowEnd = () => (navigator.hardwareConcurrency ?? 8) <= 2 || ((navigator as any).deviceMemory ?? 8) <= 2;
export const reducedMotion = () => typeof matchMedia !== 'undefined' && matchMedia('(prefers-reduced-motion: reduce)').matches;
export const heavyVisualsOk = () => !isTv() && !isLowEnd();

/** Page tint (dominant color of the current title). Animated via the registered CSS property --page-tint. */
export function setTint(color?: string | null) {
    if (color) document.documentElement.style.setProperty('--page-tint', color);
}
