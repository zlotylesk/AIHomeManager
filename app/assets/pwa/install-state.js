/**
 * Pure decision helpers for the A2HS (add-to-home-screen) install affordance
 * (HMAI-345). No DOM lives here, so the state machine is Vitest-covered;
 * `install.js` wires it to the page.
 */

/**
 * The app is already running in an installed (standalone) window. In that mode
 * the browser fires no `beforeinstallprompt` and our own banner would be noise,
 * so we suppress it. `display-mode: standalone` covers Android/desktop;
 * `navigator.standalone` is the iOS Safari signal.
 *
 * @param {{displayStandalone?: boolean, iosStandalone?: boolean}} [signals]
 * @returns {boolean}
 */
export function isAppInstalled({ displayStandalone = false, iosStandalone = false } = {}) {
    return displayStandalone || iosStandalone;
}

/**
 * Whether to show the custom install banner: we have a deferred prompt to fire,
 * the app is not already installed, and the user has not dismissed the banner
 * this session. All three must hold — a captured prompt alone is not enough.
 *
 * @param {{hasDeferredPrompt?: boolean, installed?: boolean, dismissed?: boolean}} [state]
 * @returns {boolean}
 */
export function shouldOfferInstall({ hasDeferredPrompt = false, installed = false, dismissed = false } = {}) {
    return hasDeferredPrompt && !installed && !dismissed;
}
