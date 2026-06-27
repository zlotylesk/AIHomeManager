import { Controller } from '@hotwired/stimulus';
import { TOAST_TIMEOUT_MS, apiCall, escHtml, safeUrl } from '../util.js';

const API = {
    series: () => apiCall('/api/series'),
    seriesDetail: (id) => apiCall(`/api/series/${id}`),
    createSeries: (payload) => apiCall('/api/series', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(payload),
    }),
    addSeason: (seriesId, number) => apiCall(`/api/series/${seriesId}/seasons`, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({number}),
    }),
    addEpisode: (seriesId, seasonId, title, number, rating) => apiCall(
        `/api/series/${seriesId}/seasons/${seasonId}/episodes`, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({title, number, rating: rating || null}),
    }),
    rateEpisode: (seriesId, seasonId, episodeId, rating) => apiCall(
        `/api/series/${seriesId}/seasons/${seasonId}/episodes/${episodeId}/rating`, {
        method: 'PATCH',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({rating}),
    }),
    setEpisodeWatched: (seriesId, seasonId, episodeId, watched) => apiCall(
        `/api/series/${seriesId}/seasons/${seasonId}/episodes/${episodeId}/watched`, {
        method: 'PATCH',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({watched}),
    }),
    rateSeries: (seriesId, rating) => apiCall(`/api/series/${seriesId}/rating`, {
        method: 'PATCH',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({rating}),
    }),
    rateSeason: (seriesId, seasonId, rating) => apiCall(
        `/api/series/${seriesId}/seasons/${seasonId}/rating`, {
        method: 'PATCH',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({rating}),
    }),
    deleteSeries: (seriesId) => apiCall(`/api/series/${seriesId}`, {method: 'DELETE'}),
    deleteSeason: (seriesId, seasonId) => apiCall(
        `/api/series/${seriesId}/seasons/${seasonId}`, {method: 'DELETE'}),
    deleteEpisode: (seriesId, seasonId, episodeId) => apiCall(
        `/api/series/${seriesId}/seasons/${seasonId}/episodes/${episodeId}`, {method: 'DELETE'}),
    renameSeries: (seriesId, title) => apiCall(`/api/series/${seriesId}`, {
        method: 'PATCH',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({title}),
    }),
    updateSeries: (seriesId, payload) => apiCall(`/api/series/${seriesId}`, {
        method: 'PATCH',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(payload),
    }),
    renumberSeason: (seriesId, seasonId, number) => apiCall(
        `/api/series/${seriesId}/seasons/${seasonId}`, {
        method: 'PATCH',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({number}),
    }),
    renameEpisode: (seriesId, seasonId, episodeId, title) => apiCall(
        `/api/series/${seriesId}/seasons/${seasonId}/episodes/${episodeId}`, {
        method: 'PATCH',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({title}),
    }),
    importFromTrakt: () => apiCall('/api/series/import/trakt', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
    }),
};

export function avg(nums) {
    const filtered = nums.filter(n => n !== null && n !== undefined);
    if (!filtered.length) return null;
    return Math.round((filtered.reduce((a, b) => a + b, 0) / filtered.length) * 100) / 100;
}

export function cardRating(label, value) {
    const has = value !== null && value !== undefined;
    return `<span class="card-rating${has ? '' : ' card-rating-empty'}">${label} ${has ? `★ ${value}` : '—'}</span>`;
}

export function statusLabel(status) {
    return {ongoing: 'Ongoing', ended: 'Ended'}[status] ?? null;
}


export function ratingHighlight(entity) {
    if (entity.episodeCount > 0 && entity.watchedCount < entity.episodeCount) {
        return 'incomplete';
    }
    if (entity.averageRating === null || entity.averageRating === undefined) {
        return null;
    }
    if (entity.rating === null || entity.rating === undefined) {
        return 'mismatch';
    }
    return Math.round(entity.averageRating) !== entity.rating ? 'mismatch' : null;
}

