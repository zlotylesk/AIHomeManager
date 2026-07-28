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

function formatMinutes(m) {
    const h = Math.floor(m / 60);
    const min = m % 60;
    return h > 0 ? `${h}h ${min}m` : `${min}m`;
}

function formatDateTime(iso) {
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return iso;
    return d.toLocaleString([], {dateStyle: 'medium', timeStyle: 'short'});
}

function toLocalInputValue(iso) {
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '';
    const pad = n => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

const STATUS_LABELS = {pending: 'Oczekujące', completed: 'Zakończone', cancelled: 'Anulowane'};

async function loadTasks() {
    const loading = $('tasks-loading');
    const table = $('tasks-table');
    const empty = $('tasks-empty');
    loading.classList.remove('hidden');
    table.classList.add('hidden');
    empty.classList.add('hidden');

    const statusFilter = $('task-filter-status').value;
    const url = statusFilter ? `/api/tasks?status=${encodeURIComponent(statusFilter)}` : '/api/tasks';

    let tasks;
    try {
        tasks = await window.apiCall(url);
    } catch (err) {
        loading.classList.add('hidden');
        showError(err.message || 'Nie udało się wczytać zadań.');
        return;
    }

    loading.classList.add('hidden');

    if (!Array.isArray(tasks) || tasks.length === 0) {
        empty.classList.remove('hidden');
        return;
    }

    const tbody = table.querySelector('tbody');
    tbody.innerHTML = tasks.map(t => {
        const status = String(t.status);
        const label = STATUS_LABELS[status] ?? status;
        const viewBtn = `<button class="btn btn-secondary btn-sm js-task-view" data-id="${escHtml(t.id)}">Podgląd</button>`;
        const stateActions = status === 'pending'
            ? ` <button class="btn btn-secondary btn-sm js-task-edit" data-id="${escHtml(t.id)}">Edytuj</button> <button class="btn btn-secondary btn-sm js-task-complete" data-id="${escHtml(t.id)}">Zakończ</button> <button class="btn btn-danger btn-sm js-task-cancel" data-id="${escHtml(t.id)}">Anuluj</button>`
            : '';
        const deleteBtn = ` <button class="btn btn-danger btn-sm js-task-delete" data-id="${escHtml(t.id)}">Usuń</button>`;
        const actions = viewBtn + stateActions + deleteBtn;
        return `
        <tr>
            <td data-label="Tytuł">${escHtml(t.title)}</td>
            <td data-label="Początek">${escHtml(formatDateTime(t.start))}</td>
            <td data-label="Koniec">${escHtml(formatDateTime(t.end))}</td>
            <td data-label="Czas trwania">${formatMinutes(t.durationMinutes)}</td>
            <td data-label="Status"><span class="status-badge status-badge--${escHtml(status)}">${escHtml(label)}</span></td>
            <td data-label="Akcje">${actions}</td>
        </tr>`;
    }).join('');

    table.classList.remove('hidden');
}

async function completeTask(id, btn) {
    btn.disabled = true;
    btn.textContent = 'Kończenie…';
    try {
        await window.apiCall(`/api/tasks/${id}/complete`, {method: 'POST'});
        showInfo('Zadanie zakończone.');
        await loadTasks();
    } catch (err) {
        showError(err.message || 'Nie udało się zakończyć zadania.');
        btn.disabled = false;
        btn.textContent = 'Zakończ';
    }
}

async function cancelTask(id, btn) {
    if (!confirm('Anulować to zadanie?')) {
        return;
    }
    btn.disabled = true;
    btn.textContent = 'Anulowanie…';
    try {
        await window.apiCall(`/api/tasks/${id}/cancel`, {method: 'POST'});
        showInfo('Zadanie anulowane.');
        await loadTasks();
    } catch (err) {
        showError(err.message || 'Nie udało się anulować zadania.');
        btn.disabled = false;
        btn.textContent = 'Anuluj';
    }
}

async function deleteTask(id, btn) {
    if (!confirm('Usunąć to zadanie? Tej operacji nie można cofnąć.')) {
        return;
    }
    btn.disabled = true;
    btn.textContent = 'Usuwanie…';
    try {
        await window.apiCall(`/api/tasks/${id}`, {method: 'DELETE'});
        showInfo('Zadanie usunięte.');
        await loadTasks();
    } catch (err) {
        showError(err.message || 'Nie udało się usunąć zadania.');
        btn.disabled = false;
        btn.textContent = 'Usuń';
    }
}

function renderTaskDetail(t) {
    const status = String(t.status);
    const label = STATUS_LABELS[status] ?? status;
    $('detail-title').textContent = t.title;
    $('detail-status').innerHTML = `<span class="status-badge status-badge--${escHtml(status)}">${escHtml(label)}</span>`;
    $('detail-start').textContent = formatDateTime(t.start);
    $('detail-end').textContent = formatDateTime(t.end);
    $('detail-duration').textContent = formatMinutes(t.durationMinutes);
    $('detail-google').textContent = t.googleEventId ? `Zsynchronizowano (${t.googleEventId})` : 'Niezsynchronizowane';
}

function openDetailModal() {
    $('task-detail-modal').classList.remove('hidden');
}

function closeDetailModal() {
    $('task-detail-modal').classList.add('hidden');
}

async function viewTask(id, btn) {
    btn.disabled = true;
    try {
        const task = await window.apiCall(`/api/tasks/${id}`);
        renderTaskDetail(task);
        openDetailModal();
    } catch (err) {
        showError(err.message || 'Nie udało się wczytać szczegółów zadania.');
    } finally {
        btn.disabled = false;
    }
}

function openEditModal() {
    $('task-edit-modal').classList.remove('hidden');
}

function closeEditModal() {
    $('task-edit-modal').classList.add('hidden');
}

async function editTask(id, btn) {
    btn.disabled = true;
    try {
        const task = await window.apiCall(`/api/tasks/${id}`);
        $('edit-task-id').value = task.id;
        $('edit-task-title').value = task.title;
        $('edit-task-start').value = toLocalInputValue(task.start);
        $('edit-task-end').value = toLocalInputValue(task.end);
        openEditModal();
    } catch (err) {
        showError(err.message || 'Nie udało się wczytać zadania do edycji.');
    } finally {
        btn.disabled = false;
    }
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
        const res = await fetch(`/api/tasks/export?format=${encodeURIComponent(format)}`, {headers});
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
        a.download = `tasks.${format}`;
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
        showInfo(`Zadania wyeksportowane jako ${format.toUpperCase()}.`);
    } catch (err) {
        showError(err.message || 'Nie udało się wyeksportować zadań.');
    } finally {
        btn.disabled = false;
        btn.textContent = original;
    }
}

