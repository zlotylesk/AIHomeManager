import { Controller } from '@hotwired/stimulus';
import { TOAST_TIMEOUT_MS, apiCall, escHtml, safeUrl } from '../util.js';
import {
    FILTERS,
    MOVIE_STATUSES,
    metaLine,
    ratingLabel,
    statusLabel,
    watchedLabel,
    watchedQuery,
} from '../movies/format.js';

function statusOptionsHtml(selected = '') {
    const empty = `<option value=""${'' === selected ? ' selected' : ''}>— status —</option>`;
    const options = MOVIE_STATUSES.map(
        (s) => `<option value="${escHtml(s)}"${s === selected ? ' selected' : ''}>${escHtml(statusLabel(s))}</option>`,
    ).join('');
    return empty + options;
}

function posterHtml(movie, cssClass) {
    const cover = safeUrl(movie.coverUrl);
    return cover
        ? `<img class="${cssClass}" src="${escHtml(cover)}" alt="${escHtml(movie.title)}" loading="lazy">`
        : `<div class="${cssClass} movie-poster--empty">🎬</div>`;
}

function ratingSelectorHtml(rating) {
    const buttons = [];
    for (let n = 1; n <= 10; n += 1) {
        const active = n === Number(rating) ? ' is-active' : '';
        buttons.push(`<button type="button" class="movie-rating-btn${active}" data-rating="${n}" data-action="click->movies#rate">${n}</button>`);
    }
    const clear = `<button type="button" class="movie-rating-clear" data-action="click->movies#clearRating" title="Wyczyść ocenę">✕</button>`;
    return `<div class="movie-rating-selector">${buttons.join('')}${clear}</div>`;
}

function cardHtml(movie) {
    const meta = metaLine(movie);
    return `
        <div class="movie-card${movie.watched ? ' movie-card--watched' : ''}" data-id="${escHtml(movie.id)}" data-action="click->movies#selectMovie">
            ${posterHtml(movie, 'movie-poster')}
            <div class="movie-card-body">
                <span class="movie-card-title">${escHtml(movie.title)}</span>
                ${meta ? `<span class="movie-card-meta">${escHtml(meta)}</span>` : ''}
                <span class="movie-card-flags">
                    <span class="movie-badge${movie.watched ? ' movie-badge--watched' : ''}">${escHtml(watchedLabel(movie.watched))}</span>
                    ${null !== movie.rating ? `<span class="movie-badge movie-badge--rating">${escHtml(String(movie.rating))}/10</span>` : ''}
                </span>
            </div>
        </div>
    `;
}

export default class extends Controller {
    static targets = ['addForm', 'title', 'coverUrl', 'year', 'status', 'description', 'filters', 'list', 'detail'];

    connect() {
        this.filter = 'all';
        this.statusTarget.innerHTML = statusOptionsHtml();
        this.renderFilters();
        this.loadMovies();
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

    renderFilters() {
        this.filtersTarget.innerHTML = FILTERS.map(
            (f) => `<button type="button" class="btn btn-sm movie-filter${f.value === this.filter ? ' is-active' : ''}" data-filter="${f.value}" data-action="click->movies#changeFilter">${escHtml(f.label)}</button>`,
        ).join('');
    }

    changeFilter(event) {
        this.filter = event.target.dataset.filter || 'all';
        this.renderFilters();
        this.backToList();
        this.loadMovies();
    }

    async loadMovies() {
        this.listTarget.innerHTML = '<div class="loading">Loading…</div>';
        try {
            const movies = await apiCall(`/api/movies${watchedQuery(this.filter)}`);
            this.listTarget.innerHTML = movies.length
                ? movies.map(cardHtml).join('')
                : `<div class="empty-state">${'all' === this.filter ? 'Brak filmów. Dodaj pierwszy film.' : 'Brak filmów w tym filtrze.'}</div>`;
        } catch {
            this.showError('Nie udało się wczytać filmów.');
            this.listTarget.innerHTML = '';
        }
    }

    toggleAddForm() {
        this.addFormTarget.classList.toggle('hidden');
    }

    async createMovie(event) {
        event.preventDefault();

        const title = this.titleTarget.value.trim();
        if ('' === title) {
            this.showError('Podaj tytuł filmu.');
            return;
        }

        const yearRaw = this.yearTarget.value.trim();
        const payload = {
            title,
            coverUrl: this.coverUrlTarget.value.trim() || null,
            year: '' === yearRaw ? null : Number(yearRaw),
            status: this.statusTarget.value || null,
            description: this.descriptionTarget.value.trim() || null,
        };

        try {
            await apiCall('/api/movies', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });
            this.resetAddForm();
            this.addFormTarget.classList.add('hidden');
            this.showInfo('Film dodany.');
            await this.loadMovies();
        } catch (err) {
            this.showError(err.message || 'Nie udało się dodać filmu.');
        }
    }

