import { escHtml } from '../util.js';
import { API } from './api.js';
import { hideError, showError } from './banners.js';
import { avg, ratingFlag } from './ratings.js';
import { buildInlineEditable } from './inline-edit.js';
import { buildOwnRatingControl, renderRatingSelector } from './rating-controls.js';

// Add-episode form appended under a season. `onAdded()` runs after a successful
// create (the caller removes the form and re-renders).
export function buildAddEpisodeForm(seriesId, season, onAdded) {
    const form = document.createElement('form');
    form.className = 'add-episode-form';
    let selectedRating = null;

    const ratingSelector = renderRatingSelector(null, v => { selectedRating = v; });
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
        hideError();

        try {
            const {id} = await API.addEpisode(seriesId, season.id, title, number, selectedRating);
            season.episodes.push({id, title, number, rating: selectedRating});
            onAdded();
        } catch (err) {
            showError(err.message || 'Failed to add episode.');
            submitBtn.disabled = false;
            submitBtn.textContent = 'Add Episode';
        }
    });

    return form;
}

// Renders a full season block (header + episodes table). `onUpdate()` is called
// whenever episode data changes (re-render + recompute averages); `onDelete()`
// when the whole season is removed.
export function renderSeasonBlock(seriesId, season, onUpdate, onDelete) {
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
        buildInlineEditable(season.number, {
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
        td.appendChild(buildInlineEditable(ep.title, {
            ariaLabel: 'episode title',
            onSave: async (title) => {
                await API.renameEpisode(seriesId, season.id, ep.id, title);
                ep.title = title;
            },
        }));
    });

    block.querySelector('[data-season-own-rating]').appendChild(
        buildOwnRatingControl(season.rating, async value => {
            await API.rateSeason(seriesId, season.id, value);
            season.rating = value;
        })
    );

    block.querySelectorAll('.watched-cell').forEach(td => {
        const ep = season.episodes[Number(td.dataset.epIndex)];
        renderWatchedCell(td, seriesId, season, ep, onUpdate);
    });

    block.querySelectorAll('.rating-cell').forEach(td => {
        const ep = season.episodes[Number(td.dataset.epIndex)];
        renderRatingCell(td, seriesId, season, ep, onUpdate);
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
            hideError();
            try {
                await API.deleteEpisode(seriesId, season.id, ep.id);
                season.episodes = season.episodes.filter(e => e.id !== ep.id);
                onUpdate();
            } catch (err) {
                showError(err.message || 'Failed to delete episode.');
                del.disabled = false;
            }
        });
        td.appendChild(del);
    });

    block.querySelector('.js-delete-season').addEventListener('click', async () => {
        if (!confirm(`Delete Season ${season.number} and all its episodes?`)) return;
        hideError();
        try {
            await API.deleteSeason(seriesId, season.id);
            onDelete();
        } catch (err) {
            showError(err.message || 'Failed to delete season.');
        }
    });

    block.querySelector('.js-add-episode').addEventListener('click', () => {
        if (block.querySelector('.add-episode-form')) return;
        const form = buildAddEpisodeForm(seriesId, season, () => {
            form.remove();
            onUpdate();
        });
        block.appendChild(form);
    });

    return block;
}

function renderWatchedCell(td, seriesId, season, ep, onUpdate) {
    td.innerHTML = '';
    const checkbox = document.createElement('input');
    checkbox.type = 'checkbox';
    checkbox.className = 'js-episode-watched';
    checkbox.checked = !!ep.watched;
    checkbox.title = ep.watched ? 'Mark as not watched' : 'Mark as watched';
    checkbox.addEventListener('change', async () => {
        const next = checkbox.checked;
        checkbox.disabled = true;
        hideError();
        try {
            await API.setEpisodeWatched(seriesId, season.id, ep.id, next);
            ep.watched = next;
            ep.watchedAt = next ? new Date().toISOString() : null;
            onUpdate();
        } catch (err) {
            checkbox.checked = !next;
            checkbox.disabled = false;
            showError(err.message || 'Failed to update watched status.');
        }
    });
    td.appendChild(checkbox);
}

function renderRatingCell(td, seriesId, season, ep, onUpdate) {
    const rated = ep.rating !== null && ep.rating !== undefined;
    td.innerHTML = '';
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'rating-cell-btn' + (rated ? '' : ' rating-cell-empty');
    btn.textContent = rated ? `★ ${ep.rating}` : 'Rate';
    btn.title = rated ? 'Change rating' : 'Rate this episode';
    btn.addEventListener('click', () => openRatingEditor(td, seriesId, season, ep, onUpdate));
    td.appendChild(btn);
}

function openRatingEditor(td, seriesId, season, ep, onUpdate) {
    td.innerHTML = '';
    const editor = document.createElement('div');
    editor.className = 'rating-editor';

    const selector = renderRatingSelector(ep.rating ?? null, async value => {
        if (value === ep.rating) {
            renderRatingCell(td, seriesId, season, ep, onUpdate);
            return;
        }
        selector.querySelectorAll('.rating-btn').forEach(b => { b.disabled = true; });
        hideError();
        try {
            await API.rateEpisode(seriesId, season.id, ep.id, value);
            ep.rating = value;
            onUpdate();
        } catch (err) {
            showError(err.message || 'Failed to rate episode.');
            renderRatingCell(td, seriesId, season, ep, onUpdate);
        }
    });

    const cancel = document.createElement('button');
    cancel.type = 'button';
    cancel.className = 'rating-cancel js-cancel-rate';
    cancel.textContent = '✕';
    cancel.title = 'Cancel';
    cancel.addEventListener('click', () => renderRatingCell(td, seriesId, season, ep, onUpdate));

    editor.appendChild(selector);
    editor.appendChild(cancel);
    td.appendChild(editor);
}
