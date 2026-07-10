import { Controller } from '@hotwired/stimulus';
import { TOAST_TIMEOUT_MS, apiCall, escHtml } from '../util.js';
import {
    GOAL_PERIODS,
    GOAL_TYPES,
    clampPercent,
    longestLabel,
    periodLabel,
    progressText,
    streakLabel,
    typeLabel,
} from '../goals/format.js';

function optionsHtml(values, labelFn, selected = null) {
    return values
        .map((v) => `<option value="${escHtml(v)}"${v === selected ? ' selected' : ''}>${escHtml(labelFn(v))}</option>`)
        .join('');
}

function renderGoalCard(goal) {
    const percent = clampPercent(goal.percent);
    const met = Boolean(goal.met);
    const id = escHtml(goal.goalId);

    return `
        <div class="goal-card${met ? ' goal-card--met' : ''}" data-id="${id}">
            <div class="goal-card-head">
                <div class="goal-card-title">
                    <span class="goal-type">${escHtml(typeLabel(goal.type))}</span>
                    <span class="goal-period">${escHtml(periodLabel(goal.period))}</span>
                </div>
                <div class="goal-card-actions">
                    <button class="btn btn-secondary btn-sm js-goal-edit"
                            data-id="${id}"
                            data-target="${escHtml(goal.target)}"
                            data-period="${escHtml(goal.period)}"
                            data-action="click->goals#startEdit">Edytuj</button>
                    <button class="btn btn-secondary btn-sm js-goal-delete"
                            data-id="${id}"
                            data-action="click->goals#deleteGoal">Usuń</button>
                </div>
            </div>
            <div class="goal-progress">
                <div class="goal-progress-bar">
                    <div class="goal-progress-fill" style="width:${percent}%"></div>
                </div>
                <div class="goal-progress-meta">
                    <span class="goal-progress-count">${escHtml(progressText(goal.achieved, goal.target))}</span>
                    <span class="goal-progress-percent">${percent}%${met ? ' ✓' : ''}</span>
                </div>
            </div>
        </div>
    `;
}

function renderStreak(streak) {
    return `
        <div class="streak-card" data-type="${escHtml(streak.type)}">
            <span class="streak-type">${escHtml(typeLabel(streak.type))}</span>
            <span class="streak-current">${escHtml(streakLabel(streak.currentLength))}</span>
            <span class="streak-longest">${escHtml(longestLabel(streak.longestLength))}</span>
        </div>
    `;
}

export default class extends Controller {
    static targets = ['list', 'streaks', 'type', 'target', 'period'];

    connect() {
        this.typeTarget.innerHTML = optionsHtml(GOAL_TYPES, typeLabel);
        this.periodTarget.innerHTML = optionsHtml(GOAL_PERIODS, periodLabel);
        this.loadAll();
    }

    showError(msg) {
        this.flash('error-banner', msg);
    }

    showInfo(msg) {
        this.flash('info-banner', msg);
    }

    flash(bannerId, msg) {
        const banner = document.getElementById(bannerId);
        if (!banner) {
            return;
        }
        banner.textContent = msg;
        banner.classList.remove('hidden');
        setTimeout(() => banner.classList.add('hidden'), TOAST_TIMEOUT_MS);
    }

    async loadAll() {
        await Promise.all([this.loadGoals(), this.loadStreaks()]);
    }

    async loadGoals() {
        this.listTarget.innerHTML = '<div class="loading">Loading…</div>';
        try {
            const goals = await apiCall('/api/goals');
            this.listTarget.innerHTML = goals.length
                ? goals.map(renderGoalCard).join('')
                : '<div class="empty-state">Brak celów. Dodaj pierwszy cel powyżej.</div>';
        } catch {
            this.showError('Nie udało się wczytać celów.');
            this.listTarget.innerHTML = '';
        }
    }

    async loadStreaks() {
        try {
            const streaks = await apiCall('/api/goals/streaks');
            this.streaksTarget.innerHTML = streaks.length
                ? streaks.map(renderStreak).join('')
                : '<div class="empty-state">Streaki pojawią się po dodaniu celu.</div>';
        } catch {
            this.streaksTarget.innerHTML = '';
        }
    }

    async createGoal(event) {
        event.preventDefault();

        const type = this.typeTarget.value;
        const period = this.periodTarget.value;
        const target = parseInt(this.targetTarget.value, 10);

        if (!Number.isInteger(target) || target < 1) {
            this.showError('Podaj dodatni próg celu.');
            return;
        }

        try {
            await apiCall('/api/goals', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ type, target, period }),
            });
            this.targetTarget.value = '';
            this.showInfo('Cel dodany.');
            await this.loadAll();
        } catch (err) {
            this.showError(err.message || 'Nie udało się dodać celu.');
        }
    }

    startEdit(event) {
        const card = event.target.closest('.goal-card');
        if (!card) {
            return;
        }
        const id = escHtml(event.target.dataset.id);
        const target = escHtml(event.target.dataset.target);
        const period = event.target.dataset.period;

        card.innerHTML = `
            <div class="goal-edit-form">
                <input type="number" min="1" class="js-edit-target goal-input" value="${target}">
                <select class="js-edit-period goal-input">${optionsHtml(GOAL_PERIODS, periodLabel, period)}</select>
                <button class="btn btn-primary btn-sm"
                        data-id="${id}"
                        data-action="click->goals#saveEdit">Zapisz</button>
                <button class="btn btn-secondary btn-sm"
                        data-action="click->goals#loadGoals">Anuluj</button>
            </div>
        `;
    }

    async saveEdit(event) {
        const card = event.target.closest('.goal-card');
        if (!card) {
            return;
        }
        const id = event.target.dataset.id;
        const target = parseInt(card.querySelector('.js-edit-target').value, 10);
        const period = card.querySelector('.js-edit-period').value;

        if (!Number.isInteger(target) || target < 1) {
            this.showError('Podaj dodatni próg celu.');
            return;
        }

        try {
            await apiCall(`/api/goals/${encodeURIComponent(id)}`, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ target, period }),
            });
            this.showInfo('Cel zaktualizowany.');
            await this.loadAll();
        } catch (err) {
            this.showError(err.message || 'Nie udało się zaktualizować celu.');
        }
    }

    async deleteGoal(event) {
        const id = event.target.dataset.id;
        if (!id || !window.confirm('Usunąć ten cel?')) {
            return;
        }

        try {
            await apiCall(`/api/goals/${encodeURIComponent(id)}`, { method: 'DELETE' });
            await this.loadAll();
        } catch (err) {
            this.showError(err.message || 'Nie udało się usunąć celu.');
        }
    }
}