    resetAddForm() {
        this.titleTarget.value = '';
        this.coverUrlTarget.value = '';
        this.yearTarget.value = '';
        this.statusTarget.value = '';
        this.descriptionTarget.value = '';
    }

    async selectMovie(event) {
        const id = event.currentTarget.dataset.id;
        if (!id) {
            return;
        }
        try {
            const movie = await apiCall(`/api/movies/${encodeURIComponent(id)}`);
            this.renderDetail(movie);
        } catch (err) {
            this.showError(err.message || 'Nie udało się wczytać filmu.');
        }
    }

    renderDetail(movie) {
        this.current = movie;
        const meta = metaLine(movie);
        this.detailTarget.innerHTML = `
            <button type="button" class="btn btn-secondary btn-sm" data-action="click->movies#backToList">← Wróć</button>
            <div class="movie-detail">
                ${posterHtml(movie, 'movie-detail-poster')}
                <div class="movie-detail-body">
                    <h2 class="movie-detail-title">${escHtml(movie.title)}</h2>
                    ${meta ? `<p class="movie-detail-meta">${escHtml(meta)}</p>` : ''}
                    <p class="movie-detail-watched">
                        <span class="movie-badge${movie.watched ? ' movie-badge--watched' : ''}">${escHtml(watchedLabel(movie.watched))}</span>
                        <button type="button" class="btn btn-sm" data-action="click->movies#toggleWatched">${movie.watched ? 'Oznacz jako nieobejrzany' : 'Oznacz jako obejrzany'}</button>
                    </p>
                    <div class="movie-detail-rating">
                        <span class="movie-rating-label">${escHtml(ratingLabel(movie.rating))}</span>
                        ${ratingSelectorHtml(movie.rating)}
                    </div>
                    ${movie.description ? `<p class="movie-detail-description">${escHtml(movie.description)}</p>` : ''}
                    <div class="movie-detail-actions">
                        <button type="button" class="btn btn-secondary btn-sm" data-action="click->movies#startEdit">Edytuj</button>
                        <button type="button" class="btn btn-danger btn-sm" data-action="click->movies#deleteMovie">Usuń</button>
                    </div>
                </div>
            </div>
        `;
        this.detailTarget.classList.remove('hidden');
        this.listTarget.classList.add('hidden');
    }

    backToList() {
        this.current = null;
        this.detailTarget.classList.add('hidden');
        this.detailTarget.innerHTML = '';
        this.listTarget.classList.remove('hidden');
    }

