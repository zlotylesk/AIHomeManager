import { Controller } from '@hotwired/stimulus';
import { TOAST_TIMEOUT_MS, apiCall, escHtml } from '../util.js';
import {
    MEAL_SLOTS,
    dayHeading,
    ingredientLine,
    mealTitle,
    servingsLabel,
    shiftWeek,
    slotLabel,
    weekOf,
    windowLabel,
} from '../recipes/format.js';

function mealHtml(meal) {
    const missing = null === meal.recipeTitle;

    return `
        <li class="meal-card${missing ? ' meal-card--missing' : ''}" data-id="${escHtml(meal.id)}">
            <span class="meal-card-title">${escHtml(mealTitle(meal))}</span>
            <span class="meal-card-servings">${escHtml(servingsLabel(meal.servings))}</span>
            <span class="meal-card-actions">
                <button type="button" class="btn btn-sm js-move-meal" aria-label="Przenieś posiłek"
                        data-action="click->meal-plan#startMove">⇄</button>
                <button type="button" class="btn btn-sm btn-danger js-unplan-meal" aria-label="Usuń z planu"
                        data-action="click->meal-plan#unplanMeal">✕</button>
            </span>
        </li>`;
}

function slotHtml(date, slot) {
    const meals = slot.meals.map(mealHtml).join('');

    return `
        <div class="meal-slot" data-date="${escHtml(date)}" data-slot="${escHtml(slot.slot)}"
             data-action="click->meal-plan#dropMeal">
            <h4 class="meal-slot-title">${escHtml(slotLabel(slot.slot))}</h4>
            ${meals ? `<ul class="meal-list">${meals}</ul>` : '<p class="meal-slot-empty">—</p>'}
        </div>`;
}

function dayHtml(day) {
    return `
        <section class="meal-day" data-date="${escHtml(day.date)}">
            <h3 class="meal-day-title">${escHtml(dayHeading(day.date))}</h3>
            ${day.slots.map((slot) => slotHtml(day.date, slot)).join('')}
        </section>`;
}

export default class extends Controller {
    static targets = ['calendar', 'shoppingList', 'windowLabel', 'recipeSelect', 'planDate', 'planSlot', 'planServings'];

