/**
 * Custom Add-to-Home-Screen flow (HMAI-345).
 *
 * The browser fires `beforeinstallprompt` when the app is installable; we
 * intercept it (so no default mini-infobar shows), stash the event, and offer
 * our own banner. Clicking "Zainstaluj" replays the stashed event — the OS
 * install dialog can only be triggered from that captured event, never minted
 * on our own. Dismissal is remembered for the session so the banner does not
 * nag on every navigation.
 *
 * The decision logic lives in the pure, Vitest-covered {@link ./install-state.js}.
 */
import { isAppInstalled, shouldOfferInstall } from './install-state.js';

const DISMISS_KEY = 'pwa-install-dismissed';

/** @type {Event & {prompt: () => void, userChoice: Promise<unknown>} | null} */
let deferredPrompt = null;

function installed() {
    return isAppInstalled({
        displayStandalone: window.matchMedia('(display-mode: standalone)').matches,
        iosStandalone: window.navigator.standalone === true,
    });
}

function dismissed() {
    try {
        return window.sessionStorage.getItem(DISMISS_KEY) === '1';
    } catch {
        return false;
    }
}

function rememberDismissed() {
    try {
        window.sessionStorage.setItem(DISMISS_KEY, '1');
    } catch {
        // Private mode may forbid storage — the banner simply reappears next load.
    }
}

function buildBanner(onAccept, onDismiss) {
    const banner = document.createElement('div');
    banner.id = 'pwa-install-banner';
    banner.className = 'pwa-install-banner hidden';

    const text = document.createElement('span');
    text.className = 'pwa-install-text';
    text.textContent = 'Zainstaluj AIHomeManager na urządzeniu';

    const accept = document.createElement('button');
    accept.type = 'button';
    accept.className = 'btn btn-primary pwa-install-accept';
    accept.textContent = 'Zainstaluj';
    accept.addEventListener('click', onAccept);

    const dismiss = document.createElement('button');
    dismiss.type = 'button';
    dismiss.className = 'pwa-install-dismiss';
    dismiss.setAttribute('aria-label', 'Zamknij');
    dismiss.textContent = '✕';
    dismiss.addEventListener('click', onDismiss);

    banner.append(text, accept, dismiss);
    document.body.appendChild(banner);

    return banner;
}

export function initInstallPrompt() {
    if (typeof window === 'undefined' || !('addEventListener' in window)) {
        return;
    }

    let banner = null;

    const hide = () => {
        if (banner) {
            banner.classList.add('hidden');
        }
    };

    const accept = async () => {
        hide();
        if (!deferredPrompt) {
            return;
        }
        deferredPrompt.prompt();
        try {
            await deferredPrompt.userChoice;
        } catch {
            // The user closing the OS dialog is not an error worth surfacing.
        }
        deferredPrompt = null;
    };

    const dismiss = () => {
        hide();
        rememberDismissed();
    };

    const maybeShow = () => {
        if (!shouldOfferInstall({ hasDeferredPrompt: null !== deferredPrompt, installed: installed(), dismissed: dismissed() })) {
            return;
        }
        banner ??= buildBanner(accept, dismiss);
        banner.classList.remove('hidden');
    };

    window.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault();
        deferredPrompt = event;
        maybeShow();
    });

    window.addEventListener('appinstalled', () => {
        deferredPrompt = null;
        hide();
    });
}
