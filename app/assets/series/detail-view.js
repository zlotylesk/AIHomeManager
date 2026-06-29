import { escHtml, safeUrl } from '../util.js';
import { API } from './api.js';
import { hideError, showError } from './banners.js';
import { avg, ratingFlag, statusLabel } from './ratings.js';
import { buildInlineEditable } from './inline-edit.js';
import { buildOwnRatingControl } from './rating-controls.js';
import { renderSeasonBlock } from './season-view.js';

// Renders the series detail page into `container`. Navigation is delegated to
// the controller: `reloadDetail()` re-fetches this series, `backToList()`
// returns to the list view after a delete.
export function renderDetail(container, series, {reloadDetail, backToList}) {
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
        buildInlineEditable(series.title, {
            ariaLabel: 'series title',
            onSave: async (title) => {
                await API.renameSeries(series.id, title);
                series.title = title;
            },
        })
    );

    container.querySelector('#series-own-rating').appendChild(
        buildOwnRatingControl(series.rating, async value => {
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
                renderSeasonBlock(
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
        const form = buildEditDetailsForm(series, reloadDetail);
        container.querySelector('.section-actions').after(form);
    });

    container.querySelector('#btn-add-season').addEventListener('click', () => {
        if (container.querySelector('.add-season-form')) return;
        const form = buildAddSeasonForm(series, () => renderSeasons());
        container.querySelector('.section-actions').after(form);
    });

    container.querySelector('#btn-delete-series').addEventListener('click', async () => {
        if (!confirm(`Delete "${series.title}" and all its seasons and episodes?`)) return;
        hideError();
        try {
            await API.deleteSeries(series.id);
            backToList();
        } catch (err) {
            showError(err.message || 'Failed to delete series.');
        }
    });
}

export function buildAddSeasonForm(series, onAdded) {
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
        hideError();
        try {
            const {id} = await API.addSeason(series.id, number);
            series.seasons.push({id, number, episodes: []});
            form.remove();
            onAdded();
        } catch (err) {
            showError(err.message || 'Failed to add season.');
            submitBtn.disabled = false;
            submitBtn.textContent = 'Add Season';
        }
    });
    return form;
}

export function buildEditDetailsForm(series, onSaved) {
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
        hideError();
        try {
            await API.updateSeries(series.id, {title: series.title, ...readMetadataInputs(form)});
            onSaved();
        } catch (err) {
            showError(err.message || 'Failed to save details.');
            submitBtn.disabled = false;
            submitBtn.textContent = 'Save details';
        }
    });

    return form;
}

// Reads the shared `.js-meta-*` catalog inputs out of a form root (the create
// modal and the edit-details form share the same field set). Pure DOM → object.
export function readMetadataInputs(root) {
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
