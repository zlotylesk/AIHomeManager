import { Controller } from '@hotwired/stimulus';
import { apiCall, escHtml } from '../util.js';
import {
    typeLabel,
    channelLabel,
    statusLabel,
    isChannelEnabled,
    quietHoursLabel,
    isQuietRangeComplete,
    formatSentAt,
} from '../notifications/format.js';
import { isPushSupported, currentSubscription, subscribeToPush, unsubscribeFromPush } from '../notifications/push.js';

const CHANNELS = ['email', 'push'];

export default class extends Controller {
    connect() {
        this.load();
    }

    async load() {
        try {
            const [preferences, history] = await Promise.all([
                apiCall('/api/notifications/preferences'),
                apiCall('/api/notifications/history?limit=20'),
            ]);

            this.renderPreferences(preferences || []);
            this.renderHistory(history || []);
            await this.renderPushToggle();
        } catch (error) {
            this.showError(error.message);
        }
    }

    renderPreferences(preferences) {
        const container = this.element.querySelector('#notification-preferences');

        if (!container) {
            return;
        }

        container.innerHTML = preferences.map((preference) => `
            <div class="notification-preference" data-type="${escHtml(preference.type)}">
                <div class="notification-preference-header">
                    <label>
                        <input type="checkbox" class="js-type-enabled" ${preference.enabled ? 'checked' : ''}>
                        <strong>${escHtml(typeLabel(preference.type))}</strong>
                    </label>
                    <span class="notification-quiet">${escHtml(quietHoursLabel(preference))}</span>
                </div>
                <div class="notification-channels">
                    ${CHANNELS.map((channel) => `
                        <label>
                            <input type="checkbox" class="js-channel" data-channel="${channel}"
                                ${isChannelEnabled(preference, channel) ? 'checked' : ''}>
                            ${escHtml(channelLabel(channel))}
                        </label>
                    `).join('')}
                </div>
                <div class="notification-quiet-hours">
                    <label>Cisza od <input type="time" class="js-quiet-from" value="${escHtml(preference.quietFrom || '')}"></label>
                    <label>do <input type="time" class="js-quiet-to" value="${escHtml(preference.quietTo || '')}"></label>
                    <button type="button" class="js-save-quiet">Zapisz</button>
                </div>
            </div>
        `).join('');

        container.querySelectorAll('.notification-preference').forEach((row) => this.bindPreference(row));
    }

    bindPreference(row) {
        const type = row.dataset.type;

        row.querySelector('.js-type-enabled')?.addEventListener('change', (event) => {
            this.write(`/api/notifications/preferences/${type}/enabled`, 'PATCH', { enabled: event.target.checked });
        });

        row.querySelectorAll('.js-channel').forEach((input) => {
            input.addEventListener('change', (event) => {
                this.write(
                    `/api/notifications/preferences/${type}/channels/${event.target.dataset.channel}`,
                    'PATCH',
                    { enabled: event.target.checked },
                );
            });
        });

        row.querySelector('.js-save-quiet')?.addEventListener('click', () => {
            const from = row.querySelector('.js-quiet-from').value || null;
            const to = row.querySelector('.js-quiet-to').value || null;

            if (!isQuietRangeComplete(from, to)) {
                this.showError('Podaj obie godziny ciszy albo wyczyść obie.');

                return;
            }

            this.write(`/api/notifications/preferences/${type}/quiet-hours`, 'PUT', { from, to }, true);
        });
    }

    renderHistory(notifications) {
        const container = this.element.querySelector('#notification-history');

        if (!container) {
            return;
        }

        if (notifications.length === 0) {
            container.innerHTML = '<p class="empty-state">Brak wysłanych powiadomień.</p>';

            return;
        }

        container.innerHTML = notifications.map((notification) => `
            <li class="notification-history-item">
                <span class="notification-history-type">${escHtml(typeLabel(notification.type))}</span>
                <span class="notification-history-channel">${escHtml(channelLabel(notification.channel))}</span>
                <span class="notification-history-status is-${escHtml(notification.status)}">${escHtml(statusLabel(notification.status))}</span>
                <span class="notification-history-time">${escHtml(formatSentAt(notification))}</span>
            </li>
        `).join('');
    }

    async renderPushToggle() {
        const button = this.element.querySelector('#notification-push-toggle');

        if (!button) {
            return;
        }

        if (!isPushSupported()) {
            button.disabled = true;
            button.textContent = 'Push niedostępny w tej przeglądarce';

            return;
        }

        const subscription = await currentSubscription();
        button.dataset.subscribed = subscription ? 'true' : 'false';
        button.textContent = subscription ? 'Wyłącz powiadomienia push' : 'Włącz powiadomienia push';
        button.onclick = () => this.togglePush(button);
    }

    async togglePush(button) {
        button.disabled = true;

        try {
            if (button.dataset.subscribed === 'true') {
                await unsubscribeFromPush();
            } else {
                await subscribeToPush();
            }

            this.hideError();
        } catch (error) {
            this.showError(error.message);
        } finally {
            button.disabled = false;
            await this.renderPushToggle();
        }
    }

    async write(url, method, body, reload = false) {
        try {
            await apiCall(url, { method, body: JSON.stringify(body) });
            this.hideError();

            if (reload) {
                await this.load();
            }
        } catch (error) {
            this.showError(error.message);
            // The panel reflects server state, so a rejected write must not leave
            // the checkbox showing a setting that was never saved.
            await this.load();
        }
    }

    showError(message) {
        const banner = document.querySelector('#error-banner');

        if (banner) {
            banner.textContent = message;
            banner.style.display = 'block';
        }
    }

    hideError() {
        const banner = document.querySelector('#error-banner');

        if (banner) {
            banner.style.display = 'none';
        }
    }
}
