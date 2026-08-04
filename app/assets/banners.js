import { TOAST_TIMEOUT_MS } from './util.js';

// The error/info banners live in base.html.twig under global ids, so they are
// not owned by any one panel — which is why these helpers sit here rather than
// under series/, where they used to live while five byte-identical copies of
// the same two functions also sat in the legacy panels.
//
// The legacy track reaches them through `window` (see legacy-globals.js); the
// Encore modules import them directly.

export function showError(msg) {
    const banner = document.getElementById('error-banner');
    if (!banner) return;
    banner.textContent = msg;
    banner.classList.remove('hidden');
    setTimeout(() => banner.classList.add('hidden'), TOAST_TIMEOUT_MS);
}

export function hideError() {
    const banner = document.getElementById('error-banner');
    if (banner) banner.classList.add('hidden');
}

export function showInfo(msg) {
    const banner = document.getElementById('info-banner');
    if (!banner) return;
    banner.textContent = msg;
    banner.classList.remove('hidden');
    setTimeout(() => banner.classList.add('hidden'), TOAST_TIMEOUT_MS);
}
