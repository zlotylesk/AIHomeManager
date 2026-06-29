import { TOAST_TIMEOUT_MS } from '../util.js';

// The error/info banners live in base.html.twig (global ids), not inside the
// Stimulus controller element, so these helpers are controller-independent and
// can be imported directly by any Series view module.
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

export function showTraktConnectPrompt() {
    const banner = document.getElementById('error-banner');
    if (!banner) return;
    banner.innerHTML = 'Connect your Trakt account first: <a href="/auth/trakt">Connect Trakt</a>';
    banner.classList.remove('hidden');
}
