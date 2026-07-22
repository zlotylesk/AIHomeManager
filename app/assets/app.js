import './bootstrap.js';
import './styles/app.css';
import { registerPushServiceWorker } from './notifications/service-worker-registration.js';
import { initInstallPrompt } from './pwa/install.js';
import { initOfflineIndicator } from './pwa/offline-indicator.js';
import { initQueueUx } from './pwa/queue-ux.js';
import { initPushPrompt } from './pwa/push.js';

registerPushServiceWorker();
initInstallPrompt();
initOfflineIndicator();
initQueueUx();
initPushPrompt();
