import { Controller } from '@hotwired/stimulus';
import { TOAST_TIMEOUT_MS, apiCall, escHtml } from '../util.js';
import { FIRST_PAGE, mountPagerAfter, unwrapPage, withPage } from '../pagination.js';
import {
    MEASUREMENT_UNITS,
    ingredientLine,
    metaLine,
    prepTimeLabel,
    servingsLabel,
    unitLabel,
} from '../recipes/format.js';

function unitOptions(selected) {
    return MEASUREMENT_UNITS
        .map((unit) => `<option value="${escHtml(unit)}"${unit === selected ? ' selected' : ''}>${escHtml(unitLabel(unit))}</option>`)
        .join('');
}

function ingredientRowHtml(ingredient) {
    const value = ingredient || { name: '', quantity: '', unit: 'g' };

    return `
        <div class="recipe-ingredient-row">
            <input class="recipe-input js-ing-name" type="text" placeholder="Składnik" aria-label="Nazwa składnika"
                   value="${escHtml(value.name)}" required>
            <input class="recipe-input js-ing-quantity" type="number" step="any" min="0" placeholder="Ilość"
                   aria-label="Ilość" value="${escHtml(String(value.quantity))}" required>
            <select class="recipe-input js-ing-unit" aria-label="Jednostka">${unitOptions(value.unit)}</select>
            <button type="button" class="btn btn-sm js-remove-ingredient" aria-label="Usuń składnik">✕</button>
        </div>`;
}

function stepRowHtml(text) {
    return `
        <div class="recipe-step-row">
            <input class="recipe-input js-step-text" type="text" placeholder="Krok przygotowania"
                   aria-label="Krok" value="${escHtml(text || '')}">
            <button type="button" class="btn btn-sm js-remove-step" aria-label="Usuń krok">✕</button>
        </div>`;
}

function cardHtml(recipe) {
    const tags = recipe.tags.map((tag) => `<span class="recipe-tag">${escHtml(tag)}</span>`).join('');

    return `
        <article class="recipe-card" data-id="${escHtml(recipe.id)}" data-action="click->recipes#selectRecipe">
            <h3 class="recipe-card-title">${escHtml(recipe.title)}</h3>
            <p class="recipe-card-meta">${escHtml(metaLine(recipe))}</p>
            ${tags ? `<div class="recipe-card-tags">${tags}</div>` : ''}
        </article>`;
}

function detailHtml(recipe) {
    const ingredients = recipe.ingredients
        .map((ingredient) => `<li class="recipe-ingredient">${escHtml(ingredientLine(ingredient))}</li>`)
        .join('');
    const steps = recipe.steps.length
        ? `<ol class="recipe-step-list">${recipe.steps.map((step) => `<li class="recipe-step">${escHtml(step)}</li>`).join('')}</ol>`
        // Some recipes really are "mix it all together" — the aggregate allows
        // an empty step list, so the detail says so rather than showing nothing.
        : '<p class="empty-state">Bez opisanych kroków.</p>';
    const prep = prepTimeLabel(recipe.prepTimeMinutes);

    return `
        <div class="recipe-detail">
            <button type="button" class="btn btn-sm" data-action="click->recipes#backToList">← Wróć</button>
            <div class="recipe-detail-header">
                <h2 class="recipe-detail-title">${escHtml(recipe.title)}</h2>
                <p class="recipe-detail-meta">${escHtml([servingsLabel(recipe.servings), prep].filter(Boolean).join(' · '))}</p>
                <div class="recipe-detail-actions">
                    <button type="button" class="btn btn-sm" data-action="click->recipes#editRecipe">✎ Edytuj</button>
                    <button type="button" class="btn btn-sm btn-danger" data-action="click->recipes#deleteRecipe">🗑 Usuń</button>
                </div>
            </div>
            <h3 class="recipe-detail-section">Składniki</h3>
            <ul class="recipe-ingredient-list">${ingredients}</ul>
            <h3 class="recipe-detail-section">Przygotowanie</h3>
            ${steps}
        </div>`;
}

export default class extends Controller {
    static targets = [
        'list', 'detail', 'form', 'formTitle', 'title', 'servings', 'prepTime', 'tags',
        'ingredients', 'steps', 'filterTag', 'filterPhrase',
    ];

