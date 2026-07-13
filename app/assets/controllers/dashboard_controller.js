import { Controller } from '@hotwired/stimulus';
import { apiCall, escHtml, safeUrl } from '../util.js';
import {
    emptyStateLabel,
    formatTimeRange,
    goalPeriodLabel,
    goalTypeLabel,
    musicSourceLabel,
    readTimeLabel,
    recommendationKindLabel,
    streakLabel,
} from '../dashboard/format.js';

function emptyState(widget) {
    return `<div class="empty-state">${escHtml(emptyStateLabel(widget))}</div>`;
}

function card(title, body) {
    return `
        <section class="dashboard-card">
            <h2 class="dashboard-card-title">${escHtml(title)}</h2>
            <div class="dashboard-card-body">${body}</div>
        </section>
    `;
}

function renderTasks(tasks) {
    if (!Array.isArray(tasks) || 0 === tasks.length) {
        return card('Zadania na dziś', emptyState('tasks'));
    }
    const items = tasks
        .map((task) => {
            const time = formatTimeRange(task.startsAt, task.endsAt);
            return `
                <li class="dashboard-item">
                    ${time ? `<span class="dashboard-item-time">${escHtml(time)}</span>` : ''}
                    <span class="dashboard-item-title">${escHtml(task.title)}</span>
                </li>`;
        })
        .join('');
    return card('Zadania na dziś', `<ul class="dashboard-list">${items}</ul>`);
}

function renderArticle(article) {
    if (!article) {
        return card('Artykuł dnia', emptyState('article'));
    }
    const href = safeUrl(article.url);
    const title = escHtml(article.title);
    const titleHtml = href
        ? `<a class="dashboard-article-title" href="${escHtml(href)}" target="_blank" rel="noopener">${title}</a>`
        : `<span class="dashboard-article-title">${title}</span>`;
    const meta = [];
    if (article.category) {
        meta.push(`<span>${escHtml(article.category)}</span>`);
    }
    const readTime = readTimeLabel(article.estimatedReadTime);
    if (readTime) {
        meta.push(`<span>${escHtml(readTime)}</span>`);
    }
    meta.push(
        article.isRead
            ? '<span class="dashboard-badge dashboard-badge--read">Przeczytany</span>'
            : '<span class="dashboard-badge">Do przeczytania</span>',
    );
    return card('Artykuł dnia', `${titleHtml}<div class="dashboard-meta">${meta.join('')}</div>`);
}

function renderGoals(goals) {
    if (!Array.isArray(goals) || 0 === goals.length) {
        return card('Cele i passy', emptyState('goals'));
    }
    const rows = goals
        .map(
            (goal) => `
            <div class="dashboard-goal">
                <span class="dashboard-goal-name">${escHtml(goalTypeLabel(goal.type))}</span>
                <span class="dashboard-goal-target">${escHtml(String(goal.target))} · ${escHtml(goalPeriodLabel(goal.period))}</span>
                <span class="dashboard-goal-streak">${escHtml(streakLabel(goal.currentStreak))}</span>
            </div>`,
        )
        .join('');
    return card('Cele i passy', rows);
}

function renderRecommendations(recommendations) {
    if (!Array.isArray(recommendations) || 0 === recommendations.length) {
        return card('Rekomendacje', emptyState('recommendations'));
    }
    const rows = recommendations
        .map((rec) => {
            const cover = safeUrl(rec.coverUrl);
            const coverHtml = cover
                ? `<img class="dashboard-rec-cover" src="${escHtml(cover)}" alt="" loading="lazy">`
                : '';
            const detail = rec.detail ? `<span class="dashboard-track-meta">${escHtml(rec.detail)}</span>` : '';
            return `
                <div class="dashboard-rec">
                    ${coverHtml}
                    <div class="dashboard-rec-body">
                        <span class="dashboard-badge">${escHtml(recommendationKindLabel(rec.kind))}</span>
                        <span class="dashboard-rec-title">${escHtml(rec.title)}</span>
                        ${detail}
                    </div>
                </div>`;
        })
        .join('');
    return card('Rekomendacje', rows);
}

function renderTracks(tracks) {
    if (!Array.isArray(tracks) || 0 === tracks.length) {
        return card('Ostatnio słuchane', emptyState('tracks'));
    }
    const rows = tracks
        .map(
            (track) => `
            <div class="dashboard-track">
                <span class="dashboard-track-main">${escHtml(track.artist)} — ${escHtml(track.title)}</span>
                <span class="dashboard-track-meta">${escHtml(musicSourceLabel(track.source))}</span>
            </div>`,
        )
        .join('');
    return card('Ostatnio słuchane', rows);
}

export default class extends Controller {
    static targets = ['content'];

    connect() {
        this.load();
    }

    async load() {
        this.contentTarget.innerHTML = '<div class="loading">Loading…</div>';
        try {
            const data = await apiCall('/api/dashboard');
            this.contentTarget.innerHTML = [
                renderTasks(data.tasks),
                renderArticle(data.article),
                renderGoals(data.goals),
                renderRecommendations(data.recommendations),
                renderTracks(data.recentTracks),
            ].join('');
        } catch {
            this.contentTarget.innerHTML =
                '<div class="empty-state">Nie udało się wczytać kokpitu. Odśwież stronę.</div>';
        }
    }
}
