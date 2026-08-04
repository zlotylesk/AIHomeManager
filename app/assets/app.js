import './bootstrap.js';
import './styles/app.css';
import { registerPushServiceWorker } from './notifications/service-worker-registration.js';
import { initInstallPrompt } from './pwa/install.js';
import { initOfflineIndicator } from './pwa/offline-indicator.js';
import { initQueueUx } from './pwa/queue-ux.js';
import { initPushPrompt } from './pwa/push.js';
import { publishLegacyGlobals } from './legacy-globals.js';

// First, and not merely by preference: the legacy panels read these off `window`
// as soon as their own scripts run, which is immediately after this bundle.
publishLegacyGlobals();

registerPushServiceWorker();
initInstallPrompt();
initOfflineIndicator();
initQueueUx();
initPushPrompt();