    connect() {
        this.editingId = null;
        this.currentRecipe = null;
        this.resetForm();
        this.loadRecipes();
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

    async loadRecipes(page = FIRST_PAGE) {
        this.listTarget.innerHTML = '<div class="loading">Ładowanie…</div>';

        const params = new URLSearchParams();
        const tag = this.filterTagTarget.value.trim();
        const phrase = this.filterPhraseTarget.value.trim();
        if (tag) {
            params.set('tag', tag);
        }
        if (phrase) {
            params.set('phrase', phrase);
        }
        const query = withPage(params, page).toString();

        try {
            const { items: recipes, pagination } = unwrapPage(await apiCall(`/api/recipes${query ? `?${query}` : ''}`));
            if (recipes.length) {
                this.listTarget.innerHTML = recipes.map(cardHtml).join('');
                mountPagerAfter(this.listTarget, pagination, (next) => this.loadRecipes(next));

                return;
            }

            // An empty catalog and a filter that matched nothing are two
            // different states, and telling them apart is what stops a user
            // concluding they have no recipes when they only mistyped a tag.
            this.listTarget.innerHTML = (tag || phrase)
                ? '<div class="empty-state">Żaden przepis nie pasuje do filtrów.</div>'
                : '<div class="empty-state">Brak przepisów. Dodaj pierwszy powyżej.</div>';
        } catch {
            this.showError('Nie udało się wczytać przepisów.');
            this.listTarget.innerHTML = '';
        }
    }

    async selectRecipe(event) {
        const card = event.target.closest('.recipe-card');
        if (!card) {
            return;
        }

        try {
            const recipe = await apiCall(`/api/recipes/${encodeURIComponent(card.dataset.id)}`);
            this.currentRecipe = recipe;
            this.detailTarget.innerHTML = detailHtml(recipe);
            this.detailTarget.classList.remove('hidden');
            this.listTarget.classList.add('hidden');
        } catch (err) {
            this.showError(err.message || 'Nie udało się wczytać przepisu.');
        }
    }

    backToList() {
        this.currentRecipe = null;
        this.detailTarget.classList.add('hidden');
        this.detailTarget.innerHTML = '';
        this.listTarget.classList.remove('hidden');
    }

    addIngredient() {
        this.ingredientsTarget.insertAdjacentHTML('beforeend', ingredientRowHtml(null));
    }

    addStep() {
        this.stepsTarget.insertAdjacentHTML('beforeend', stepRowHtml(''));
    }

    removeRow(event) {
        const remove = event.target.closest('.js-remove-ingredient, .js-remove-step');
        if (!remove) {
            return;
        }

        const row = remove.closest('.recipe-ingredient-row, .recipe-step-row');
        // A recipe cannot exist without an ingredient (the aggregate refuses
        // one), so the last row is kept and cleared instead of removed — the
        // alternative is a form that can only ever be rejected.
        if (row.classList.contains('recipe-ingredient-row') && this.ingredientsTarget.children.length <= 1) {
            row.querySelector('.js-ing-name').value = '';
            row.querySelector('.js-ing-quantity').value = '';

            return;
        }

        row.remove();
    }

    resetForm() {
        this.editingId = null;
        this.formTitleTarget.textContent = 'Nowy przepis';
        this.titleTarget.value = '';
        this.servingsTarget.value = '1';
        this.prepTimeTarget.value = '';
        this.tagsTarget.value = '';
        this.ingredientsTarget.innerHTML = ingredientRowHtml(null);
        this.stepsTarget.innerHTML = stepRowHtml('');
    }

    cancelEdit() {
        this.resetForm();
    }

    editRecipe() {
        const recipe = this.currentRecipe;
        if (!recipe) {
            return;
        }

        this.editingId = recipe.id;
        this.formTitleTarget.textContent = `Edycja: ${recipe.title}`;
        this.titleTarget.value = recipe.title;
        this.servingsTarget.value = String(recipe.servings);
        this.prepTimeTarget.value = null === recipe.prepTimeMinutes ? '' : String(recipe.prepTimeMinutes);
        this.tagsTarget.value = recipe.tags.join(', ');
        this.ingredientsTarget.innerHTML = recipe.ingredients.map(ingredientRowHtml).join('');
        this.stepsTarget.innerHTML = recipe.steps.length
            ? recipe.steps.map(stepRowHtml).join('')
            : stepRowHtml('');

        this.backToList();
        this.formTarget.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    readForm() {
        const ingredients = Array.from(this.ingredientsTarget.querySelectorAll('.recipe-ingredient-row'))
            .map((row) => ({
                name: row.querySelector('.js-ing-name').value.trim(),
                quantity: Number(row.querySelector('.js-ing-quantity').value),
                unit: row.querySelector('.js-ing-unit').value,
            }))
            .filter((ingredient) => ingredient.name && Number.isFinite(ingredient.quantity));

        const steps = Array.from(this.stepsTarget.querySelectorAll('.js-step-text'))
            .map((input) => input.value.trim())
            .filter(Boolean);

        const tags = this.tagsTarget.value.split(',').map((tag) => tag.trim()).filter(Boolean);
        const prepTime = this.prepTimeTarget.value.trim();

        return {
            title: this.titleTarget.value.trim(),
            ingredients,
            steps,
            // Always sent, because the update is a full replace and the API
            // requires servings there: an omitted value would otherwise be
            // defaulted and rescale the whole shopping list.
            servings: Number(this.servingsTarget.value) || 1,
            prepTimeMinutes: prepTime ? Number(prepTime) : null,
            tags,
        };
    }

    async submit(event) {
        event.preventDefault();
        const payload = this.readForm();

        if (!payload.ingredients.length) {
            this.showError('Przepis musi mieć co najmniej jeden składnik.');

            return;
        }

        const editingId = this.editingId;

        try {
            if (editingId) {
                await apiCall(`/api/recipes/${encodeURIComponent(editingId)}`, {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload),
                });
                this.showInfo('Przepis zapisany.');
            } else {
                await apiCall('/api/recipes', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload),
                });
                this.showInfo('Przepis dodany.');
            }

            this.resetForm();
            await this.loadRecipes();
        } catch (err) {
            this.showError(err.message || 'Nie udało się zapisać przepisu.');
        }
    }

    async deleteRecipe() {
        const recipe = this.currentRecipe;
        if (!recipe || !confirm(`Usunąć przepis „${recipe.title}"?`)) {
            return;
        }

        try {
            await apiCall(`/api/recipes/${encodeURIComponent(recipe.id)}`, { method: 'DELETE' });
            this.backToList();
            await this.loadRecipes();
            this.showInfo('Przepis usunięty.');
        } catch (err) {
            // 409 means the recipe is still on the calendar. Saying so beats a
            // generic failure: the user can act on it, and the shopping list
            // would otherwise be silently short by that meal.
            this.showError(409 === err.status
                ? 'Nie można usunąć — przepis jest zaplanowany w kalendarzu. Usuń najpierw wpisy z planu.'
                : (err.message || 'Nie udało się usunąć przepisu.'));
        }
    }
}
