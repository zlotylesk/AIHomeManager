import { Controller } from '@hotwired/stimulus';
import { apiCall, escHtml } from '../util.js';
import {
    granularityLabel,
    headlineCaption,
    headlineLabel,
    isIdle,
    isUnavailable,
    metricLabel,
    toChartData,
} from '../insights/format.js';

/**
 * Trends dashboard.
 *
 * Chart.js is pulled in with a dynamic import so the ~200 KB charting bundle
 * becomes its own chunk instead of riding along in the `app` entry, which every
 * page in the site loads. It is bundled by Encore from npm — never a CDN.
 */
let chartLibrary = null;

async function loadChartLibrary() {
    if (null === chartLibrary) {
        const module = await import('chart.js/auto');
        chartLibrary = module.default ?? module.Chart;
    }

    return chartLibrary;
}

export default class extends Controller {
    static targets = ['grid', 'granularity', 'error'];

    connect() {
        this.charts = new Map();
        this.granularity = 'week';
        this.load();
    }

    disconnect() {
        this.destroyCharts();
    }

    changeGranularity(event) {
        this.granularity = event.target.value;
        this.load();
    }

    async load() {
        this.hideError();
        this.destroyCharts();
        this.gridTarget.innerHTML = '<p class="trends-loading">Ładowanie trendów…</p>';

        let trends;
        try {
            trends = await apiCall(`/api/trends?granularity=${encodeURIComponent(this.granularity)}`);
        } catch {
            this.gridTarget.innerHTML = '';
            this.showError('Nie udało się pobrać trendów.');

            return;
        }

        this.render(trends);
    }

    render(trends) {
        const series = Array.isArray(trends.series) ? trends.series : [];

        if (0 === series.length) {
            this.gridTarget.innerHTML = '<p class="trends-empty">Brak metryk do pokazania.</p>';

            return;
        }

        this.gridTarget.innerHTML = series.map((s) => this.cardHtml(s, trends)).join('');
        series.forEach((s) => this.drawChart(s));
    }

    cardHtml(series, trends) {
        const stateClass = isUnavailable(series)
            ? ' trends-card--unavailable'
            : (isIdle(series) ? ' trends-card--idle' : '');

        return `
            <article class="trends-card${stateClass}" data-metric="${escHtml(series.metric)}">
                <header class="trends-card-head">
                    <h2 class="trends-card-title">${escHtml(metricLabel(series.metric))}</h2>
                    <span class="trends-card-headline">${escHtml(headlineLabel(series))}</span>
                    <span class="trends-card-caption">${escHtml(headlineCaption(series))}</span>
                </header>
                <div class="trends-chart-wrap">
                    ${isUnavailable(series)
                        ? '<p class="trends-card-empty">Ta metryka jest chwilowo niedostępna.</p>'
                        : `<canvas class="trends-chart" data-metric-canvas="${escHtml(series.metric)}" aria-label="${escHtml(metricLabel(series.metric))} — ${escHtml(granularityLabel(trends.granularity))}" role="img"></canvas>`}
                </div>
            </article>`;
    }

    async drawChart(series) {
        if (isUnavailable(series)) {
            return;
        }

        const canvas = this.gridTarget.querySelector(`[data-metric-canvas="${series.metric}"]`);
        if (!canvas) {
            return;
        }

        const data = toChartData(series, this.granularity);

        let Chart;
        try {
            Chart = await loadChartLibrary();
        } catch {
            // A failed chunk load must not blank the page — the headline figure
            // on the card still carries the number.
            this.showError('Nie udało się załadować wykresów. Liczby poniżej są aktualne.');

            return;
        }

        this.charts.set(series.metric, new Chart(canvas, {
            type: data.type,
            data: {
                labels: data.labels,
                datasets: [{
                    label: metricLabel(series.metric),
                    data: data.values,
                    borderColor: '#2563eb',
                    backgroundColor: 'line' === data.type ? 'rgba(37, 99, 235, 0.15)' : 'rgba(37, 99, 235, 0.65)',
                    fill: 'line' === data.type,
                    tension: 0.25,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, suggestedMax: data.suggestedMax },
                    x: { ticks: { maxRotation: 0, autoSkip: true } },
                },
            },
        }));
    }

    destroyCharts() {
        if (!this.charts) {
            return;
        }

        this.charts.forEach((chart) => chart.destroy());
        this.charts.clear();
    }

    showError(message) {
        if (this.hasErrorTarget) {
            this.errorTarget.textContent = message;
            this.errorTarget.classList.remove('hidden');
        }
    }

    hideError() {
        if (this.hasErrorTarget) {
            this.errorTarget.classList.add('hidden');
        }
    }
}