export function ratingFlag(entity) {
    const state = ratingHighlight(entity);
    if (state === 'incomplete') {
        return {cls: 'is-rating-incomplete', title: `W toku — obejrzane ${entity.watchedCount}/${entity.episodeCount} odcinków`};
    }
    if (state === 'mismatch') {
        const avgRounded = Math.round(entity.averageRating);
        const title = entity.rating === null || entity.rating === undefined
            ? `Brak Twojej oceny (średnia ${avgRounded})`
            : `Twoja ocena ${entity.rating} ≠ średnia ${avgRounded}`;
        return {cls: 'is-rating-mismatch', title};
    }
    return {cls: '', title: ''};
}

export function cardRatingFlag(s) {
    if (s.episodeCount > 0 && s.watchedCount < s.episodeCount) {
        return {cls: 'is-rating-incomplete', title: `W toku — obejrzane ${s.watchedCount}/${s.episodeCount} odcinków`};
    }
    if (ratingHighlight(s) === 'mismatch' || (s.seasons ?? []).some(se => ratingHighlight(se) === 'mismatch')) {
        return {cls: 'is-rating-mismatch', title: 'Twoja ocena ≠ średnia z odcinków (serial lub sezon)'};
    }
    return {cls: '', title: ''};
}

export function filterSeries(list, searchTerm) {
    const term = (searchTerm ?? '').trim().toLowerCase();
    return term
        ? list.filter(s => s.title.toLowerCase().includes(term))
        : [...list];
}

export function sortSeries(list, key) {
    const desc = (a, b) => (b ?? -Infinity) - (a ?? -Infinity);
    const sorted = [...list];
    switch (key) {
        case 'rating-desc':
            sorted.sort((a, b) => desc(a.averageRating, b.averageRating));
            break;
        case 'own-desc':
            sorted.sort((a, b) => desc(a.rating, b.rating));
            break;
        case 'created-desc':
            sorted.sort((a, b) => (b.createdAt ?? '').localeCompare(a.createdAt ?? ''));
            break;
        default:
            sorted.sort((a, b) => a.title.localeCompare(b.title));
    }
    return sorted;
}

export default class extends Controller {
    connect() {
        this.initAddSeriesModal();
        this.initImportTrakt();
        this.initNavigation();
        this.initListControls();
        this.loadSeriesList();
    }


    $(id) {
        return this.element.querySelector(`#${id}`);
    }

    show(el) {
        el.classList.remove('hidden');
    }

    hide(el) {
        el.classList.add('hidden');
    }

    showError(msg) {
        const banner = document.getElementById('error-banner');
        if (!banner) return;
        banner.textContent = msg;
        banner.classList.remove('hidden');
        setTimeout(() => banner.classList.add('hidden'), TOAST_TIMEOUT_MS);
    }

    hideError() {
        const banner = document.getElementById('error-banner');
        if (banner) banner.classList.add('hidden');
    }

    showInfo(msg) {
        const banner = document.getElementById('info-banner');
        if (!banner) return;
        banner.textContent = msg;
        banner.classList.remove('hidden');
        setTimeout(() => banner.classList.add('hidden'), TOAST_TIMEOUT_MS);
    }


    initImportTrakt() {
        const btn = this.$('btn-import-trakt');
        if (!btn) return;
        btn.addEventListener('click', () => this.importFromTrakt(btn));
    }

    async importFromTrakt(btn) {
        this.hideError();
        btn.disabled = true;
        try {
            await API.importFromTrakt();
            this.showInfo('Trakt import started in the background. Your watched shows will appear shortly.');
        } catch (err) {
            if (err.status === 409) {
                this.showTraktConnectPrompt();
            } else {
                this.showError(err.message || 'Failed to start the Trakt import.');
            }
        } finally {
            btn.disabled = false;
        }
    }

    showTraktConnectPrompt() {
        const banner = document.getElementById('error-banner');
        if (!banner) return;
        banner.innerHTML = 'Connect your Trakt account first: <a href="/auth/trakt">Connect Trakt</a>';
        banner.classList.remove('hidden');
    }


    initListControls() {
        this.allSeries = [];
        this.searchTerm = '';
        this.sortKey = 'title';

        const search = this.$('series-search');
        if (search) {
            search.addEventListener('input', () => {
                this.searchTerm = search.value;
                this.applyListView();
            });
        }
        const sort = this.$('series-sort');
        if (sort) {
            sort.addEventListener('change', () => {
                this.sortKey = sort.value;
                this.applyListView();
            });
        }
    }

