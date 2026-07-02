import { Controller } from '@hotwired/stimulus';
import { API } from '../series/api.js';
import { hideError, showError, showInfo, showTraktConnectPrompt } from '../series/banners.js';
import { filterSeries, sortSeries } from '../series/list.js';
import { renderSeriesList } from '../series/list-view.js';
import { readMetadataInputs, renderDetail } from '../series/detail-view.js';

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
            showInfo('Trakt import started in the background. Your watched shows will appear shortly.');
        } catch (err) {
            if (err.status === 409) {
                showTraktConnectPrompt();
            } else {
                showError(err.message || 'Failed to start the Trakt import.');
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
            container.innerHTML = '<div class="empty-state">No series yet. Add your first one!</div>';
            return;
        }

        if (toolbar) this.show(toolbar);

        const filtered = filterSeries(this.allSeries, this.searchTerm);

        if (!filtered.length) {
            container.innerHTML = '<div class="empty-state">No series match your search.</div>';
            return;
        }

        renderSeriesList(container, sortSeries(filtered, this.sortKey), id => this.loadDetail(id));
    }

    async loadSeriesList() {
        const container = this.$('series-list');
        container.innerHTML = '<div class="loading">Loading…</div>';
        try {
            this.allSeries = await API.series();
            this.applyListView();
        } catch {
            showError('Failed to load series. Is the backend running?');
            container.innerHTML = '';
        }
    }

    async loadDetail(id) {
        this.hide(this.$('series-list-view'));
        this.show(this.$('series-detail-view'));
        const content = this.$('series-detail-content');
        content.innerHTML = '<div class="loading">Loading…</div>';
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
            showError('Failed to load series detail.');
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
            submitBtn.textContent = 'Creating…';
            hideError();

            try {
                await API.createSeries({title, ...readMetadataInputs(form)});
                this.hide(modal);
                submitBtn.disabled = false;
                submitBtn.textContent = 'Create';
                await this.loadSeriesList();
            } catch (err) {
                showError(err.message || 'Failed to create series.');
                submitBtn.disabled = false;
                submitBtn.textContent = 'Create';
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
