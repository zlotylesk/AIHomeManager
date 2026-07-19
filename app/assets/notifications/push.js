import { apiCall } from '../util.js';
import { registerPushServiceWorker } from './service-worker-registration.js';

/**
 * The browser side of the push opt-in (HMAI-283): permission, subscription, and
 * handing the subscription to the API.
 *
 * The Service Worker registration from HMAI-280 is a prerequisite — a push
 * subscription is created *through* a registered worker — so this reuses it
 * rather than registering a second one.
 */

/**
 * The VAPID public key travels as base64url but pushManager wants raw bytes.
 */
export function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const raw = window.atob(base64);

    return Uint8Array.from([...raw].map((char) => char.charCodeAt(0)));
}

export function isPushSupported() {
    return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
}

export async function currentSubscription() {
    if (!isPushSupported()) {
        return null;
    }

    const registration = await navigator.serviceWorker.getRegistration();

    return registration ? registration.pushManager.getSubscription() : null;
}

/**
 * Returns the created subscription, or throws with a message the panel shows.
 */
export async function subscribeToPush() {
    if (!isPushSupported()) {
        throw new Error('Ta przeglądarka nie obsługuje powiadomień push.');
    }

    const permission = await Notification.requestPermission();

    if (permission !== 'granted') {
        throw new Error('Bez zgody przeglądarki powiadomienia push nie zadziałają.');
    }

    const { publicKey } = await apiCall('/api/notifications/push/key');

    if (!publicKey) {
        throw new Error('Push nie jest skonfigurowany na serwerze (brak klucza VAPID).');
    }

    const registration = (await navigator.serviceWorker.getRegistration()) || (await registerPushServiceWorker());

    if (!registration) {
        throw new Error('Nie udało się zarejestrować Service Workera.');
    }

    const subscription = await registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(publicKey),
    });

    const raw = subscription.toJSON();

    await apiCall('/api/notifications/push/subscriptions', {
        method: 'POST',
        body: JSON.stringify({
            endpoint: subscription.endpoint,
            publicKey: raw.keys.p256dh,
            authToken: raw.keys.auth,
        }),
    });

    return subscription;
}

export async function unsubscribeFromPush() {
    const subscription = await currentSubscription();

    if (!subscription) {
        return;
    }

    // Tell the server first: if the browser-side unsubscribe succeeded but the
    // API call did not, the server would keep pushing to a dead endpoint until
    // the push service reported 410.
    await apiCall('/api/notifications/push/subscriptions', {
        method: 'DELETE',
        body: JSON.stringify({ endpoint: subscription.endpoint }),
    });

    await subscription.unsubscribe();
}