    async toggleWatched() {
        if (!this.current) {
            return;
        }
        const id = this.current.id;
        try {
            await apiCall(`/api/movies/${encodeURIComponent(id)}/watched`, {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ watched: !this.current.watched }),
            });
            await this.reloadDetail(id);
            await this.loadMovies();
        } catch (err) {
            this.showError(err.message || 'Nie udało się zmienić statusu.');
        }
    }

    async rate(event) {
        if (!this.current) {
            return;
        }
        const rating = Number(event.target.dataset.rating);
        await this.applyRating(rating);
    }

    async clearRating() {
        await this.applyRating(null);
    }

    async applyRating(rating) {
        if (!this.current) {
            return;
        }
        const id = this.current.id;
        try {
            await apiCall(`/api/movies/${encodeURIComponent(id)}/rating`, {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ rating }),
            });
            await this.reloadDetail(id);
            await this.loadMovies();
        } catch (err) {
            this.showError(err.message || 'Nie udało się zapisać oceny.');
        }
    }

    startEdit() {
        if (!this.current) {
            return;
        }
        const m = this.current;
        this.detailTarget.innerHTML = `
            <form class="movie-form movie-edit-form" data-action="submit->movies#saveEdit">
                <input class="movie-input js-edit-title" type="text" value="${escHtml(m.title)}" placeholder="Tytuł" required>
                <input class="movie-input js-edit-cover" type="url" value="${escHtml(m.coverUrl ?? '')}" placeholder="URL okładki">
                <input class="movie-input js-edit-year" type="number" value="${escHtml(null === m.year ? '' : String(m.year))}" placeholder="Rok">
                <select class="movie-input js-edit-status">${statusOptionsHtml(m.status ?? '')}</select>
                <textarea class="movie-input movie-input--wide js-edit-description" placeholder="Opis">${escHtml(m.description ?? '')}</textarea>
                <div class="movie-detail-actions">
                    <button type="submit" class="btn btn-primary btn-sm">Zapisz</button>
                    <button type="button" class="btn btn-secondary btn-sm" data-action="click->movies#cancelEdit">Anuluj</button>
                </div>
            </form>
        `;
    }

    cancelEdit() {
        if (this.current) {
            this.renderDetail(this.current);
        }
    }

    async saveEdit(event) {
        event.preventDefault();
        if (!this.current) {
            return;
        }
        const id = this.current.id;
        const form = event.target;
        const title = form.querySelector('.js-edit-title').value.trim();
        if ('' === title) {
            this.showError('Podaj tytuł filmu.');
            return;
        }
        const yearRaw = form.querySelector('.js-edit-year').value.trim();
        const payload = {
            title,
            coverUrl: form.querySelector('.js-edit-cover').value.trim() || null,
            year: '' === yearRaw ? null : Number(yearRaw),
            status: form.querySelector('.js-edit-status').value || null,
            description: form.querySelector('.js-edit-description').value.trim() || null,
        };

        try {
            await apiCall(`/api/movies/${encodeURIComponent(id)}`, {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });
            this.showInfo('Film zaktualizowany.');
            await this.reloadDetail(id);
            await this.loadMovies();
        } catch (err) {
            this.showError(err.message || 'Nie udało się zapisać zmian.');
        }
    }

    async deleteMovie() {
        if (!this.current || !window.confirm('Usunąć ten film?')) {
            return;
        }
        const id = this.current.id;
        try {
            await apiCall(`/api/movies/${encodeURIComponent(id)}`, { method: 'DELETE' });
            this.backToList();
            await this.loadMovies();
        } catch (err) {
            this.showError(err.message || 'Nie udało się usunąć filmu.');
        }
    }

    async reloadDetail(id) {
        try {
            const movie = await apiCall(`/api/movies/${encodeURIComponent(id)}`);
            this.renderDetail(movie);
        } catch {
            this.backToList();
        }
    }

    async importFromTrakt() {
        try {
            await apiCall('/api/movies/import/trakt', { method: 'POST' });
            this.showInfo('Import z Trakt uruchomiony. Filmy pojawią się za chwilę.');
        } catch (err) {
            if (409 === err.status) {
                const authUrl = err.body && err.body.authUrl ? err.body.authUrl : '/auth/trakt';
                this.showError(`Trakt nie jest połączony. Autoryzuj: ${authUrl}`);
            } else {
                this.showError(err.message || 'Nie udało się uruchomić importu.');
            }
        }
    }
}
