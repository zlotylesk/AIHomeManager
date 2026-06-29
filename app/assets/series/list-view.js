import { escHtml, safeUrl } from '../util.js';
import { cardRating, cardRatingFlag, statusLabel } from './ratings.js';

// Renders the series grid into `container`. `onSelect(id)` fires when a card is
// clicked (the controller navigates to the detail view).
export function renderSeriesList(container, seriesArr, onSelect) {
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
        card.addEventListener('click', () => onSelect(card.dataset.id));
    });
}
