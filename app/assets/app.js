import './bootstrap.js';
import './styles/app.css';
import { registerPushServiceWorker } from './notifications/service-worker-registration.js';
import { initInstallPrompt } from './pwa/install.js';
import { initOfflineIndicator } from './pwa/offline-indicator.js';

registerPushServiceWorker();
initInstallPrompt();
initOfflineIndicator();
