'use strict';

const $ = id => document.getElementById(id);

function showError(msg) {
    const b = $('error-banner');
    b.textContent = msg;
    b.classList.remove('hidden');
    setTimeout(() => b.classList.add('hidden'), 6000);
}

function escHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

const STATUS_LABELS = {to_read: 'To Read', reading: 'Reading', completed: 'Completed'};
const STATUS_COLORS = {to_read: '#6b7280', reading: '#2563eb', completed: '#16a34a'};

function renderBook(book) {
    const pct = book.percentage ?? 0;
    const cover = book.coverUrl
        ? `<img class="book-cover" src="${escHtml(book.coverUrl)}" alt="cover" loading="lazy">`
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
                <button class="btn btn-secondary btn-sm btn-log-session" data-id="${book.id}" data-title="${escHtml(book.title ?? '')}">
                    + Log Session
                </button>
                ` : ''}
            </div>
        </div>
    `;
}

async function loadBooks(status) {
    const grid = $('books-grid');
    grid.innerHTML = '<div class="loading">Loading…</div>';

    const url = status ? `/api/books?status=${status}` : '/api/books';
    try {
        const res = await fetch(url);
        const books = await res.json();
        if (!books.length) {
            grid.innerHTML = '<div class="empty-state">No books found.</div>';
            return;
        }
        grid.innerHTML = books.map(renderBook).join('');
        grid.querySelectorAll('.btn-log-session').forEach(btn => {
            btn.addEventListener('click', () => openSessionModal(btn.dataset.id, btn.dataset.title));
        });
    } catch {
        showError('Failed to load books.');
        grid.innerHTML = '';
    }
}

function openSessionModal(bookId, title) {
    $('session-book-id').value = bookId;
    document.querySelector('#modal-reading-session h2').textContent = `Log Session — ${title}`;
    $('input-pages').value = '';
    $('input-session-date').value = new Date().toISOString().slice(0, 10);
    $('input-notes').value = '';
    $('modal-reading-session').classList.remove('hidden');
    $('input-pages').focus();
}

document.addEventListener('DOMContentLoaded', () => {
    loadBooks('');

    $('filter-status').addEventListener('change', e => loadBooks(e.target.value));

    $('btn-add-book').addEventListener('click', () => {
        $('input-isbn').value = '';
        $('modal-add-book').classList.remove('hidden');
        $('input-isbn').focus();
    });
    $('btn-cancel-book').addEventListener('click', () => $('modal-add-book').classList.add('hidden'));
    $('modal-add-book').addEventListener('click', e => {
        if (e.target === $('modal-add-book')) $('modal-add-book').classList.add('hidden');
    });

    $('form-add-book').addEventListener('submit', async e => {
        e.preventDefault();
        const isbn = $('input-isbn').value.trim();
        if (!isbn) return;
        const btn = e.target.querySelector('[type=submit]');
        btn.disabled = true;
        btn.textContent = 'Adding…';
        try {
            const res = await fetch('/api/books', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({isbn}),
            });
            if (!res.ok) {
                const err = await res.json();
                showError(err.error || 'Failed to add book.');
            } else {
                $('modal-add-book').classList.add('hidden');
                await loadBooks($('filter-status').value);
            }
        } catch {
            showError('Network error. Please try again.');
        }
        btn.disabled = false;
        btn.textContent = 'Add Book';
    });

    $('btn-cancel-session').addEventListener('click', () => $('modal-reading-session').classList.add('hidden'));
    $('modal-reading-session').addEventListener('click', e => {
        if (e.target === $('modal-reading-session')) $('modal-reading-session').classList.add('hidden');
    });

    $('form-reading-session').addEventListener('submit', async e => {
        e.preventDefault();
        const bookId = $('session-book-id').value;
        const pages = parseInt($('input-pages').value, 10);
        const date = $('input-session-date').value;
        const notes = $('input-notes').value.trim() || null;
        if (!pages || !date) return;
        const btn = e.target.querySelector('[type=submit]');
        btn.disabled = true;
        btn.textContent = 'Saving…';
        try {
            const res = await fetch(`/api/books/${bookId}/reading-sessions`, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({pages_read: pages, date, notes}),
            });
            if (!res.ok) {
                const err = await res.json();
                showError(err.error || 'Failed to log session.');
            } else {
                $('modal-reading-session').classList.add('hidden');
                await loadBooks($('filter-status').value);
            }
        } catch {
            showError('Network error. Please try again.');
        }
        btn.disabled = false;
        btn.textContent = 'Save Session';
    });
});