    applyListView() {
        const toolbar = this.$('series-toolbar');
        const container = this.$('series-list');

        if (!this.allSeries.length) {
            if (toolbar) this.hide(toolbar);
            container.innerHTML = '<div class="empty-state">No series yet. Add your first one!</div>';
            return;
        }

        if (toolbar) this.show(toolbar);

        const filtered = filterSeries(this.allSeries, this.searchTerm);

        if (!filtered.length) {
            container.innerHTML = '<div class="empty-state">No series match your search.</div>';
            return;
        }

        this.renderSeriesList(sortSeries(filtered, this.sortKey));
    }

    renderSeriesList(seriesArr) {
        const container = this.$('series-list');
        container.innerHTML = seriesArr.map(s => {
            const poster = safeUrl(s.coverUrl);
            const status = statusLabel(s.status);
            const metaBits = [s.year, status].filter(Boolean);
            const flag = cardRatingFlag(s);
            return `
            <div class="series-card${flag.cls ? ` ${flag.cls}` : ''}" data-id="${s.id}"${flag.title ? ` title="${escHtml(flag.title)}"` : ''}>
                <div class="series-card-poster">
                    ${poster
                        ? `<img src="${escHtml(poster)}" alt="" loading="lazy">`
                        : '<span class="series-card-poster-empty">No poster</span>'}
                </div>
                <div class="series-card-body">
                    <h3>${escHtml(s.title)}</h3>
                    ${metaBits.length ? `<div class="series-card-meta">${escHtml(metaBits.join(' · '))}</div>` : ''}
                    <div class="series-card-ratings">
                        ${cardRating('My', s.rating)}
                        ${cardRating('Avg', s.averageRating)}
                    </div>
                </div>
            </div>
        `;
        }).join('');

        container.querySelectorAll('.series-card').forEach(card => {
            card.addEventListener('click', () => this.loadDetail(card.dataset.id));
        });
    }

    async loadSeriesList() {
        const container = this.$('series-list');
        container.innerHTML = '<div class="loading">Loading…</div>';
        try {
            this.allSeries = await API.series();
            this.applyListView();
        } catch {
            this.showError('Failed to load series. Is the backend running?');
            container.innerHTML = '';
        }
    }


