'use strict';

const $ = id => document.getElementById(id);

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
        ? `<span class="read-badge">✓ Przeczytano ${formatDate(article.readAt)}</span>`
        : `<button class="btn btn-secondary btn-sm btn-mark-read" data-id="${article.id}">Oznacz jako przeczytany</button>`;

    const safeHref = window.safeUrl(article.url) ?? '#';

    return `
        <div class="article-row${today && !compact ? ' article-today' : ''}" data-id="${article.id}">
            <div class="article-main">
                <a class="article-title" href="${window.escHtml(safeHref)}" target="_blank" rel="noopener">
                    ${window.escHtml(article.title)}${today ? ' <span class="badge-today">Dziś</span>' : ''}
                </a>
                <div class="article-meta">
                    ${article.category ? `<span class="tag">${window.escHtml(article.category)}</span>` : ''}
                    ${article.estimatedReadTime ? `<span>${article.estimatedReadTime} min czytania</span>` : ''}
                    <span>Dodano ${formatDate(article.addedAt)}</span>
                </div>
            </div>
            <div class="article-actions">
                <button class="btn btn-secondary btn-sm btn-view-details" data-id="${article.id}">Szczegóły</button>
                <button class="btn btn-secondary btn-sm btn-edit" data-id="${article.id}">Edytuj</button>
                ${readBtn}
                <button class="btn btn-danger btn-sm btn-delete" data-id="${article.id}">Usuń</button>
            </div>
        </div>
    `;
}

let allArticles = [];

