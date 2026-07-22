import './bootstrap.js';
import './styles/app.css';
import { registerPushServiceWorker } from './notifications/service-worker-registration.js';
import { initInstallPrompt } from './pwa/install.js';

registerPushServiceWorker();
initInstallPrompt();
