import { Controller } from '@hotwired/stimulus';
import { API } from '../series/api.js';
import { hideError, showError, showInfo, showTraktConnectPrompt } from '../series/banners.js';
import { filterSeries, sortSeries } from '../series/list.js';
import { renderSeriesList } from '../series/list-view.js';
import { readMetadataInputs, renderDetail } from '../series/detail-view.js';
import { fetchAllPages } from '../pagination.js';

// Thin Stimulus controller for the Series page: lifecycle wiring, list/detail
// orchestration and the add-series modal. The heavy DOM building lives in the
// ./series/* modules (list-view, detail-view, season-view, rating-controls,
// inline-edit) and the pure helpers in ./series/{ratings,list}.js.
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

    initImportTrakt() {
        const btn = this.$('btn-import-trakt');
        if (!btn) return;
        btn.addEventListener('click', () => this.importFromTrakt(btn));
    }

    async importFromTrakt(btn) {
        hideError();
        btn.disabled = true;
        try {
            await API.importFromTrakt();
            showInfo('Import z Trakt uruchomiony w tle. Obejrzane seriale pojawią się wkrótce.');
        } catch (err) {
            if (err.status === 409) {
                showTraktConnectPrompt();
            } else {
                showError(err.message || 'Nie udało się uruchomić importu z Trakt.');
            }
        } finally {
            btn.disabled = false;
        }
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
            container.innerHTML = '<div class="empty-state">Brak seriali. Dodaj pierwszy!</div>';
            return;
        }

        if (toolbar) this.show(toolbar);

        const filtered = filterSeries(this.allSeries, this.searchTerm);

        if (!filtered.length) {
            container.innerHTML = '<div class="empty-state">Żaden serial nie pasuje do wyszukiwania.</div>';
            return;
        }

        renderSeriesList(container, sortSeries(filtered, this.sortKey), id => this.loadDetail(id));
    }

    async loadSeriesList() {
        const container = this.$('series-list');
        container.innerHTML = '<div class="loading">Ładowanie…</div>';
        try {
            // The toolbar filters and sorts over the whole library in the
            // browser, so this panel needs every page rather than a pager.
            this.allSeries = await fetchAllPages((page) => API.series(page));
            this.applyListView();
        } catch {
            showError('Nie udało się wczytać seriali. Czy backend działa?');
            container.innerHTML = '';
        }
    }

    async loadDetail(id) {
        this.hide(this.$('series-list-view'));
        this.show(this.$('series-detail-view'));
        const content = this.$('series-detail-content');
        content.innerHTML = '<div class="loading">Ładowanie…</div>';
        hideError();

        try {
            const series = await API.seriesDetail(id);
            renderDetail(content, series, {
                reloadDetail: () => this.loadDetail(id),
                backToList: () => {
                    this.hide(this.$('series-detail-view'));
                    this.show(this.$('series-list-view'));
                    this.loadSeriesList();
                },
            });
        } catch {
            showError('Nie udało się wczytać szczegółów serialu.');
            content.innerHTML = '';
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
            submitBtn.textContent = 'Tworzenie…';
            hideError();

            try {
                await API.createSeries({title, ...readMetadataInputs(form)});
                this.hide(modal);
                submitBtn.disabled = false;
                submitBtn.textContent = 'Utwórz';
                await this.loadSeriesList();
            } catch (err) {
                showError(err.message || 'Nie udało się utworzyć serialu.');
                submitBtn.disabled = false;
                submitBtn.textContent = 'Utwórz';
            }
        });
    }

    initNavigation() {
        this.$('btn-back').addEventListener('click', () => {
            this.hide(this.$('series-detail-view'));
            this.show(this.$('series-list-view'));
            hideError();
            this.loadSeriesList();
        });
    }
}
