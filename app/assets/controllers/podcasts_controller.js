import { Controller } from '@hotwired/stimulus';
import { TOAST_TIMEOUT_MS, apiCall, escHtml, safeUrl } from '../util.js';
import {
    LISTENED_CAVEAT,
    counterLabel,
    dayLabel,
    durationLabel,
    episodeProgressLabel,
    groupSessionsByDay,
    lastListenedLabel,
    progressPercent,
    timeLabel,
} from '../podcasts/format.js';

function coverHtml(podcast, cssClass) {
    const cover = safeUrl(podcast.coverUrl);

    return cover
        ? `<img class="${cssClass}" src="${escHtml(cover)}" alt="${escHtml(podcast.title)}" loading="lazy">`
        : `<div class="${cssClass} podcast-cover--empty">🎙️</div>`;
}

function cardHtml(podcast) {
    return `
        <div class="podcast-card" data-id="${escHtml(podcast.id)}" data-action="click->podcasts#selectPodcast">
            ${coverHtml(podcast, 'podcast-cover')}
            <div class="podcast-card-body">
                <span class="podcast-card-title">${escHtml(podcast.title)}</span>
                ${podcast.publisher ? `<span class="podcast-card-meta">${escHtml(podcast.publisher)}</span>` : ''}
                <span class="podcast-card-counter">${escHtml(counterLabel(podcast.listenedEpisodeCount, podcast.episodeCount))}</span>
                <span class="podcast-card-last">${escHtml(lastListenedLabel(podcast.lastListenedAt))}</span>
            </div>
        </div>`;
}

function episodeRowHtml(episode) {
    const percent = progressPercent(episode.resumePositionMs, episode.durationMs);
    const stateClass = episode.fullyPlayed
        ? ' podcast-episode--done'
        : (episode.listened ? ' podcast-episode--started' : '');

    return `
        <li class="podcast-episode${stateClass}">
            <div class="podcast-episode-head">
                <span class="podcast-episode-title">${escHtml(episode.title)}</span>
                <span class="podcast-episode-duration">${escHtml(durationLabel(episode.durationMs))}</span>
            </div>
            <div class="podcast-episode-meta">
                <span class="podcast-episode-published">${escHtml(dayLabel(episode.publishedAt))}</span>
                <span class="podcast-episode-progress">${escHtml(episodeProgressLabel(episode))}</span>
            </div>
            <div class="podcast-progress-track" role="progressbar" aria-valuenow="${percent}" aria-valuemin="0" aria-valuemax="100">
                <div class="podcast-progress-bar" style="width: ${percent}%"></div>
            </div>
        </li>`;
}

function sessionGroupHtml(group) {
    const rows = group.sessions.map((session) => {
        const time = timeLabel(session.listenedAt);

        return `
            <li class="podcast-session">
                <span class="podcast-session-episode">${escHtml(session.episodeTitle)}</span>
                <span class="podcast-session-state">${escHtml(session.fullyPlayed ? 'ukończony' : 'w trakcie')}</span>
                ${time ? `<span class="podcast-session-time">${escHtml(time)}</span>` : ''}
            </li>`;
    }).join('');

    return `
        <div class="podcast-session-group">
            <h4 class="podcast-session-day">${escHtml(group.day)}</h4>
            <ul class="podcast-session-list">${rows}</ul>
        </div>`;
}

function detailHtml(detail) {
    const episodes = detail.episodes.length
        ? `<ul class="podcast-episode-list">${detail.episodes.map(episodeRowHtml).join('')}</ul>`
        : '<div class="empty-state">Brak odcinków w katalogu.</div>';

    const groups = groupSessionsByDay(detail.sessions);
    const sessions = groups.length
        ? groups.map(sessionGroupHtml).join('')
        : '<div class="empty-state">Brak historii odsłuchów.</div>';

    return `
        <div class="podcast-detail">
            <button type="button" class="btn btn-sm" data-action="click->podcasts#backToList">← Wróć</button>
            <div class="podcast-detail-header">
                ${coverHtml(detail, 'podcast-detail-cover')}
                <div class="podcast-detail-info">
                    <h2 class="podcast-detail-title">${escHtml(detail.title)}</h2>
                    ${detail.publisher ? `<p class="podcast-detail-publisher">${escHtml(detail.publisher)}</p>` : ''}
                    <p class="podcast-detail-counter">${escHtml(counterLabel(detail.listenedEpisodeCount, detail.episodeCount))}</p>
                    ${detail.description ? `<p class="podcast-detail-description">${escHtml(detail.description)}</p>` : ''}
                </div>
            </div>
            <h3 class="podcast-detail-section">Odcinki</h3>
            ${episodes}
            <h3 class="podcast-detail-section">Historia odsłuchów</h3>
            ${sessions}
        </div>`;
}

export default class extends Controller {
    static targets = ['list', 'detail', 'caveat', 'syncButton'];

    connect() {
        this.caveatTarget.textContent = LISTENED_CAVEAT;
        this.loadPodcasts();
    }

    flash(bannerId, msg) {
        const banner = document.getElementById(bannerId);
        if (!banner) {
            return;
        }
        banner.textContent = msg;
        banner.classList.remove('hidden');
        setTimeout(() => banner.classList.add('hidden'), TOAST_TIMEOUT_MS);
    }

    showError(msg) {
        this.flash('error-banner', msg);
    }

    showInfo(msg) {
        this.flash('info-banner', msg);
    }

    async loadPodcasts() {
        this.listTarget.innerHTML = '<div class="loading">Loading…</div>';
        try {
            const podcasts = await apiCall('/api/podcasts');
            this.listTarget.innerHTML = podcasts.length
                ? podcasts.map(cardHtml).join('')
                : '<div class="empty-state">Brak podcastów. Uruchom synchronizację, aby pobrać historię odsłuchów.</div>';
        } catch {
            this.showError('Nie udało się wczytać podcastów.');
            this.listTarget.innerHTML = '';
        }
    }

    async selectPodcast(event) {
        const card = event.target.closest('.podcast-card');
        if (!card) {
            return;
        }

        try {
            const detail = await apiCall(`/api/podcasts/${encodeURIComponent(card.dataset.id)}`);
            this.detailTarget.innerHTML = detailHtml(detail);
            this.detailTarget.classList.remove('hidden');
            this.listTarget.classList.add('hidden');
        } catch (err) {
            this.showError(err.message || 'Nie udało się wczytać podcastu.');
        }
    }

    backToList() {
        this.detailTarget.classList.add('hidden');
        this.detailTarget.innerHTML = '';
        this.listTarget.classList.remove('hidden');
    }

    async sync() {
        try {
            await apiCall('/api/podcasts/sync', { method: 'POST' });
            this.showInfo('Synchronizacja uruchomiona. Historia odsłuchów zaktualizuje się za chwilę.');
        } catch (err) {
            if (409 === err.status) {
                const authUrl = err.body && err.body.authUrl ? err.body.authUrl : '/auth/spotify';
                this.showError(`Spotify nie jest połączone. Autoryzuj: ${authUrl}`);
            } else {
                this.showError(err.message || 'Nie udało się uruchomić synchronizacji.');
            }
        }
    }
}
