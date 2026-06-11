import { Controller } from '@hotwired/stimulus';
import { TOAST_TIMEOUT_MS, apiCall, escHtml } from '../util.js';

const API = {
    series: () => apiCall('/api/series'),
    seriesDetail: (id) => apiCall(`/api/series/${id}`),
    // Mutations go through apiCall so the X-API-Key meta header is attached —
    // a bare fetch() skips it and the stateless api firewall answers 401 (HMAI-176).
    createSeries: (title) => apiCall('/api/series', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({title}),
    }),
    addSeason: (seriesId, number) => apiCall(`/api/series/${seriesId}/seasons`, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({number}),
    }),
    addEpisode: (seriesId, seasonId, title, rating) => apiCall(
        `/api/series/${seriesId}/seasons/${seasonId}/episodes`, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({title, rating: rating || null}),
    }),
    // PATCH — sets/changes the rating of an existing episode. Returns 204
    // (apiCall → null) on success, 422 for a rating outside 1–10, 404 if missing.
    rateEpisode: (seriesId, seasonId, episodeId, rating) => apiCall(
        `/api/series/${seriesId}/seasons/${seasonId}/episodes/${episodeId}/rating`, {
        method: 'PATCH',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({rating}),
    }),
    // PATCH — the user's own (manual) whole-series score, independent of the
    // episode-derived average. Same 204/422/404 contract as rateEpisode.
    rateSeries: (seriesId, rating) => apiCall(`/api/series/${seriesId}/rating`, {
        method: 'PATCH',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({rating}),
    }),
    // PATCH — the user's own (manual) season score, independent of the average.
    rateSeason: (seriesId, seasonId, rating) => apiCall(
        `/api/series/${seriesId}/seasons/${seasonId}/rating`, {
        method: 'PATCH',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({rating}),
    }),
};

function avg(nums) {
    const filtered = nums.filter(n => n !== null && n !== undefined);
    if (!filtered.length) return null;
    return Math.round((filtered.reduce((a, b) => a + b, 0) / filtered.length) * 100) / 100;
}

function ratingBadge(value) {
    if (value === null || value === undefined) return '<span class="no-rating">no rating</span>';
    return `<span class="rating-badge">★ ${value}</span>`;
}

export default class extends Controller {
    connect() {
        this.initAddSeriesModal();
        this.initNavigation();
        this.loadSeriesList();
    }

    // ── shared dom helpers — scoped to the controller's element subtree ──

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

    // ── series list ──

    renderSeriesList(seriesArr) {
        const container = this.$('series-list');
        if (!seriesArr.length) {
            container.innerHTML = '<div class="empty-state">No series yet. Add your first one!</div>';
            return;
        }
        container.innerHTML = seriesArr.map(s => `
            <div class="series-card" data-id="${s.id}">
                <h3>${escHtml(s.title)}</h3>
                ${ratingBadge(s.averageRating)}
            </div>
        `).join('');

        container.querySelectorAll('.series-card').forEach(card => {
            card.addEventListener('click', () => this.loadDetail(card.dataset.id));
        });
    }

    async loadSeriesList() {
        const container = this.$('series-list');
        container.innerHTML = '<div class="loading">Loading…</div>';
        try {
            const data = await API.series();
            this.renderSeriesList(data);
        } catch {
            this.showError('Failed to load series. Is the backend running?');
            container.innerHTML = '';
        }
    }

    // ── series detail ──

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

    // Inline "My rating" control (display ↔ editor) for a series or season
    // header. `current` is the user's own manual score (or null); `onSave`
    // PATCHes it and must resolve before the display updates. Shown alongside —
    // and fully independent of — the episode-derived average (HMAI-179).
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

