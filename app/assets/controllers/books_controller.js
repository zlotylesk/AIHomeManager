import { Controller } from '@hotwired/stimulus';
import { TOAST_TIMEOUT_MS, apiCall, safeUrl, escHtml } from '../util.js';

const STATUS_LABELS = {to_read: 'To Read', reading: 'Reading', completed: 'Completed'};
const STATUS_COLORS = {to_read: '#6b7280', reading: '#2563eb', completed: '#16a34a'};

function renderBook(book) {
    const pct = book.percentage ?? 0;
    const safeCover = safeUrl(book.coverUrl);
    const cover = safeCover
        ? `<img class="book-cover" src="${escHtml(safeCover)}" alt="cover" loading="lazy">`
        : `<div class="book-cover book-cover--placeholder">📖</div>`;

    return `
        <div class="book-card" data-id="${book.id}">
            ${cover}
            <div class="book-info">
                <h3 class="book-title">${escHtml(book.title ?? '—')}</h3>
                <p class="book-author">${escHtml(book.author ?? '—')}</p>
                <span class="status-badge" style="background:${STATUS_COLORS[book.status] ?? '#6b7280'}">
                    ${STATUS_LABELS[book.status] ?? book.status}
                </span>
                ${book.totalPages ? `
                <div class="progress-wrap">
                    <progress value="${pct}" max="100"></progress>
                    <span class="progress-pct">${pct}%</span>
                </div>
                <p class="progress-pages">${book.currentPage ?? 0} / ${book.totalPages} pages</p>
                ` : ''}
                ${book.status === 'reading' ? `
                <button class="btn btn-secondary btn-sm btn-log-session"
                        data-id="${book.id}"
                        data-title="${escHtml(book.title ?? '')}"
                        data-action="click->books#openSession">
                    + Log Session
                </button>
                ` : ''}
            </div>
        </div>
    `;
}

export default class extends Controller {
    static targets = [
        'list',
        'filterStatus',
        'addBookModal',
        'addBookForm',
        'isbnInput',
        'sessionModal',
        'sessionForm',
        'sessionTitle',
        'sessionBookId',
        'pagesInput',
        'sessionDateInput',
        'notesInput',
    ];

    connect() {
        this.loadList('');
    }


    show(el) {
        el.classList.remove('hidden');
    }

    hide(el) {
        el.classList.add('hidden');
    }

    showError(msg) {
        const banner = document.getElementById('error-banner');
        if (!banner) return;
        banner.textContent = msg;
        banner.classList.remove('hidden');
        setTimeout(() => banner.classList.add('hidden'), TOAST_TIMEOUT_MS);
    }


    async loadList(status) {
        this.listTarget.innerHTML = '<div class="loading">Loading…</div>';

        const url = status
            ? `/api/books?${new URLSearchParams({status})}`
            : '/api/books';
        try {
            const books = await apiCall(url);
            if (!books.length) {
                this.listTarget.innerHTML = '<div class="empty-state">No books found.</div>';
                return;
            }
            this.listTarget.innerHTML = books.map(renderBook).join('');
        } catch {
            this.showError('Failed to load books.');
            this.listTarget.innerHTML = '';
        }
    }

    filterChange(event) {
        this.loadList(event.target.value);
    }


    openAddBook() {
        this.isbnInputTarget.value = '';
        this.show(this.addBookModalTarget);
        this.isbnInputTarget.focus();
    }

    closeAddBook() {
        this.hide(this.addBookModalTarget);
    }

    closeAddBookBackdrop(event) {
        if (event.target !== this.addBookModalTarget) return;
        this.hide(this.addBookModalTarget);
    }

    async submitAddBook(event) {
        event.preventDefault();
        const isbn = this.isbnInputTarget.value.trim();
        if (!isbn) return;
        const submitBtn = this.addBookFormTarget.querySelector('[type=submit]');
        submitBtn.disabled = true;
        submitBtn.textContent = 'Adding…';
        try {
            await apiCall('/api/books', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({isbn}),
            });
            this.hide(this.addBookModalTarget);
            await this.loadList(this.filterStatusTarget.value);
        } catch (err) {
            this.showError(err.message || 'Failed to add book.');
        }
        submitBtn.disabled = false;
        submitBtn.textContent = 'Add Book';
    }


    openSession(event) {
        const btn = event.target.closest('.btn-log-session');
        if (!btn) return;
        this.sessionBookIdTarget.value = btn.dataset.id;
        this.sessionTitleTarget.textContent = `Log Session — ${btn.dataset.title}`;
        this.pagesInputTarget.value = '';
        this.sessionDateInputTarget.value = new Date().toISOString().slice(0, 10);
        this.notesInputTarget.value = '';
        this.show(this.sessionModalTarget);
        this.pagesInputTarget.focus();
    }

    closeSession() {
        this.hide(this.sessionModalTarget);
    }

    closeSessionBackdrop(event) {
        if (event.target !== this.sessionModalTarget) return;
        this.hide(this.sessionModalTarget);
    }

    async submitSession(event) {
        event.preventDefault();
        const bookId = this.sessionBookIdTarget.value;
        const pages = parseInt(this.pagesInputTarget.value, 10);
        const date = this.sessionDateInputTarget.value;
        const notes = this.notesInputTarget.value.trim() || null;
        if (!pages || !date) return;
        const submitBtn = this.sessionFormTarget.querySelector('[type=submit]');
        submitBtn.disabled = true;
        submitBtn.textContent = 'Saving…';
        try {
            await apiCall(`/api/books/${bookId}/reading-sessions`, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({pages_read: pages, date, notes}),
            });
            this.hide(this.sessionModalTarget);
            await this.loadList(this.filterStatusTarget.value);
        } catch (err) {
            this.showError(err.message || 'Failed to log session.');
        }
        submitBtn.disabled = false;
        submitBtn.textContent = 'Save Session';
    }
}
