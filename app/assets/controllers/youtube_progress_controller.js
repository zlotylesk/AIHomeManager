import { Controller } from '@hotwired/stimulus';
import { TOAST_TIMEOUT_MS, apiCall, escHtml } from '../util.js';

const STATUS_LABELS = {
    'split-pool': 'W puli',
    started: 'Rozpoczęty',
    watched: 'Obejrzany',
};

function formatDuration(seconds) {
    const total = Number(seconds) || 0;
    const h = Math.floor(total / 3600);
    const m = Math.floor((total % 3600) / 60);
    const s = total % 60;
    const pad = (n) => String(n).padStart(2, '0');
    return h > 0 ? `${h}:${pad(m)}:${pad(s)}` : `${m}:${pad(s)}`;
}

function statusBadge(status) {
    const label = STATUS_LABELS[status] ?? status ?? '—';
    return `<span class="youtube-progress-status-badge youtube-progress-status-badge--${escHtml(status ?? 'unknown')}">${escHtml(label)}</span>`;
}

function renderVideoRow(video) {
    const id = escHtml(video.youtubeId);
    const actions = video.status === 'watched'
        ? ''
        : `
            <div class="youtube-progress-video-actions">
                ${video.status === 'split-pool' ? `
                <button class="btn btn-secondary btn-sm"
                        data-id="${id}"
                        data-action="click->youtube-progress#markStarted">Rozpocznij</button>
                ` : ''}
                <button class="btn btn-secondary btn-sm"
                        data-id="${id}"
                        data-action="click->youtube-progress#markWatched">Obejrzane</button>
            </div>
        `;

    return `
        <div class="youtube-progress-video-row" data-id="${id}">
            <div class="youtube-progress-video-main">
                <a class="youtube-progress-video-title"
                   href="https://www.youtube.com/watch?v=${id}"
                   target="_blank"
                   rel="noopener noreferrer">${escHtml(video.title ?? '—')}</a>
                <span class="youtube-progress-video-channel">${escHtml(video.channel ?? '—')}</span>
            </div>
            <div class="youtube-progress-video-meta">
                <span class="youtube-progress-video-duration">${formatDuration(video.durationSeconds)}</span>
                ${statusBadge(video.status)}
                ${actions}
            </div>
        </div>
    `;
}

function renderSession(session) {
    const pushed = Boolean(session.youtubePlaylistId);
    const videos = (session.videos ?? []).map(renderVideoRow).join('');
    const date = session.createdAt ? new Date(session.createdAt).toLocaleString() : '';

    const pushButton = pushed
        ? `<span class="youtube-progress-pushed">✓ Na YouTube</span>`
        : `<button class="btn btn-primary btn-sm"
                   data-id="${escHtml(session.id)}"
                   data-action="click->youtube-progress#pushSession">Wyślij do YouTube</button>`;

    return `
        <div class="youtube-progress-session-card" data-id="${escHtml(session.id)}">
            <div class="youtube-progress-session-head">
                <div class="youtube-progress-session-info">
                    <span class="youtube-progress-session-date">${escHtml(date)}</span>
                    <span class="youtube-progress-session-duration">${formatDuration(session.totalDurationSeconds)} · ${(session.videos ?? []).length} filmów</span>
                </div>
                ${pushButton}
            </div>
            <div class="youtube-progress-session-videos">${videos}</div>
        </div>
    `;
}

export default class extends Controller {
    static targets = ['sessions', 'watchlist', 'syncButton'];

    connect() {
        this.loadAll();
    }


    showError(msg) {
        const banner = document.getElementById('error-banner');
        if (!banner) return;
        banner.textContent = msg;
        banner.classList.remove('hidden');
        setTimeout(() => banner.classList.add('hidden'), TOAST_TIMEOUT_MS);
    }

    async loadAll() {
        await Promise.all([this.loadSessions(), this.loadWatchlist()]);
    }


    async loadSessions() {
        this.sessionsTarget.innerHTML = '<div class="loading">Loading…</div>';
        try {
            const { sessions } = await apiCall('/api/youtube-progress/sessions');
            this.sessionsTarget.innerHTML = sessions.length
                ? sessions.map(renderSession).join('')
                : '<div class="empty-state">Brak sesji. Zsynchronizuj watchlistę.</div>';
        } catch {
            this.showError('Nie udało się wczytać sesji.');
            this.sessionsTarget.innerHTML = '';
        }
    }

    async loadWatchlist() {
        this.watchlistTarget.innerHTML = '<div class="loading">Loading…</div>';
        try {
            const { videos } = await apiCall('/api/youtube-progress/watchlist');
            this.watchlistTarget.innerHTML = videos.length
                ? videos.map(renderVideoRow).join('')
                : '<div class="empty-state">Watchlista jest pusta.</div>';
        } catch {
            this.showError('Nie udało się wczytać watchlisty.');
            this.watchlistTarget.innerHTML = '';
        }
    }


    async sync() {
        const btn = this.syncButtonTarget;
        btn.disabled = true;
        btn.textContent = 'Synchronizuję…';
        try {
            await apiCall('/api/youtube-progress/sync', { method: 'POST' });
            await this.loadAll();
        } catch (err) {
            this.showError(err.message || 'Synchronizacja nie powiodła się.');
        }
        btn.disabled = false;
        btn.textContent = 'Synchronizuj';
    }

    async markStarted(event) {
        await this.videoCommand(event, 'start', 'Nie udało się oznaczyć jako rozpoczęty.');
    }

    async markWatched(event) {
        await this.videoCommand(event, 'watched', 'Nie udało się oznaczyć jako obejrzany.');
    }

    async videoCommand(event, action, errorMsg) {
        const id = event.target.dataset.id;
        if (!id) return;
        event.target.disabled = true;
        try {
            await apiCall(`/api/youtube-progress/videos/${encodeURIComponent(id)}/${action}`, { method: 'POST' });
            await this.loadAll();
        } catch (err) {
            this.showError(err.message || errorMsg);
            event.target.disabled = false;
        }
    }

    async pushSession(event) {
        const id = event.target.dataset.id;
        if (!id) return;
        event.target.disabled = true;
        event.target.textContent = 'Wysyłam…';
        try {
            await apiCall(`/api/youtube-progress/sessions/${encodeURIComponent(id)}/push-to-youtube`, { method: 'POST' });
            await this.loadSessions();
        } catch (err) {
            this.showError(err.message || 'Nie udało się wysłać sesji do YouTube.');
            event.target.disabled = false;
            event.target.textContent = 'Wyślij do YouTube';
        }
    }
}