    buildAddEpisodeForm(seriesId, season, onAdded) {
        const form = document.createElement('form');
        form.className = 'add-episode-form';
        let selectedRating = null;

        const ratingSelector = this.renderRatingSelector(null, v => { selectedRating = v; });

        form.innerHTML = `
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
            const title = form.querySelector('input').value.trim();
            if (!title) return;

            const submitBtn = form.querySelector('[type=submit]');
            submitBtn.disabled = true;
            submitBtn.textContent = 'Adding…';
            this.hideError();

            try {
                const {id} = await API.addEpisode(seriesId, season.id, title, selectedRating);
                season.episodes.push({id, title, rating: selectedRating});
                onAdded();
            } catch (err) {
                this.showError(err.message || 'Failed to add episode.');
                submitBtn.disabled = false;
                submitBtn.textContent = 'Add Episode';
            }
        });

        return form;
    }

    renderSeasonBlock(seriesId, season, onUpdate) {
        const seasonAvg = avg(season.episodes.map(e => e.rating));
        const block = document.createElement('div');
        block.className = 'season-block';
        block.dataset.seasonId = season.id;

        block.innerHTML = `
            <div class="season-header">
                <h3>Season ${season.number} ${seasonAvg !== null ? `<small style="font-weight:normal;color:#6b7280">avg ${seasonAvg}</small>` : ''}</h3>
                <button type="button" class="btn btn-secondary btn-sm js-add-episode">+ Add Episode</button>
            </div>
            <div class="own-rating-row" data-season-own-rating></div>
            <table class="episodes-table">
                <thead><tr><th>#</th><th>Title</th><th>Rating</th></tr></thead>
                <tbody class="episodes-tbody">${
                    season.episodes.map((ep, i) => `
                        <tr>
                            <td>${i + 1}</td>
                            <td>${escHtml(ep.title)}</td>
                            <td class="rating-cell" data-ep-index="${i}"></td>
                        </tr>
                    `).join('')
                }</tbody>
            </table>
        `;

        // Season's own (manual) rating control — independent of the avg above.
        // Saving updates the model in place; no full re-render needed.
        block.querySelector('[data-season-own-rating]').appendChild(
            this.buildOwnRatingControl(season.rating, async value => {
                await API.rateSeason(seriesId, season.id, value);
                season.rating = value;
            })
        );

        // Hydrate each Rating cell into a clickable control (display ↔ inline
        // selector). Built post-innerHTML so each cell binds to its episode object.
        block.querySelectorAll('.rating-cell').forEach(td => {
            const ep = season.episodes[Number(td.dataset.epIndex)];
            this.renderRatingCell(td, seriesId, season, ep, onUpdate);
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

    // Display mode for an episode's Rating cell: a button showing the current
    // score (or "Rate"); clicking swaps the cell into the inline selector.
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

    // Edit mode: the 10-button selector (current score highlighted) plus a
    // cancel affordance. Picking a value PATCHes the episode; on success the
    // model is updated and onUpdate() re-renders so season/series averages
    // refresh immediately. Re-picking the current score is a no-op close.
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

        container.innerHTML = `
            <div class="series-detail-header">
                <h2>${escHtml(series.title)}</h2>
                <div class="meta">
                    ${seriesAvg !== null ? `Average rating: <strong>★ ${seriesAvg}</strong>` : 'No ratings yet'}
                    · ${series.seasons.length} season(s)
                </div>
                <div class="own-rating-row" id="series-own-rating"></div>
            </div>
            <div class="section-actions">
                <button type="button" class="btn btn-secondary btn-sm" id="btn-add-season">+ Add Season</button>
            </div>
            <div id="seasons-container"></div>
        `;

        // Series' own (manual) rating control — independent of the average above.
        container.querySelector('#series-own-rating').appendChild(
            this.buildOwnRatingControl(series.rating, async value => {
                await API.rateSeries(series.id, value);
                series.rating = value;
            })
        );

        const seasonsContainer = container.querySelector('#seasons-container');
        const renderSeasons = () => {
            seasonsContainer.innerHTML = '';
            series.seasons.forEach(season => {
                seasonsContainer.appendChild(
                    this.renderSeasonBlock(series.id, season, () => {
                        const detailAvg = avg(series.seasons.flatMap(s => s.episodes.map(e => e.rating)));
                        container.querySelector('.meta').innerHTML =
                            `${detailAvg !== null ? `Average rating: <strong>★ ${detailAvg}</strong>` : 'No ratings yet'} · ${series.seasons.length} season(s)`;
                        renderSeasons();
                    })
                );
            });
        };
        renderSeasons();

        container.querySelector('#btn-add-season').addEventListener('click', () => {
            if (container.querySelector('.add-season-form')) return;
            const form = this.buildAddSeasonForm(series, () => renderSeasons());
            container.querySelector('.section-actions').after(form);
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

    // ── add series modal ──

    initAddSeriesModal() {
        const modal = this.$('modal-add-series');
        const form = this.$('form-add-series');
        const input = this.$('input-series-title');

        this.$('btn-add-series').addEventListener('click', () => {
            input.value = '';
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
                await API.createSeries(title);
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

    // ── navigation ──

    initNavigation() {
        this.$('btn-back').addEventListener('click', () => {
            this.hide(this.$('series-detail-view'));
            this.show(this.$('series-list-view'));
            this.hideError();
            this.loadSeriesList();
        });
    }
}
