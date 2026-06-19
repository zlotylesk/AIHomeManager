'use strict';

const $ = id => document.getElementById(id);

function showError(msg) {
    const b = $('error-banner');
    b.textContent = msg;
    b.classList.remove('hidden');
    setTimeout(() => b.classList.add('hidden'), window.TOAST_TIMEOUT_MS);
}

function showInfo(msg) {
    const b = $('info-banner');
    b.textContent = msg;
    b.classList.remove('hidden');
    setTimeout(() => b.classList.add('hidden'), window.TOAST_TIMEOUT_MS);
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

    const safeHref = window.safeUrl(article.url) ?? '#';

    return `
        <div class="article-row${today && !compact ? ' article-today' : ''}" data-id="${article.id}">
            <div class="article-main">
                <a class="article-title" href="${escHtml(safeHref)}" target="_blank" rel="noopener">
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
        await window.apiCall(`/api/articles/${id}/read`, {method: 'POST'});
        const article = allArticles.find(a => a.id === id);
        if (article) {
            article.isRead = true;
            article.readAt = new Date().toISOString();
        }
        renderList($('filter-category').value);
    } catch (err) {
        showError(err.message || 'Failed to mark as read.');
        btn.disabled = false;
        btn.textContent = 'Mark as Read';
    }
}

async function createArticle(form) {
    const title = $('article-title').value.trim();
    const url = $('article-url').value.trim();
    if (!title || !url) return;

    const category = $('article-category').value.trim();
    const readTime = $('article-read-time').value;

    const body = {title, url, category: category || null};
    if (readTime) body.estimated_read_time = Number(readTime);

    const btn = form.querySelector('[type=submit]');
    btn.disabled = true;
    btn.textContent = 'Adding…';
    try {
        await window.apiCall('/api/articles', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(body),
        });
        form.reset();
        showInfo('Article added.');
        await loadArticles();
    } catch (err) {
        showError(err.message || 'Failed to add article.');
    }
    btn.disabled = false;
    btn.textContent = 'Add Article';
}

async function importArticles(form) {
    const file = $('import-file').files[0];
    if (!file) return;

    const fd = new FormData();
    fd.append('file', file);
    const encoding = $('import-encoding').value;
    if (encoding) fd.append('encoding', encoding);
    if ($('import-dry-run').checked) fd.append('dry_run', '1');

    const btn = form.querySelector('[type=submit]');
    const resultBox = $('import-result');
    resultBox.classList.add('hidden');
    btn.disabled = true;
    btn.textContent = 'Importing…';
    try {
        const res = await window.apiCall('/api/articles/import', {method: 'POST', body: fd});
        resultBox.textContent = `${res.dryRun ? '[Dry run] ' : ''}Imported: ${res.imported} · Skipped (duplicates): ${res.skipped} · Errors: ${res.errors}`;
        resultBox.classList.remove('hidden');
        form.reset();
        if (!res.dryRun && res.imported > 0) {
            await loadArticles();
        }
    } catch (err) {
        showError(err.message || 'Import failed.');
    }
    btn.disabled = false;
    btn.textContent = 'Import';
}

async function loadArticles() {
    $('articles-list').innerHTML = '<div class="loading">Loading…</div>';

    const [listResult, todayResult] = await Promise.allSettled([
        window.apiCall('/api/articles'),
        window.apiCall('/api/articles/today'),
    ]);

    if (listResult.status !== 'fulfilled') {
        showError('Failed to load articles.');
        $('articles-list').innerHTML = '';
        return;
    }
    allArticles = listResult.value;

    if (todayResult.status === 'fulfilled' && todayResult.value) {
        $('today-section').classList.remove('hidden');
        $('today-article').innerHTML = renderArticle(todayResult.value);
    }

    populateCategoryFilter();
    renderList('');
}

document.addEventListener('DOMContentLoaded', () => {
    loadArticles();
    $('filter-category').addEventListener('change', e => renderList(e.target.value));
    $('form-create-article').addEventListener('submit', e => {
        e.preventDefault();
        createArticle(e.target);
    });
    $('form-import-articles').addEventListener('submit', e => {
        e.preventDefault();
        importArticles(e.target);
    });

    document.body.addEventListener('click', e => {
        const btn = e.target.closest('.btn-mark-read');
        if (btn) {
            markAsRead(btn.dataset.id, btn);
        }
    });
});
