/*
 * Contextual push-notification opt-in for the PWA (HMAI-349).
 *
 * The WebPush/VAPID backend — the public-key endpoint, the subscription store and
 * the delivery channel — belongs to the Notifications module (HMAI-275/280/283).
 * This is purely the PWA-side consumer: it does NOT build a second backend. The
 * actual subscribe/unsubscribe calls are reused verbatim from
 * assets/notifications/push.js (which the settings page already drives); here we
 * add only the *contextual* soft-ask banner (shown on a return visit, never on
 * first entry) and a silent re-subscribe when permission is already granted but
 * the subscription went missing (a new Service Worker, a cleared push store).
 *
 * The permanent, explicit control stays on the /notifications settings page; this
 * banner is a one-shot nudge, dismissible for good.
 *
 * The decision logic lives in the pure, Vitest-covered ./push-state.js.
 */
import { currentSubscription, isPushSupported, subscribeToPush } from '../notifications/push.js';
import { nextVisitCount, shouldOfferPushPrompt } from './push-state.js';

const VISITS_KEY = 'pwa-push-visits';
const DISMISS_KEY = 'pwa-push-dismissed';

function permissionState() {
    return 'Notification' in window ? Notification.permission : 'denied';
}

function readStore(key) {
    try {
        return window.localStorage.getItem(key);
    } catch {
        return null;
    }
}

function writeStore(key, value) {
    try {
        window.localStorage.setItem(key, value);
    } catch {
        // Private mode may forbid storage — the nudge simply behaves as if fresh.
    }
}

// Re-establish the subscription when the browser still holds permission but no
// live subscription exists. The register endpoint is idempotent by endpoint
// (HMAI-283), so this is safe to run on every load; any failure (no VAPID key,
// server unreachable) is swallowed so it can never break the page.
async function ensureFreshSubscription() {
    try {
        if (!isPushSupported() || 'granted' !== permissionState()) {
            return;
        }
        if (!(await currentSubscription())) {
            await subscribeToPush();
        }
    } catch {
        // Best-effort — a missing key or an offline server is not the page's problem.
    }
}

function buildBanner(onAccept, onDismiss) {
    const banner = document.createElement('div');
    banner.id = 'pwa-push-prompt';
    banner.className = 'push-prompt hidden';

    const text = document.createElement('span');
    text.className = 'pwa-install-text';
    text.textContent = 'Włączyć powiadomienia o przypomnieniach i alertach?';

    const accept = document.createElement('button');
    accept.type = 'button';
    accept.className = 'btn btn-primary';
    accept.textContent = 'Włącz';
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

export function initPushPrompt() {
    if ('undefined' === typeof window || !('addEventListener' in window)) {
        return;
    }

    const visits = nextVisitCount(readStore(VISITS_KEY));
    writeStore(VISITS_KEY, String(visits));

    // Already granted — no banner; just make sure the subscription is live.
    if ('granted' === permissionState()) {
        void ensureFreshSubscription();

        return;
    }

    if (!shouldOfferPushPrompt({
        supported: isPushSupported(),
        permission: permissionState(),
        dismissed: '1' === readStore(DISMISS_KEY),
        visits,
    })) {
        return;
    }

    let banner = null;
    const hide = () => banner && banner.classList.add('hidden');

    const accept = async () => {
        hide();
        try {
            await subscribeToPush();
        } catch {
            // The permanent opt-in on /notifications surfaces the detailed reason;
            // a declined or failed soft-ask just closes quietly (permission is now
            // 'denied'/'default', so shouldOfferPushPrompt won't nag again anyway).
        }
    };

    const dismiss = () => {
        hide();
        writeStore(DISMISS_KEY, '1');
    };

    banner = buildBanner(accept, dismiss);
    banner.classList.remove('hidden');
}