async function loadReport(from, to) {
    const result = $('report-result');
    const empty = $('report-empty');
    result.classList.add('hidden');
    empty.classList.add('hidden');

    const params = new URLSearchParams({from, to});
    let data;
    try {
        data = await window.apiCall(`/api/tasks/time-report?${params}`);
    } catch (err) {
        showError(err.message || 'Nie udało się wczytać raportu.');
        return;
    }

    if (data.breakdown.length === 0) {
        empty.classList.remove('hidden');
        return;
    }

    $('stat-hours').textContent = `${data.totalHours}h`;
    $('stat-minutes').textContent = `${data.totalMinutes}m`;

    const tbody = $('breakdown-table').querySelector('tbody');
    tbody.innerHTML = data.breakdown.map(t => `
        <tr>
            <td>${escHtml(t.title)}</td>
            <td>${t.minutes}</td>
            <td>${formatMinutes(t.minutes)}</td>
        </tr>
    `).join('');

    result.classList.remove('hidden');
}

document.addEventListener('DOMContentLoaded', () => {
    loadTasks();

    $('task-filter-status').addEventListener('change', () => loadTasks());

    $('btn-export-csv').addEventListener('click', e => downloadExport('csv', e.currentTarget));
    $('btn-export-pdf').addEventListener('click', e => downloadExport('pdf', e.currentTarget));

    document.body.addEventListener('click', e => {
        const viewBtn = e.target.closest('.js-task-view');
        if (viewBtn) {
            viewTask(viewBtn.dataset.id, viewBtn);
            return;
        }
        const editBtn = e.target.closest('.js-task-edit');
        if (editBtn) {
            editTask(editBtn.dataset.id, editBtn);
            return;
        }
        const completeBtn = e.target.closest('.js-task-complete');
        if (completeBtn) {
            completeTask(completeBtn.dataset.id, completeBtn);
            return;
        }
        const cancelBtn = e.target.closest('.js-task-cancel');
        if (cancelBtn) {
            cancelTask(cancelBtn.dataset.id, cancelBtn);
            return;
        }
        const deleteBtn = e.target.closest('.js-task-delete');
        if (deleteBtn) {
            deleteTask(deleteBtn.dataset.id, deleteBtn);
            return;
        }
        if (e.target.closest('.js-detail-close') || e.target.id === 'task-detail-modal') {
            closeDetailModal();
        }
        if (e.target.closest('.js-edit-close') || e.target.id === 'task-edit-modal') {
            closeEditModal();
        }
    });

    document.addEventListener('keydown', e => {
        if ('Escape' === e.key) {
            closeDetailModal();
            closeEditModal();
        }
    });

    $('form-create-task').addEventListener('submit', async e => {
        e.preventDefault();
        const title = $('task-title').value.trim();
        const start = $('task-start').value;
        const end = $('task-end').value;
        if (!title || !start || !end) return;
        if (end <= start) {
            showError('Czas zakończenia musi być późniejszy niż czas rozpoczęcia.');
            return;
        }
        const btn = e.target.querySelector('[type=submit]');
        btn.disabled = true;
        btn.textContent = 'Tworzenie…';
        try {
            await window.apiCall('/api/tasks', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({title, start, end}),
            });
            e.target.reset();
            showInfo('Zadanie utworzone.');
            await loadTasks();
        } catch (err) {
            showError(err.message || 'Nie udało się utworzyć zadania.');
        }
        btn.disabled = false;
        btn.textContent = 'Utwórz zadanie';
    });

    $('form-edit-task').addEventListener('submit', async e => {
        e.preventDefault();
        const id = $('edit-task-id').value;
        const title = $('edit-task-title').value.trim();
        const start = $('edit-task-start').value;
        const end = $('edit-task-end').value;
        if (!id || !title || !start || !end) return;
        if (end <= start) {
            showError('Czas zakończenia musi być późniejszy niż czas rozpoczęcia.');
            return;
        }
        const btn = e.target.querySelector('[type=submit]');
        btn.disabled = true;
        btn.textContent = 'Zapisywanie…';
        try {
            await window.apiCall(`/api/tasks/${id}`, {
                method: 'PATCH',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({title, start, end}),
            });
            closeEditModal();
            showInfo('Zadanie zaktualizowane.');
            await loadTasks();
        } catch (err) {
            showError(err.message || 'Nie udało się zaktualizować zadania.');
        }
        btn.disabled = false;
        btn.textContent = 'Zapisz';
    });

    const today = new Date().toISOString().slice(0, 10);
    const firstOfMonth = today.slice(0, 8) + '01';
    $('input-from').value = firstOfMonth;
    $('input-to').value = today;

    $('form-time-report').addEventListener('submit', async e => {
        e.preventDefault();
        const from = $('input-from').value;
        const to = $('input-to').value;
        if (!from || !to) return;
        const btn = e.target.querySelector('[type=submit]');
        btn.disabled = true;
        btn.textContent = 'Ładowanie…';
        try {
            await loadReport(from, to);
        } catch {
            showError('Błąd sieci. Spróbuj ponownie.');
        }
        btn.disabled = false;
        btn.textContent = 'Wygeneruj raport';
    });
});