function renderList() {
    const list = $('articles-list');
    const filterCat = $('filter-category').value;
    const filterStatus = $('filter-status').value;
    let filtered = allArticles;
    if (filterCat) {
        filtered = filtered.filter(a => a.category === filterCat);
    }
    if ('read' === filterStatus) {
        filtered = filtered.filter(a => a.isRead);
    } else if ('unread' === filterStatus) {
        filtered = filtered.filter(a => !a.isRead);
    }
    if (!filtered.length) {
        list.innerHTML = '<div class="empty-state">Nie znaleziono artykułów.</div>';
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

function renderDetail(a) {
    const safeHref = window.safeUrl(a.url) ?? '#';
    const status = a.isRead ? `Przeczytano${a.readAt ? ' · ' + formatDate(a.readAt) : ''}` : 'Nieprzeczytany';
    return `
        <dl class="detail-list">
            <dt>URL</dt>
            <dd><a href="${window.escHtml(safeHref)}" target="_blank" rel="noopener">${window.escHtml(a.url)}</a></dd>
            <dt>Kategoria</dt>
            <dd>${a.category ? window.escHtml(a.category) : '—'}</dd>
            <dt>Czas czytania</dt>
            <dd>${a.estimatedReadTime ? window.escHtml(String(a.estimatedReadTime)) + ' min' : '—'}</dd>
            <dt>Dodano</dt>
            <dd>${window.escHtml(formatDate(a.addedAt))}</dd>
            <dt>Status</dt>
            <dd>${window.escHtml(status)}</dd>
        </dl>
    `;
}

async function openDetail(id) {
    const modal = $('article-detail-modal');
    $('detail-title').textContent = 'Ładowanie…';
    $('detail-body').innerHTML = '';
    modal.classList.remove('hidden');
    try {
        const article = await window.apiCall(`/api/articles/${id}`);
        $('detail-title').textContent = article.title;
        $('detail-body').innerHTML = renderDetail(article);
    } catch (err) {
        closeDetail();
        window.showError(err.message || 'Nie udało się wczytać artykułu.');
    }
}

function closeDetail() {
    $('article-detail-modal').classList.add('hidden');
}

function openEdit(id) {
    const article = allArticles.find(a => a.id === id);
    if (!article) return;
    $('edit-id').value = article.id;
    $('edit-title').value = article.title;
    $('edit-category').value = article.category ?? '';
    $('edit-read-time').value = article.estimatedReadTime ?? '';
    $('article-edit-modal').classList.remove('hidden');
}

function closeEdit() {
    $('article-edit-modal').classList.add('hidden');
}

async function saveEdit(form) {
    const id = $('edit-id').value;
    const title = $('edit-title').value.trim();
    if (!title) return;

    const category = $('edit-category').value.trim();
    const readTime = $('edit-read-time').value;

    const body = {title, category: category || null};
    if (readTime) body.estimated_read_time = Number(readTime);

    const btn = form.querySelector('[type=submit]');
    btn.disabled = true;
    btn.textContent = 'Zapisywanie…';
    try {
        await window.apiCall(`/api/articles/${id}`, {
            method: 'PUT',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(body),
        });
        closeEdit();
        window.showInfo('Artykuł zaktualizowany.');
        await loadArticles();
    } catch (err) {
        window.showError(err.message || 'Nie udało się zaktualizować artykułu.');
    }
    btn.disabled = false;
    btn.textContent = 'Zapisz';
}

async function deleteArticle(id, btn) {
    if (!confirm('Usunąć ten artykuł? Tej operacji nie można cofnąć.')) {
        return;
    }
    btn.disabled = true;
    btn.textContent = 'Usuwanie…';
    try {
        await window.apiCall(`/api/articles/${id}`, {method: 'DELETE'});
        window.showInfo('Artykuł usunięty.');
        await loadArticles();
    } catch (err) {
        window.showError(err.message || 'Nie udało się usunąć artykułu.');
        btn.disabled = false;
        btn.textContent = 'Usuń';
    }
}

async function markAsRead(id, btn) {
    btn.disabled = true;
    btn.textContent = 'Zapisywanie…';
    try {
        await window.apiCall(`/api/articles/${id}/read`, {method: 'POST'});
        const article = allArticles.find(a => a.id === id);
        if (article) {
            article.isRead = true;
            article.readAt = new Date().toISOString();
        }
        renderList();
    } catch (err) {
        window.showError(err.message || 'Nie udało się oznaczyć jako przeczytany.');
        btn.disabled = false;
        btn.textContent = 'Oznacz jako przeczytany';
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
    btn.textContent = 'Dodawanie…';
    try {
        await window.apiCall('/api/articles', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(body),
        });
        form.reset();
        window.showInfo('Artykuł dodany.');
        await loadArticles();
    } catch (err) {
        window.showError(err.message || 'Nie udało się dodać artykułu.');
    }
    btn.disabled = false;
    btn.textContent = 'Dodaj artykuł';
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
    btn.textContent = 'Importowanie…';
    try {
        const res = await window.apiCall('/api/articles/import', {method: 'POST', body: fd});
        resultBox.textContent = `${res.dryRun ? '[Test] ' : ''}Zaimportowano: ${res.imported} · Pominięto (duplikaty): ${res.skipped} · Błędy: ${res.errors}`;
        resultBox.classList.remove('hidden');
        form.reset();
        if (!res.dryRun && res.imported > 0) {
            await loadArticles();
        }
    } catch (err) {
        window.showError(err.message || 'Import nie powiódł się.');
    }
    btn.disabled = false;
    btn.textContent = 'Importuj';
}

async function downloadExport(format, btn) {
    const original = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Eksportowanie…';
    try {
        const meta = document.querySelector('meta[name="api-key"]');
        const apiKey = meta ? meta.getAttribute('content') : '';
        const headers = {};
        if (apiKey) {
            headers['X-API-Key'] = apiKey;
        }
        const res = await fetch(`/api/articles/export?format=${encodeURIComponent(format)}`, {headers});
        if (!res.ok) {
            let message = `Eksport nie powiódł się (${res.status}).`;
            try {
                const payload = await res.json();
                if (payload && 'string' === typeof payload.error) {
                    message = payload.error;
                }
            } catch (_) {
            }
            throw new Error(message);
        }
        const blob = await res.blob();
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `articles.${format}`;
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
        window.showInfo(`Artykuły wyeksportowane jako ${format.toUpperCase()}.`);
    } catch (err) {
        window.showError(err.message || 'Nie udało się wyeksportować artykułów.');
    } finally {
        btn.disabled = false;
        btn.textContent = original;
    }
}

async function loadArticles() {
    $('articles-list').innerHTML = '<div class="loading">Ładowanie…</div>';

    const [listResult, todayResult] = await Promise.allSettled([
        // The panel filters by category in the browser and builds the category
        // list from every article, so it needs the whole set rather than a page.
        window.fetchAllPages((page) => window.apiCall(`/api/articles?page=${page}`)),
        window.apiCall('/api/articles/today'),
    ]);

    if (listResult.status !== 'fulfilled') {
        window.showError('Nie udało się wczytać artykułów.');
        $('articles-list').innerHTML = '';
        return;
    }
    allArticles = listResult.value;

    if (todayResult.status === 'fulfilled' && todayResult.value) {
        $('today-section').classList.remove('hidden');
        $('today-article').innerHTML = renderArticle(todayResult.value);
    }

    populateCategoryFilter();
    renderList();
}

document.addEventListener('DOMContentLoaded', () => {
    loadArticles();
    $('filter-category').addEventListener('change', () => renderList());
    $('filter-status').addEventListener('change', () => renderList());
    $('btn-export-csv').addEventListener('click', e => downloadExport('csv', e.currentTarget));
    $('btn-export-pdf').addEventListener('click', e => downloadExport('pdf', e.currentTarget));
    $('form-create-article').addEventListener('submit', e => {
        e.preventDefault();
        createArticle(e.target);
    });
    $('form-import-articles').addEventListener('submit', e => {
        e.preventDefault();
        importArticles(e.target);
    });

    document.body.addEventListener('click', e => {
        const readBtn = e.target.closest('.btn-mark-read');
        if (readBtn) {
            markAsRead(readBtn.dataset.id, readBtn);
            return;
        }
        const detailBtn = e.target.closest('.btn-view-details');
        if (detailBtn) {
            openDetail(detailBtn.dataset.id);
            return;
        }
        const editBtn = e.target.closest('.btn-edit');
        if (editBtn) {
            openEdit(editBtn.dataset.id);
            return;
        }
        const deleteBtn = e.target.closest('.btn-delete');
        if (deleteBtn) {
            deleteArticle(deleteBtn.dataset.id, deleteBtn);
        }
    });

    $('form-edit-article').addEventListener('submit', e => {
        e.preventDefault();
        saveEdit(e.target);
    });
    $('edit-cancel').addEventListener('click', closeEdit);

    $('detail-close').addEventListener('click', closeDetail);
    [['article-detail-modal', closeDetail], ['article-edit-modal', closeEdit]].forEach(([modalId, close]) => {
        $(modalId).addEventListener('click', e => {
            if (e.target === e.currentTarget) close();
        });
    });
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            closeDetail();
            closeEdit();
        }
    });
});
