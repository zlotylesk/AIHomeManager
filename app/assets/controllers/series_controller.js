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
    // PATCH — toggles an episode's watched flag (HMAI-188). 204 on success,
    // 422 for a non-boolean body, 404 if the episode is missing.
    setEpisodeWatched: (seriesId, seasonId, episodeId, watched) => apiCall(
        `/api/series/${seriesId}/seasons/${seasonId}/episodes/${episodeId}/watched`, {
        method: 'PATCH',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({watched}),
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
    // DELETE — cascades on the server (series → seasons → episodes). 204 → null
    // on success, 404 if already gone.
    deleteSeries: (seriesId) => apiCall(`/api/series/${seriesId}`, {method: 'DELETE'}),
    deleteSeason: (seriesId, seasonId) => apiCall(
        `/api/series/${seriesId}/seasons/${seasonId}`, {method: 'DELETE'}),
    deleteEpisode: (seriesId, seasonId, episodeId) => apiCall(
        `/api/series/${seriesId}/seasons/${seasonId}/episodes/${episodeId}`, {method: 'DELETE'}),
    // PATCH — edit metadata. 204 → null on success; 422 (bad title/number),
    // 404 (missing), 409 (season number already used in the series).
    renameSeries: (seriesId, title) => apiCall(`/api/series/${seriesId}`, {
        method: 'PATCH',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({title}),
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

            // Clear affordance — only when a manual rating exists (HMAI-191).
            // PATCHes {rating: null} and reverts the control to "Rate".
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

    // Inline text/number editor (HMAI-186). Renders `value` as a clickable
    // control; clicking swaps it for an <input>. Enter or blur saves, Esc
    // cancels. onSave(newValue) must resolve before the display updates; a
    // rejection surfaces via showError and restores the previous value.
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

            // Enter→save re-renders and removes the input, which fires blur and
            // would save again — `settled` collapses both into one action.
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

    renderSeasonBlock(seriesId, season, onUpdate, onDelete) {
        const seasonAvg = avg(season.episodes.map(e => e.rating));
        const watchedCount = season.episodes.filter(e => e.watched).length;
        const block = document.createElement('div');
        block.className = 'season-block';
        block.dataset.seasonId = season.id;

        block.innerHTML = `
            <div class="season-header">
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
                            <td>${i + 1}</td>
                            <td class="episode-title" data-ep-index="${i}"></td>
                            <td class="watched-cell" data-ep-index="${i}"></td>
                            <td class="rating-cell" data-ep-index="${i}"></td>
                            <td class="episode-actions" data-ep-index="${i}"></td>
                        </tr>
                    `).join('')
                }</tbody>
            </table>
        `;

        // Inline-editable season number (HMAI-186) — renumber persists; a clash
        // with another season's number surfaces a 409 via showError.
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

        // Inline-editable episode titles.
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

        // Season's own (manual) rating control — independent of the avg above.
        // Saving updates the model in place; no full re-render needed.
        block.querySelector('[data-season-own-rating]').appendChild(
            this.buildOwnRatingControl(season.rating, async value => {
                await API.rateSeason(seriesId, season.id, value);
                season.rating = value;
            })
        );

        // Hydrate each Watched cell into a checkbox toggle bound to its episode.
        block.querySelectorAll('.watched-cell').forEach(td => {
            const ep = season.episodes[Number(td.dataset.epIndex)];
            this.renderWatchedCell(td, seriesId, season, ep, onUpdate);
        });

        // Hydrate each Rating cell into a clickable control (display ↔ inline
        // selector). Built post-innerHTML so each cell binds to its episode object.
        block.querySelectorAll('.rating-cell').forEach(td => {
            const ep = season.episodes[Number(td.dataset.epIndex)];
            this.renderRatingCell(td, seriesId, season, ep, onUpdate);
        });

        // Per-episode delete button. On success the episode is dropped from the
        // model and onUpdate() re-renders so the table + averages refresh.
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

    // Watched toggle for an episode (HMAI-188). Flipping it PATCHes the flag;
    // on success the model updates and onUpdate() re-renders so the season's
    // "X/Y watched" counter refreshes. On failure the checkbox reverts.
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
                <h2 id="series-title-edit"></h2>
                <div class="meta">
                    ${seriesAvg !== null ? `Average rating: <strong>★ ${seriesAvg}</strong>` : 'No ratings yet'}
                    · ${series.seasons.length} season(s)
                </div>
                <div class="own-rating-row" id="series-own-rating"></div>
            </div>
            <div class="section-actions">
                <button type="button" class="btn btn-secondary btn-sm" id="btn-add-season">+ Add Season</button>
                <button type="button" class="btn btn-danger btn-sm" id="btn-delete-series">🗑 Delete series</button>
            </div>
            <div id="seasons-container"></div>
        `;

        // Inline-editable series title (HMAI-186).
        container.querySelector('#series-title-edit').appendChild(
            this.buildInlineEditable(series.title, {
                ariaLabel: 'series title',
                onSave: async (title) => {
                    await API.renameSeries(series.id, title);
                    series.title = title;
                },
            })
        );

        // Series' own (manual) rating control — independent of the average above.
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
