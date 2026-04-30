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

function isToday(dateStr) {
    if (!dateStr) return false;
    return dateStr.slice(0, 10) === new Date().toISOString().slice(0, 10);
}

function formatDate(dateStr) {
    if (!dateStr) return '';
    return new Date(dateStr).toLocaleDateString('pl-PL', {day: '2-digit', month: '2-digit', year: 'numeric'});
}

function renderArticle(article, compact = false) {
    const today = isToday(article.addedAt);
    const readBtn = article.isRead
        ? `<span class="read-badge">✓ Read ${formatDate(article.readAt)}</span>`
        : `<button class="btn btn-secondary btn-sm btn-mark-read" data-id="${article.id}">Mark as Read</button>`;

    return `
        <div class="article-row${today && !compact ? ' article-today' : ''}" data-id="${article.id}">
            <div class="article-main">
                <a class="article-title" href="${escHtml(article.url)}" target="_blank" rel="noopener">
                    ${escHtml(article.title)}${today ? ' <span class="badge-today">Dziś</span>' : ''}
                </a>
                <div class="article-meta">
                    ${article.category ? `<span class="tag">${escHtml(article.category)}</span>` : ''}
                    ${article.estimatedReadTime ? `<span>${article.estimatedReadTime} min read</span>` : ''}
                    <span>Added ${formatDate(article.addedAt)}</span>
                </div>
            </div>
            <div class="article-actions">${readBtn}</div>
        </div>
    `;
}

let allArticles = [];

function renderList(filterCat) {
    const list = $('articles-list');
    const filtered = filterCat ? allArticles.filter(a => a.category === filterCat) : allArticles;
    if (!filtered.length) {
        list.innerHTML = '<div class="empty-state">No articles found.</div>';
        return;
    }
    list.innerHTML = filtered.map(a => renderArticle(a)).join('');
    list.querySelectorAll('.btn-mark-read').forEach(btn => {
        btn.addEventListener('click', () => markAsRead(btn.dataset.id, btn));
    });
}

function populateCategoryFilter() {
    const categories = [...new Set(allArticles.map(a => a.category).filter(Boolean))].sort();
    const sel = $('filter-category');
    categories.forEach(cat => {
        const opt = document.createElement('option');
        opt.value = cat;
        opt.textContent = cat;
        sel.appendChild(opt);
    });
}

async function markAsRead(id, btn) {
    btn.disabled = true;
    btn.textContent = 'Saving…';
    try {
        const res = await fetch(`/api/articles/${id}/read`, {method: 'POST'});
        if (!res.ok) {
            showError('Failed to mark as read.');
            btn.disabled = false;
            btn.textContent = 'Mark as Read';
            return;
        }
        const article = allArticles.find(a => a.id === id);
        if (article) {
            article.isRead = true;
            article.readAt = new Date().toISOString();
        }
        renderList($('filter-category').value);
    } catch {
        showError('Network error. Please try again.');
        btn.disabled = false;
        btn.textContent = 'Mark as Read';
    }
}

async function loadArticles() {
    $('articles-list').innerHTML = '<div class="loading">Loading…</div>';
    try {
        const [listRes, todayRes] = await Promise.all([
            fetch('/api/articles'),
            fetch('/api/articles/today'),
        ]);
        allArticles = await listRes.json();

        if (todayRes.status === 200) {
            const todayArticle = await todayRes.json();
            if (todayArticle) {
                $('today-section').classList.remove('hidden');
                $('today-article').innerHTML = renderArticle(todayArticle);
                $('today-article').querySelectorAll('.btn-mark-read').forEach(btn => {
                    btn.addEventListener('click', () => markAsRead(btn.dataset.id, btn));
                });
            }
        }

        populateCategoryFilter();
        renderList('');
    } catch {
        showError('Failed to load articles.');
        $('articles-list').innerHTML = '';
    }
}

document.addEventListener('DOMContentLoaded', () => {
    loadArticles();
    $('filter-category').addEventListener('change', e => renderList(e.target.value));
});
