import { Controller } from '@hotwired/stimulus';
import { TOAST_TIMEOUT_MS, apiCall, escHtml } from '../util.js';
import {
    TRANSACTION_TYPES,
    clampPercent,
    currentMonth,
    limitLabel,
    moneyLabel,
    typeLabel,
} from '../budget/format.js';

function optionsHtml(values, labelFn, selected = null) {
    return values
        .map((v) => `<option value="${escHtml(v)}"${v === selected ? ' selected' : ''}>${escHtml(labelFn(v))}</option>`)
        .join('');
}

function categoryOptionsHtml(categories, selected = null) {
    return categories
        .map((c) => `<option value="${escHtml(c.id)}"${c.id === selected ? ' selected' : ''}>${escHtml(c.name)}</option>`)
        .join('');
}

function transactionRowHtml(tx, categoryName) {
    return `
        <div class="budget-transaction-row" data-id="${escHtml(tx.id)}"
             data-amount-cents="${tx.amountInCents}" data-currency="${escHtml(tx.currency)}"
             data-date="${escHtml(tx.date)}" data-category-id="${escHtml(tx.categoryId)}"
             data-type="${escHtml(tx.type)}" data-description="${escHtml(tx.description ?? '')}">
            <span class="budget-tx-date">${escHtml(tx.date)}</span>
            <span class="budget-tx-category">${escHtml(categoryName)}</span>
            <span class="budget-tx-type budget-tx-type--${escHtml(tx.type)}">${escHtml(typeLabel(tx.type))}</span>
            <span class="budget-tx-amount">${escHtml(moneyLabel(tx.amountInCents, tx.currency))}</span>
            <span class="budget-tx-description">${tx.description ? escHtml(tx.description) : ''}</span>
            <span class="budget-tx-actions">
                <button class="btn btn-secondary btn-sm js-tx-edit" data-id="${escHtml(tx.id)}" data-action="click->budget#startEditTransaction">Edytuj</button>
                <button class="btn btn-secondary btn-sm" data-id="${escHtml(tx.id)}" data-action="click->budget#deleteTransaction">Usuń</button>
            </span>
        </div>`;
}

function categoryRowHtml(category) {
    return `
        <div class="budget-category-row" data-id="${escHtml(category.id)}"
             data-name="${escHtml(category.name)}" data-type="${escHtml(category.type)}"
             data-limit-cents="${category.monthlyLimitAmountInCents ?? ''}">
            <span class="budget-category-name">${escHtml(category.name)}</span>
            <span class="budget-category-type budget-category-type--${escHtml(category.type)}">${escHtml(typeLabel(category.type))}</span>
            <span class="budget-category-limit">${escHtml(limitLabel(category.monthlyLimitAmountInCents, category.monthlyLimitCurrency))}</span>
            <span class="budget-category-actions">
                <button class="btn btn-secondary btn-sm js-category-edit" data-id="${escHtml(category.id)}" data-action="click->budget#startEditCategory">Edytuj</button>
                <button class="btn btn-secondary btn-sm" data-id="${escHtml(category.id)}" data-action="click->budget#deleteCategory">Usuń</button>
            </span>
        </div>`;
}

function categoryBudgetRowHtml(row) {
    const percent = clampPercent(row.percentUsed);
    const hasLimit = null !== row.percentUsed;
    const overClass = row.overLimit ? ' budget-report-bar--over' : '';
    const rowClass = row.overLimit ? ' budget-report-row--over' : '';
    const spent = moneyLabel(row.spentInCents, 'PLN');
    const limitPart = row.monthlyLimitInCents
        ? ` / ${escHtml(moneyLabel(row.monthlyLimitInCents, row.monthlyLimitCurrency))}`
        : '';

    const barHtml = hasLimit
        ? `<div class="budget-report-bar-track">
                <div class="budget-report-bar${overClass}" style="width:${percent}%"></div>
            </div>
            <span class="budget-report-percent">${percent}%${row.overLimit ? ' ⚠️' : ''}</span>`
        : '<span class="budget-report-nolimit">Bez limitu</span>';

    return `
        <div class="budget-report-row${rowClass}">
            <div class="budget-report-row-head">
                <span class="budget-report-category">${escHtml(row.categoryName)}</span>
                <span class="budget-report-spent">${escHtml(spent)}${limitPart}</span>
            </div>
            ${barHtml}
        </div>`;
}

function reportHtml(report) {
    const rows = report.categories.length
        ? report.categories.map(categoryBudgetRowHtml).join('')
        : '<div class="empty-state">Brak kategorii.</div>';

    return `
        <div class="budget-report-summary">
            <div class="budget-report-total"><span>Przychody</span><strong>${escHtml(moneyLabel(report.totalIncomeInCents, 'PLN'))}</strong></div>
            <div class="budget-report-total"><span>Wydatki</span><strong>${escHtml(moneyLabel(report.totalExpensesInCents, 'PLN'))}</strong></div>
            <div class="budget-report-total budget-report-total--balance"><span>Saldo</span><strong>${escHtml(moneyLabel(report.balanceInCents, 'PLN'))}</strong></div>
        </div>
        <div class="budget-report-categories">${rows}</div>`;
}

