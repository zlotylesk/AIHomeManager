import { Controller } from '@hotwired/stimulus';
import { apiCall, escHtml, safeUrl } from '../util.js';
import { facetCounts, groupByType, groupHeading } from '../search/format.js';

const DEBOUNCE_MS = 250;
const MIN_QUERY_LENGTH = 2;

function renderResult(result) {
    const url = safeUrl(result.url) || '#';
    const title = escHtml(result.title ?? '');
    const snippet = result.snippet
        ? `<span class="search-result-snippet">${escHtml(result.snippet)}</span>`
        : '';

    return `<a class="search-result" href="${escHtml(url)}"><span class="search-result-title">${title}</span>${snippet}</a>`;
}

function renderGroup(group, counts) {
    return `<div class="search-group"><div class="search-group-label">${escHtml(groupHeading(group, counts))}</div>${group.items.map(renderResult).join('')}</div>`;
}

export default class extends Controller {
    static targets = ['input', 'results'];

    connect() {
        this.timer = null;
        this.onDocumentClick = (event) => {
            if (!this.element.contains(event.target)) {
                this.hide();
            }
        };
        document.addEventListener('click', this.onDocumentClick);
    }

    disconnect() {
        document.removeEventListener('click', this.onDocumentClick);
        if (this.timer) {
            clearTimeout(this.timer);
        }
    }

    search() {
        if (this.timer) {
            clearTimeout(this.timer);
        }

        const term = this.inputTarget.value.trim();
        if (term.length < MIN_QUERY_LENGTH) {
            this.hide();
            return;
        }

        this.timer = setTimeout(() => this.run(term), DEBOUNCE_MS);
    }

    async run(term) {
        this.show('<div class="search-loading">Szukam…</div>');

        const query = encodeURIComponent(term);

        try {
            // Both reads are issued together so the counts do not add a second
            // round trip to every keystroke. The facets are an enhancement, not
            // a requirement: `catch` turns their failure into "no counts" so a
            // broken facet read can never cost the user the results themselves.
            const [results, facets] = await Promise.all([
                apiCall(`/api/search?q=${query}`),
                apiCall(`/api/search/facets?q=${query}`).catch(() => []),
            ]);

            if (!Array.isArray(results) || 0 === results.length) {
                this.show('<div class="search-empty">Brak wyników.</div>');
                return;
            }

            const counts = facetCounts(facets);
            this.show(groupByType(results).map((group) => renderGroup(group, counts)).join(''));
        } catch {
            this.show('<div class="search-error">Wyszukiwanie nie powiodło się.</div>');
        }
    }

    show(html) {
        this.resultsTarget.innerHTML = html;
        this.resultsTarget.hidden = false;
    }

    hide() {
        this.resultsTarget.hidden = true;
        this.resultsTarget.innerHTML = '';
    }
}