    renderRatingSelector(selected, onChange) {
        const wrap = document.createElement('div');
        wrap.className = 'rating-selector';
        for (let i = 1; i <= 10; i++) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'rating-btn' + (selected === i ? ' selected' : '');
            btn.textContent = i;
            btn.dataset.value = i;
            btn.addEventListener('click', () => {
                wrap.querySelectorAll('.rating-btn').forEach(b => b.classList.remove('selected'));
                btn.classList.add('selected');
                onChange(i);
            });
            wrap.appendChild(btn);
        }
        return wrap;
    }

    buildOwnRatingControl(current, onSave) {
        const wrap = document.createElement('span');
        wrap.className = 'own-rating';

        const renderDisplay = () => {
            wrap.innerHTML = '';
            const rated = current !== null && current !== undefined;
            const label = document.createElement('span');
            label.className = 'own-rating-label';
            label.textContent = 'My rating:';
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'rating-cell-btn' + (rated ? '' : ' rating-cell-empty');
            btn.textContent = rated ? `★ ${current}` : 'Rate';
            btn.title = rated ? 'Change your rating' : 'Set your rating';
            btn.addEventListener('click', renderEditor);
            wrap.append(label, ' ', btn);

            if (rated) {
                const clear = document.createElement('button');
                clear.type = 'button';
                clear.className = 'rating-clear';
                clear.textContent = '✕';
                clear.title = 'Remove your rating';
                clear.addEventListener('click', async () => {
                    clear.disabled = true;
                    this.hideError();
                    try {
                        await onSave(null);
                        current = null;
                        renderDisplay();
                    } catch (err) {
                        this.showError(err.message || 'Failed to clear rating.');
                        clear.disabled = false;
                    }
                });
                wrap.append(' ', clear);
            }
        };

        const renderEditor = () => {
            wrap.innerHTML = '';
            const editor = document.createElement('div');
            editor.className = 'rating-editor';

            const selector = this.renderRatingSelector(current ?? null, async value => {
                if (value === current) {
                    renderDisplay();
                    return;
                }
                selector.querySelectorAll('.rating-btn').forEach(b => { b.disabled = true; });
                this.hideError();
                try {
                    await onSave(value);
                    current = value;
                    renderDisplay();
                } catch (err) {
                    this.showError(err.message || 'Failed to save rating.');
                    renderDisplay();
                }
            });

            const cancel = document.createElement('button');
            cancel.type = 'button';
            cancel.className = 'rating-cancel';
            cancel.textContent = '✕';
            cancel.title = 'Cancel';
            cancel.addEventListener('click', renderDisplay);

            editor.append(selector, cancel);
            wrap.appendChild(editor);
        };

        renderDisplay();
        return wrap;
    }

    buildInlineEditable(value, {inputType = 'text', min = 1, ariaLabel, onSave}) {
        const wrap = document.createElement('span');
        wrap.className = 'inline-editable';

        const normalize = (raw) => inputType === 'number' ? parseInt(raw, 10) : String(raw).trim();
        const isValid = (v) => inputType === 'number' ? Number.isInteger(v) && v >= min : v !== '';

        const showDisplay = () => {
            wrap.innerHTML = '';
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'inline-editable-value js-inline-edit';
            btn.textContent = value;
            btn.title = ariaLabel ? `Edit ${ariaLabel}` : 'Click to edit';
            btn.addEventListener('click', showEditor);
            wrap.appendChild(btn);
        };

        const showEditor = () => {
            wrap.innerHTML = '';
            const input = document.createElement('input');
            input.type = inputType;
            input.className = 'inline-editable-input';
            input.value = value;
            if (inputType === 'number') input.min = String(min);

            let settled = false;
            const cancel = () => { if (!settled) { settled = true; showDisplay(); } };
            const save = async () => {
                if (settled) return;
                const next = normalize(input.value);
                if (next === value || !isValid(next)) { cancel(); return; }
                settled = true;
                input.disabled = true;
                this.hideError();
                try {
                    await onSave(next);
                    value = next;
                } catch (err) {
                    this.showError(err.message || 'Failed to save.');
                }
                showDisplay();
            };

            input.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') { e.preventDefault(); save(); }
                else if (e.key === 'Escape') { e.preventDefault(); cancel(); }
            });
            input.addEventListener('blur', save);

            wrap.appendChild(input);
            input.focus();
            input.select();
        };

        showDisplay();
        return wrap;
    }

    buildAddEpisodeForm(seriesId, season, onAdded) {
        const form = document.createElement('form');
        form.className = 'add-episode-form';
        let selectedRating = null;

        const ratingSelector = this.renderRatingSelector(null, v => { selectedRating = v; });
        const nextNumber = season.episodes.length
            ? Math.max(...season.episodes.map(e => e.number)) + 1
            : 1;

        form.innerHTML = `
            <label>Episode number</label>
            <input type="number" min="1" class="js-episode-number" value="${nextNumber}" required>
            <label>Episode title</label>
            <input type="text" placeholder="e.g. Pilot" required>
            <div class="rating-row">
                <label style="margin:0">Rating (optional):</label>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary btn-sm">Add Episode</button>
                <button type="button" class="btn btn-secondary btn-sm js-cancel">Cancel</button>
            </div>
        `;

        form.querySelector('.rating-row').appendChild(ratingSelector);
        form.querySelector('.js-cancel').addEventListener('click', () => form.remove());

        form.addEventListener('submit', async e => {
            e.preventDefault();
            const title = form.querySelector('input[type=text]').value.trim();
            const number = parseInt(form.querySelector('.js-episode-number').value, 10);
            if (!title || !number || number < 1) return;

            const submitBtn = form.querySelector('[type=submit]');
            submitBtn.disabled = true;
            submitBtn.textContent = 'Adding…';
            this.hideError();

            try {
                const {id} = await API.addEpisode(seriesId, season.id, title, number, selectedRating);
                season.episodes.push({id, title, number, rating: selectedRating});
                onAdded();
            } catch (err) {
                this.showError(err.message || 'Failed to add episode.');
                submitBtn.disabled = false;
                submitBtn.textContent = 'Add Episode';
            }
        });

        return form;
    }

    renderSeasonBlock(seriesId, season, onUpdate, onDelete) {
        season.episodes.sort((a, b) => a.number - b.number);
        const seasonAvg = avg(season.episodes.map(e => e.rating));
        const watchedCount = season.episodes.filter(e => e.watched).length;
        const seasonFlag = ratingFlag({rating: season.rating, averageRating: seasonAvg, watchedCount, episodeCount: season.episodes.length});
        const block = document.createElement('div');
        block.className = 'season-block';
        block.dataset.seasonId = season.id;

        block.innerHTML = `
            <div class="season-header${seasonFlag.cls ? ` ${seasonFlag.cls}` : ''}"${seasonFlag.title ? ` title="${escHtml(seasonFlag.title)}"` : ''}>
                <h3>Season <span class="js-season-number"></span> <small style="font-weight:normal;color:#6b7280">${watchedCount}/${season.episodes.length} watched${seasonAvg !== null ? ` · avg ${seasonAvg}` : ''}</small></h3>
                <div class="season-header-actions">
                    <button type="button" class="btn btn-secondary btn-sm js-add-episode">+ Add Episode</button>
                    <button type="button" class="btn btn-danger btn-sm js-delete-season" title="Delete season">🗑</button>
                </div>
            </div>
            <div class="own-rating-row" data-season-own-rating></div>
            <table class="episodes-table">
                <thead><tr><th>#</th><th>Title</th><th>Watched</th><th>Rating</th></tr></thead>
                <tbody class="episodes-tbody">${
                    season.episodes.map((ep, i) => `
                        <tr class="${ep.watched ? 'episode-watched' : ''}">
                            <td>${ep.number}</td>
                            <td class="episode-title" data-ep-index="${i}"></td>
                            <td class="watched-cell" data-ep-index="${i}"></td>
                            <td class="rating-cell" data-ep-index="${i}"></td>
                            <td class="episode-actions" data-ep-index="${i}"></td>
                        </tr>
                    `).join('')
                }</tbody>
            </table>
        `;

        block.querySelector('.js-season-number').appendChild(
            this.buildInlineEditable(season.number, {
                inputType: 'number',
                min: 1,
                ariaLabel: 'season number',
                onSave: async (number) => {
                    await API.renumberSeason(seriesId, season.id, number);
                    season.number = number;
                },
            })
        );

        block.querySelectorAll('.episode-title').forEach(td => {
            const ep = season.episodes[Number(td.dataset.epIndex)];
            td.appendChild(this.buildInlineEditable(ep.title, {
                ariaLabel: 'episode title',
                onSave: async (title) => {
                    await API.renameEpisode(seriesId, season.id, ep.id, title);
                    ep.title = title;
                },
            }));
        });

        block.querySelector('[data-season-own-rating]').appendChild(
            this.buildOwnRatingControl(season.rating, async value => {
                await API.rateSeason(seriesId, season.id, value);
                season.rating = value;
            })
        );

        block.querySelectorAll('.watched-cell').forEach(td => {
            const ep = season.episodes[Number(td.dataset.epIndex)];
            this.renderWatchedCell(td, seriesId, season, ep, onUpdate);
        });

        block.querySelectorAll('.rating-cell').forEach(td => {
            const ep = season.episodes[Number(td.dataset.epIndex)];
            this.renderRatingCell(td, seriesId, season, ep, onUpdate);
        });

        block.querySelectorAll('.episode-actions').forEach(td => {
            const ep = season.episodes[Number(td.dataset.epIndex)];
            const del = document.createElement('button');
            del.type = 'button';
            del.className = 'btn-icon-danger js-delete-episode';
            del.textContent = '🗑';
            del.title = 'Delete episode';
            del.addEventListener('click', async () => {
                if (!confirm(`Delete episode "${ep.title}"?`)) return;
                del.disabled = true;
                this.hideError();
                try {
                    await API.deleteEpisode(seriesId, season.id, ep.id);
                    season.episodes = season.episodes.filter(e => e.id !== ep.id);
                    onUpdate();
                } catch (err) {
                    this.showError(err.message || 'Failed to delete episode.');
                    del.disabled = false;
                }
            });
            td.appendChild(del);
        });

        block.querySelector('.js-delete-season').addEventListener('click', async () => {
            if (!confirm(`Delete Season ${season.number} and all its episodes?`)) return;
            this.hideError();
            try {
                await API.deleteSeason(seriesId, season.id);
                onDelete();
            } catch (err) {
                this.showError(err.message || 'Failed to delete season.');
            }
        });

        block.querySelector('.js-add-episode').addEventListener('click', () => {
            if (block.querySelector('.add-episode-form')) return;
            const form = this.buildAddEpisodeForm(seriesId, season, () => {
                form.remove();
                onUpdate();
            });
            block.appendChild(form);
        });

        return block;
    }

    renderWatchedCell(td, seriesId, season, ep, onUpdate) {
        td.innerHTML = '';
        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.className = 'js-episode-watched';
        checkbox.checked = !!ep.watched;
        checkbox.title = ep.watched ? 'Mark as not watched' : 'Mark as watched';
        checkbox.addEventListener('change', async () => {
            const next = checkbox.checked;
            checkbox.disabled = true;
            this.hideError();
            try {
                await API.setEpisodeWatched(seriesId, season.id, ep.id, next);
                ep.watched = next;
                ep.watchedAt = next ? new Date().toISOString() : null;
                onUpdate();
            } catch (err) {
                checkbox.checked = !next;
                checkbox.disabled = false;
                this.showError(err.message || 'Failed to update watched status.');
            }
        });
        td.appendChild(checkbox);
    }

    renderRatingCell(td, seriesId, season, ep, onUpdate) {
        const rated = ep.rating !== null && ep.rating !== undefined;
        td.innerHTML = '';
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'rating-cell-btn' + (rated ? '' : ' rating-cell-empty');
        btn.textContent = rated ? `★ ${ep.rating}` : 'Rate';
        btn.title = rated ? 'Change rating' : 'Rate this episode';
        btn.addEventListener('click', () => this.openRatingEditor(td, seriesId, season, ep, onUpdate));
        td.appendChild(btn);
    }

    openRatingEditor(td, seriesId, season, ep, onUpdate) {
        td.innerHTML = '';
        const editor = document.createElement('div');
        editor.className = 'rating-editor';

        const selector = this.renderRatingSelector(ep.rating ?? null, async value => {
            if (value === ep.rating) {
                this.renderRatingCell(td, seriesId, season, ep, onUpdate);
                return;
            }
            selector.querySelectorAll('.rating-btn').forEach(b => { b.disabled = true; });
            this.hideError();
            try {
                await API.rateEpisode(seriesId, season.id, ep.id, value);
                ep.rating = value;
                onUpdate();
            } catch (err) {
                this.showError(err.message || 'Failed to rate episode.');
                this.renderRatingCell(td, seriesId, season, ep, onUpdate);
            }
        });

        const cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.className = 'rating-cancel js-cancel-rate';
        cancel.textContent = '✕';
        cancel.title = 'Cancel';
        cancel.addEventListener('click', () => this.renderRatingCell(td, seriesId, season, ep, onUpdate));

        editor.appendChild(selector);
        editor.appendChild(cancel);
        td.appendChild(editor);
    }

    renderDetail(series) {
        const container = this.$('series-detail-content');
        const seriesAvg = avg(
            series.seasons.flatMap(s => s.episodes.map(e => e.rating))
        );
        const seriesWatched = series.seasons.reduce((n, s) => n + s.episodes.filter(e => e.watched).length, 0);
        const seriesEpisodes = series.seasons.reduce((n, s) => n + s.episodes.length, 0);
        const headerFlag = ratingFlag({rating: series.rating, averageRating: seriesAvg, watchedCount: seriesWatched, episodeCount: seriesEpisodes});
        const poster = safeUrl(series.coverUrl);
        const catalogBits = [series.year, statusLabel(series.status)].filter(Boolean);

        container.innerHTML = `
            <div class="series-detail-header${headerFlag.cls ? ` ${headerFlag.cls}` : ''}"${headerFlag.title ? ` title="${escHtml(headerFlag.title)}"` : ''}>
                <div class="series-detail-poster">
                    ${poster
                        ? `<img src="${escHtml(poster)}" alt="" loading="lazy">`
                        : '<span class="series-card-poster-empty">No poster</span>'}
                </div>
                <div class="series-detail-info">
                    <h2 id="series-title-edit"></h2>
                    ${catalogBits.length ? `<div class="series-catalog-meta">${escHtml(catalogBits.join(' · '))}</div>` : ''}
                    <div class="meta">
                        ${seriesAvg !== null ? `Average rating: <strong>★ ${seriesAvg}</strong>` : 'No ratings yet'}
                        · ${series.seasons.length} season(s)
                    </div>
                    <div class="own-rating-row" id="series-own-rating"></div>
                    ${series.description ? `<p class="series-description">${escHtml(series.description)}</p>` : ''}
                </div>
            </div>
            <div class="section-actions">
                <button type="button" class="btn btn-secondary btn-sm" id="btn-edit-details">✎ Edit details</button>
                <button type="button" class="btn btn-secondary btn-sm" id="btn-add-season">+ Add Season</button>
                <button type="button" class="btn btn-danger btn-sm" id="btn-delete-series">🗑 Delete series</button>
            </div>
            <div id="seasons-container"></div>
        `;

        container.querySelector('#series-title-edit').appendChild(
            this.buildInlineEditable(series.title, {
                ariaLabel: 'series title',
                onSave: async (title) => {
                    await API.renameSeries(series.id, title);
                    series.title = title;
                },
            })
        );

        container.querySelector('#series-own-rating').appendChild(
            this.buildOwnRatingControl(series.rating, async value => {
                await API.rateSeries(series.id, value);
                series.rating = value;
            })
        );

        const updateMeta = () => {
            const detailAvg = avg(series.seasons.flatMap(s => s.episodes.map(e => e.rating)));
            container.querySelector('.meta').innerHTML =
                `${detailAvg !== null ? `Average rating: <strong>★ ${detailAvg}</strong>` : 'No ratings yet'} · ${series.seasons.length} season(s)`;
        };

        const seasonsContainer = container.querySelector('#seasons-container');
        const renderSeasons = () => {
            seasonsContainer.innerHTML = '';
            series.seasons.forEach(season => {
                seasonsContainer.appendChild(
                    this.renderSeasonBlock(
                        series.id,
                        season,
                        () => { updateMeta(); renderSeasons(); },
                        () => {
                            series.seasons = series.seasons.filter(s => s.id !== season.id);
                            updateMeta();
                            renderSeasons();
                        }
                    )
                );
            });
        };
        renderSeasons();

        container.querySelector('#btn-edit-details').addEventListener('click', () => {
            if (container.querySelector('.edit-details-form')) return;
            const form = this.buildEditDetailsForm(series, () => this.loadDetail(series.id));
            container.querySelector('.section-actions').after(form);
        });

        container.querySelector('#btn-add-season').addEventListener('click', () => {
            if (container.querySelector('.add-season-form')) return;
            const form = this.buildAddSeasonForm(series, () => renderSeasons());
            container.querySelector('.section-actions').after(form);
        });

        container.querySelector('#btn-delete-series').addEventListener('click', async () => {
            if (!confirm(`Delete "${series.title}" and all its seasons and episodes?`)) return;
            this.hideError();
            try {
                await API.deleteSeries(series.id);
                this.hide(this.$('series-detail-view'));
                this.show(this.$('series-list-view'));
                this.loadSeriesList();
            } catch (err) {
                this.showError(err.message || 'Failed to delete series.');
            }
        });
    }

    buildAddSeasonForm(series, onAdded) {
        const form = document.createElement('form');
        form.className = 'add-season-form';
        form.innerHTML = `
            <label>Season number</label>
            <input type="number" min="1" value="${series.seasons.length + 1}" required>
            <button type="submit" class="btn btn-primary btn-sm">Add Season</button>
            <button type="button" class="btn btn-secondary btn-sm js-cancel">Cancel</button>
        `;
        form.querySelector('.js-cancel').addEventListener('click', () => form.remove());
        form.addEventListener('submit', async e => {
            e.preventDefault();
            const number = parseInt(form.querySelector('input').value, 10);
            if (!number || number < 1) return;
            const submitBtn = form.querySelector('[type=submit]');
            submitBtn.disabled = true;
            submitBtn.textContent = 'Adding…';
            this.hideError();
            try {
                const {id} = await API.addSeason(series.id, number);
                series.seasons.push({id, number, episodes: []});
                form.remove();
                onAdded();
            } catch (err) {
                this.showError(err.message || 'Failed to add season.');
                submitBtn.disabled = false;
                submitBtn.textContent = 'Add Season';
            }
        });
        return form;
    }

    buildEditDetailsForm(series, onSaved) {
        const form = document.createElement('form');
        form.className = 'edit-details-form';
        form.innerHTML = `
            <label>Poster URL</label>
            <input type="url" class="js-meta-cover" placeholder="https://…">
            <label>Year</label>
            <input type="number" min="1900" class="js-meta-year" placeholder="e.g. 2008">
            <label>Status</label>
            <select class="js-meta-status">
                <option value="">—</option>
                <option value="ongoing">Ongoing</option>
                <option value="ended">Ended</option>
            </select>
            <label>Description</label>
            <textarea class="js-meta-description" rows="3" placeholder="Short synopsis…"></textarea>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary btn-sm">Save details</button>
                <button type="button" class="btn btn-secondary btn-sm js-cancel">Cancel</button>
            </div>
        `;

        form.querySelector('.js-meta-cover').value = series.coverUrl ?? '';
        form.querySelector('.js-meta-year').value = series.year ?? '';
        form.querySelector('.js-meta-status').value = series.status ?? '';
        form.querySelector('.js-meta-description').value = series.description ?? '';

        form.querySelector('.js-cancel').addEventListener('click', () => form.remove());

        form.addEventListener('submit', async e => {
            e.preventDefault();
            const submitBtn = form.querySelector('[type=submit]');
            submitBtn.disabled = true;
            submitBtn.textContent = 'Saving…';
            this.hideError();
            try {
                await API.updateSeries(series.id, {title: series.title, ...this.readMetadataInputs(form)});
                onSaved();
            } catch (err) {
                this.showError(err.message || 'Failed to save details.');
                submitBtn.disabled = false;
                submitBtn.textContent = 'Save details';
            }
        });

        return form;
    }

    readMetadataInputs(root) {
        const cover = (root.querySelector('.js-meta-cover')?.value || '').trim();
        const yearRaw = (root.querySelector('.js-meta-year')?.value || '').trim();
        const status = root.querySelector('.js-meta-status')?.value || '';
        const description = (root.querySelector('.js-meta-description')?.value || '').trim();

        return {
            coverUrl: cover || null,
            year: yearRaw ? parseInt(yearRaw, 10) : null,
            status: status || null,
            description: description || null,
        };
    }

    async loadDetail(id) {
        this.hide(this.$('series-list-view'));
        this.show(this.$('series-detail-view'));
        this.$('series-detail-content').innerHTML = '<div class="loading">Loading…</div>';
        this.hideError();

        try {
            const series = await API.seriesDetail(id);
            this.renderDetail(series);
        } catch {
            this.showError('Failed to load series detail.');
            this.$('series-detail-content').innerHTML = '';
        }
    }


    initAddSeriesModal() {
        const modal = this.$('modal-add-series');
        const form = this.$('form-add-series');
        const input = this.$('input-series-title');

        this.$('btn-add-series').addEventListener('click', () => {
            form.reset();
            this.show(modal);
            input.focus();
        });
        this.$('btn-cancel-series').addEventListener('click', () => this.hide(modal));
        modal.addEventListener('click', e => { if (e.target === modal) this.hide(modal); });

        form.addEventListener('submit', async e => {
            e.preventDefault();
            const title = input.value.trim();
            if (!title) return;
            const submitBtn = form.querySelector('[type=submit]');
            submitBtn.disabled = true;
            submitBtn.textContent = 'Creating…';
            this.hideError();

            try {
                await API.createSeries({title, ...this.readMetadataInputs(form)});
                this.hide(modal);
                submitBtn.disabled = false;
                submitBtn.textContent = 'Create';
                await this.loadSeriesList();
            } catch (err) {
                this.showError(err.message || 'Failed to create series.');
                submitBtn.disabled = false;
                submitBtn.textContent = 'Create';
            }
        });
    }


    initNavigation() {
        this.$('btn-back').addEventListener('click', () => {
            this.hide(this.$('series-detail-view'));
            this.show(this.$('series-list-view'));
            this.hideError();
            this.loadSeriesList();
        });
    }
}