export default class extends Controller {
    static targets = [
        'transactionList', 'categoryList', 'report',
        'txAmount', 'txCurrency', 'txDate', 'txCategory', 'txType', 'txDescription',
        'categoryName', 'categoryType',
        'filterMonth', 'filterCategory', 'filterType',
        'reportMonth',
    ];

    categories = [];

    connect() {
        this.txTypeTarget.innerHTML = optionsHtml(TRANSACTION_TYPES, typeLabel);
        this.categoryTypeTarget.innerHTML = optionsHtml(TRANSACTION_TYPES, typeLabel);
        this.filterTypeTarget.insertAdjacentHTML('beforeend', optionsHtml(TRANSACTION_TYPES, typeLabel));

        const month = currentMonth();
        this.filterMonthTarget.value = month;
        this.reportMonthTarget.value = month;
        this.txDateTarget.value = new Date().toISOString().slice(0, 10);

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
        await this.loadCategories();
        await Promise.all([this.loadTransactions(), this.loadReport()]);
    }

    categoryName(id) {
        const category = this.categories.find((c) => c.id === id);

        return category ? category.name : id;
    }

    async loadCategories() {
        try {
            this.categories = await apiCall('/api/budget/categories');
            this.categoryListTarget.innerHTML = this.categories.length
                ? this.categories.map(categoryRowHtml).join('')
                : '<div class="empty-state">Brak kategorii. Dodaj pierwszą powyżej.</div>';

            const selectedFilter = this.filterCategoryTarget.value;
            this.filterCategoryTarget.innerHTML = '<option value="">Wszystkie</option>'
                + categoryOptionsHtml(this.categories, selectedFilter);
            this.txCategoryTarget.innerHTML = categoryOptionsHtml(this.categories);
        } catch {
            this.showError('Nie udało się wczytać kategorii.');
        }
    }

    async loadTransactions() {
        this.transactionListTarget.innerHTML = '<div class="loading">Loading…</div>';

        const params = new URLSearchParams();
        if (this.filterMonthTarget.value) {
            params.set('month', this.filterMonthTarget.value);
        }
        if (this.filterCategoryTarget.value) {
            params.set('categoryId', this.filterCategoryTarget.value);
        }
        if (this.filterTypeTarget.value) {
            params.set('type', this.filterTypeTarget.value);
        }

        try {
            const transactions = await apiCall(`/api/budget/transactions?${params.toString()}`);
            this.transactionListTarget.innerHTML = transactions.length
                ? transactions.map((tx) => transactionRowHtml(tx, this.categoryName(tx.categoryId))).join('')
                : '<div class="empty-state">Brak transakcji.</div>';
        } catch {
            this.showError('Nie udało się wczytać transakcji.');
            this.transactionListTarget.innerHTML = '';
        }
    }

    async loadReport() {
        this.reportTarget.innerHTML = '<div class="loading">Loading…</div>';
        try {
            const report = await apiCall(`/api/budget/report?month=${encodeURIComponent(this.reportMonthTarget.value)}`);
            this.reportTarget.innerHTML = reportHtml(report);
        } catch (err) {
            this.showError(err.message || 'Nie udało się wczytać raportu.');
            this.reportTarget.innerHTML = '';
        }
    }