    connect() {
        this.window = weekOf();
        this.movingMealId = null;
        // The slot vocabulary and its order through the day are single-sourced
        // in format.js, so the form cannot drift from what the calendar renders.
        this.planSlotTarget.innerHTML = MEAL_SLOTS
            .map((slot) => `<option value="${escHtml(slot)}">${escHtml(slotLabel(slot))}</option>`)
            .join('');
        this.loadRecipes();
        this.reload();
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

    showError(msg) {
        this.flash('error-banner', msg);
    }

    showInfo(msg) {
        this.flash('info-banner', msg);
    }

    async reload() {
        this.windowLabelTarget.textContent = windowLabel(this.window);
        // The date picker follows the visible week, so planning a meal defaults
        // to somewhere the user can actually see it land.
        this.planDateTarget.value = this.window.from;
        this.planDateTarget.min = this.window.from;
        this.planDateTarget.max = this.window.to;

        await Promise.all([this.loadCalendar(), this.loadShoppingList()]);
    }

    previousWeek() {
        this.window = shiftWeek(this.window, -1);
        this.reload();
    }

    nextWeek() {
        this.window = shiftWeek(this.window, 1);
        this.reload();
    }

    thisWeek() {
        this.window = weekOf();
        this.reload();
    }

    windowQuery() {
        return `from=${encodeURIComponent(this.window.from)}&to=${encodeURIComponent(this.window.to)}`;
    }

    async loadCalendar() {
        this.calendarTarget.innerHTML = '<div class="loading">Ładowanie…</div>';

        try {
            const plan = await apiCall(`/api/meal-plan?${this.windowQuery()}`);
            // Every day and every slot is present in the payload by design, so
            // the grid is drawn straight from it — no client-side gap filling.
            this.calendarTarget.innerHTML = plan.days.map(dayHtml).join('');
        } catch {
            this.showError('Nie udało się wczytać planu posiłków.');
            this.calendarTarget.innerHTML = '';
        }
    }

    async loadShoppingList() {
        this.shoppingListTarget.innerHTML = '<div class="loading">Ładowanie…</div>';

        try {
            const list = await apiCall(`/api/meal-plan/shopping-list?${this.windowQuery()}`);
            // Unlike the calendar this read is not gap-filled: an empty list
            // means nothing is planned, not that something failed to load.
            this.shoppingListTarget.innerHTML = list.items.length
                ? `<ul class="shopping-list">${list.items.map((item) => `<li class="shopping-item">${escHtml(ingredientLine(item))}</li>`).join('')}</ul>`
                : '<div class="empty-state">Nic do kupienia — brak zaplanowanych posiłków w tym tygodniu.</div>';
        } catch {
            this.showError('Nie udało się wczytać listy zakupów.');
            this.shoppingListTarget.innerHTML = '';
        }
    }

    async loadRecipes() {
        try {
            const recipes = await apiCall('/api/recipes');
            this.recipeSelectTarget.innerHTML = recipes.length
                ? recipes.map((recipe) => `<option value="${escHtml(recipe.id)}">${escHtml(recipe.title)}</option>`).join('')
                : '<option value="">Brak przepisów</option>';
        } catch {
            this.recipeSelectTarget.innerHTML = '<option value="">Brak przepisów</option>';
        }
    }

    async planMeal(event) {
        event.preventDefault();

        const recipeId = this.recipeSelectTarget.value;
        if (!recipeId) {
            this.showError('Najpierw dodaj przepis w zakładce Przepisy.');

            return;
        }

        try {
            await apiCall('/api/meal-plan', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    date: this.planDateTarget.value,
                    slot: this.planSlotTarget.value,
                    recipeId,
                    servings: Number(this.planServingsTarget.value) || 1,
                }),
            });
            await this.reload();
            this.showInfo('Posiłek zaplanowany.');
        } catch (err) {
            // 409 is the same recipe twice in one slot — a double-clicked
            // button, not a menu. Two *different* recipes there are fine, so
            // the message says which of the two it was.
            this.showError(409 === err.status
                ? 'Ten przepis jest już zaplanowany w tym miejscu.'
                : (err.message || 'Nie udało się zaplanować posiłku.'));
        }
    }

    startMove(event) {
        const card = event.target.closest('.meal-card');
        if (!card) {
            return;
        }

        this.calendarTarget.querySelectorAll('.meal-card--moving').forEach((el) => el.classList.remove('meal-card--moving'));

        // Clicking the same card again cancels, so a mis-click is undoable
        // without picking an arbitrary destination first.
        if (this.movingMealId === card.dataset.id) {
            this.movingMealId = null;
            this.showInfo('Przenoszenie anulowane.');

            return;
        }

        this.movingMealId = card.dataset.id;
        card.classList.add('meal-card--moving');
        this.showInfo('Wybierz slot docelowy.');
    }

    async dropMeal(event) {
        if (!this.movingMealId) {
            return;
        }

        // A click that landed on a card is that card's own business (its ✕ and
        // ⇄ buttons bubble up here too), so a drop only counts on the slot's
        // own surface — its heading or its empty area.
        if (event.target.closest('.meal-card')) {
            return;
        }

        const slot = event.target.closest('.meal-slot');
        if (!slot) {
            return;
        }

        const mealId = this.movingMealId;
        this.movingMealId = null;

        try {
            await apiCall(`/api/meal-plan/${encodeURIComponent(mealId)}`, {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ date: slot.dataset.date, slot: slot.dataset.slot }),
            });
            await this.reload();
            this.showInfo('Posiłek przeniesiony.');
        } catch (err) {
            this.showError(409 === err.status
                ? 'W tym slocie ten przepis już jest.'
                : (err.message || 'Nie udało się przenieść posiłku.'));
            await this.loadCalendar();
        }
    }

    async unplanMeal(event) {
        const card = event.target.closest('.meal-card');
        if (!card) {
            return;
        }

        try {
            await apiCall(`/api/meal-plan/${encodeURIComponent(card.dataset.id)}`, { method: 'DELETE' });
            await this.reload();
            this.showInfo('Posiłek usunięty z planu.');
        } catch (err) {
            this.showError(err.message || 'Nie udało się usunąć posiłku.');
        }
    }
}