    async createCategory(event) {
        event.preventDefault();
        const name = this.categoryNameTarget.value.trim();
        if (!name) {
            this.showError('Podaj nazwę kategorii.');
            return;
        }

        try {
            await apiCall('/api/budget/categories', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ name, type: this.categoryTypeTarget.value }),
            });
            this.categoryNameTarget.value = '';
            this.showInfo('Kategoria dodana.');
            await this.loadCategories();
        } catch (err) {
            this.showError(err.message || 'Nie udało się dodać kategorii.');
        }
    }

    async deleteCategory(event) {
        const id = event.target.dataset.id;
        if (!id || !window.confirm('Usunąć tę kategorię?')) {
            return;
        }

        try {
            await apiCall(`/api/budget/categories/${encodeURIComponent(id)}`, { method: 'DELETE' });
            await this.loadCategories();
        } catch (err) {
            this.showError(err.message || 'Nie udało się usunąć kategorii.');
        }
    }

    startEditCategory(event) {
        const row = event.target.closest('.budget-category-row');
        if (!row) {
            return;
        }
        const id = event.target.dataset.id;
        const { name, type, limitCents } = row.dataset;
        const limitValue = limitCents ? (Number(limitCents) / 100).toFixed(2) : '';

        row.innerHTML = `
            <input type="text" class="budget-input js-edit-name" value="${escHtml(name)}">
            <span class="budget-category-type budget-category-type--${escHtml(type)}">${escHtml(typeLabel(type))}</span>
            <input type="number" step="0.01" min="0" class="budget-input js-edit-limit"
                   placeholder="Limit (puste = brak)" value="${escHtml(limitValue)}">
            <span class="budget-category-actions">
                <button class="btn btn-primary btn-sm" data-id="${escHtml(id)}" data-action="click->budget#saveCategoryEdit">Zapisz</button>
                <button class="btn btn-secondary btn-sm" data-action="click->budget#loadCategories">Anuluj</button>
            </span>
        `;
    }

    async saveCategoryEdit(event) {
        const row = event.target.closest('.budget-category-row');
        const id = event.target.dataset.id;
        const name = row.querySelector('.js-edit-name').value.trim();
        const limitRaw = row.querySelector('.js-edit-limit').value.trim();

        if (!name) {
            this.showError('Podaj nazwę kategorii.');
            return;
        }

        try {
            await apiCall(`/api/budget/categories/${encodeURIComponent(id)}`, {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ name }),
            });

            const limitValue = parseFloat(limitRaw);
            const hasLimit = '' !== limitRaw && Number.isFinite(limitValue) && limitValue > 0;
            await apiCall(`/api/budget/categories/${encodeURIComponent(id)}/limit`, {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(hasLimit
                    ? { amountInCents: Math.round(limitValue * 100), currency: 'PLN' }
                    : { amountInCents: null, currency: null }),
            });

            this.showInfo('Kategoria zaktualizowana.');
            await this.loadCategories();
        } catch (err) {
            this.showError(err.message || 'Nie udało się zaktualizować kategorii.');
        }
    }

    async createTransaction(event) {
        event.preventDefault();
        const amount = parseFloat(this.txAmountTarget.value);

        if (!Number.isFinite(amount) || amount <= 0) {
            this.showError('Podaj dodatnią kwotę.');
            return;
        }
        if (!this.txDateTarget.value) {
            this.showError('Podaj datę.');
            return;
        }
        if (!this.txCategoryTarget.value) {
            this.showError('Wybierz kategorię.');
            return;
        }

        try {
            await apiCall('/api/budget/transactions', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    amountInCents: Math.round(amount * 100),
                    currency: this.txCurrencyTarget.value,
                    date: this.txDateTarget.value,
                    categoryId: this.txCategoryTarget.value,
                    type: this.txTypeTarget.value,
                    description: this.txDescriptionTarget.value || null,
                }),
            });
            this.txAmountTarget.value = '';
            this.txDescriptionTarget.value = '';
            this.showInfo('Transakcja dodana.');
            await Promise.all([this.loadTransactions(), this.loadReport()]);
        } catch (err) {
            this.showError(err.message || 'Nie udało się dodać transakcji.');
        }
    }

    startEditTransaction(event) {
        const row = event.target.closest('.budget-transaction-row');
        if (!row) {
            return;
        }
        const id = event.target.dataset.id;
        const { amountCents, date, categoryId, type, description } = row.dataset;
        const amount = (Number(amountCents) / 100).toFixed(2);

        row.innerHTML = `
            <input type="date" class="budget-input js-edit-date" value="${escHtml(date)}">
            <select class="budget-input js-edit-category">${categoryOptionsHtml(this.categories, categoryId)}</select>
            <select class="budget-input js-edit-type">${optionsHtml(TRANSACTION_TYPES, typeLabel, type)}</select>
            <input type="number" step="0.01" min="0.01" class="budget-input js-edit-amount" placeholder="Kwota" value="${escHtml(amount)}">
            <input type="text" class="budget-input js-edit-description" placeholder="Opis" value="${escHtml(description)}">
            <button class="btn btn-primary btn-sm" data-id="${escHtml(id)}" data-action="click->budget#saveTransactionEdit">Zapisz</button>
            <button class="btn btn-secondary btn-sm" data-action="click->budget#loadTransactions">Anuluj</button>
        `;
    }

    async saveTransactionEdit(event) {
        const row = event.target.closest('.budget-transaction-row');
        const id = event.target.dataset.id;
        const amount = parseFloat(row.querySelector('.js-edit-amount').value);
        const date = row.querySelector('.js-edit-date').value;
        const categoryId = row.querySelector('.js-edit-category').value;
        const type = row.querySelector('.js-edit-type').value;
        const description = row.querySelector('.js-edit-description').value;

        if (!Number.isFinite(amount) || amount <= 0) {
            this.showError('Podaj dodatnią kwotę.');
            return;
        }

        try {
            await apiCall(`/api/budget/transactions/${encodeURIComponent(id)}`, {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    amountInCents: Math.round(amount * 100),
                    currency: 'PLN',
                    date,
                    categoryId,
                    type,
                    description: description || null,
                }),
            });
            this.showInfo('Transakcja zaktualizowana.');
            await Promise.all([this.loadTransactions(), this.loadReport()]);
        } catch (err) {
            this.showError(err.message || 'Nie udało się zaktualizować transakcji.');
        }
    }

    async deleteTransaction(event) {
        const id = event.target.dataset.id;
        if (!id || !window.confirm('Usunąć tę transakcję?')) {
            return;
        }

        try {
            await apiCall(`/api/budget/transactions/${encodeURIComponent(id)}`, { method: 'DELETE' });
            await Promise.all([this.loadTransactions(), this.loadReport()]);
        } catch (err) {
            this.showError(err.message || 'Nie udało się usunąć transakcji.');
        }
    }
}
