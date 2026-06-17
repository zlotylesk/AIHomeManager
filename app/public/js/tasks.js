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

const STATUS_LABELS = {pending: 'Pending', completed: 'Completed', cancelled: 'Cancelled'};

async function loadTasks() {
    const loading = $('tasks-loading');
    const table = $('tasks-table');
    const empty = $('tasks-empty');
    loading.classList.remove('hidden');
    table.classList.add('hidden');
    empty.classList.add('hidden');

    let tasks;
    try {
        tasks = await window.apiCall('/api/tasks');
    } catch (err) {
        loading.classList.add('hidden');
        showError(err.message || 'Failed to load tasks.');
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
        const viewBtn = `<button class="btn btn-secondary btn-sm js-task-view" data-id="${escHtml(t.id)}">View</button>`;
        const stateActions = status === 'pending'
            ? ` <button class="btn btn-secondary btn-sm js-task-edit" data-id="${escHtml(t.id)}">Edit</button> <button class="btn btn-secondary btn-sm js-task-complete" data-id="${escHtml(t.id)}">Complete</button> <button class="btn btn-danger btn-sm js-task-cancel" data-id="${escHtml(t.id)}">Cancel</button>`
            : '';
        const actions = viewBtn + stateActions;
        return `
        <tr>
            <td>${escHtml(t.title)}</td>
            <td>${escHtml(formatDateTime(t.start))}</td>
            <td>${escHtml(formatDateTime(t.end))}</td>
            <td>${formatMinutes(t.durationMinutes)}</td>
            <td><span class="status-badge status-badge--${escHtml(status)}">${escHtml(label)}</span></td>
            <td>${actions}</td>
        </tr>`;
    }).join('');

    table.classList.remove('hidden');
}

async function completeTask(id, btn) {
    btn.disabled = true;
    btn.textContent = 'Completing…';
    try {
        await window.apiCall(`/api/tasks/${id}/complete`, {method: 'POST'});
        showInfo('Task completed.');
        await loadTasks();
    } catch (err) {
        showError(err.message || 'Failed to complete task.');
        btn.disabled = false;
        btn.textContent = 'Complete';
    }
}

async function cancelTask(id, btn) {
    if (!confirm('Cancel this task?')) {
        return;
    }
    btn.disabled = true;
    btn.textContent = 'Cancelling…';
    try {
        await window.apiCall(`/api/tasks/${id}/cancel`, {method: 'POST'});
        showInfo('Task cancelled.');
        await loadTasks();
    } catch (err) {
        showError(err.message || 'Failed to cancel task.');
        btn.disabled = false;
        btn.textContent = 'Cancel';
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
    $('detail-google').textContent = t.googleEventId ? `Synced (${t.googleEventId})` : 'Not synced';
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
        showError(err.message || 'Failed to load task details.');
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
        showError(err.message || 'Failed to load task for editing.');
    } finally {
        btn.disabled = false;
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
        showError(err.message || 'Failed to load report.');
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
            showError('End time must be after start time.');
            return;
        }
        const btn = e.target.querySelector('[type=submit]');
        btn.disabled = true;
        btn.textContent = 'Creating…';
        try {
            await window.apiCall('/api/tasks', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({title, start, end}),
            });
            e.target.reset();
            showInfo('Task created.');
            await loadTasks();
        } catch (err) {
            showError(err.message || 'Failed to create task.');
        }
        btn.disabled = false;
        btn.textContent = 'Create Task';
    });

    $('form-edit-task').addEventListener('submit', async e => {
        e.preventDefault();
        const id = $('edit-task-id').value;
        const title = $('edit-task-title').value.trim();
        const start = $('edit-task-start').value;
        const end = $('edit-task-end').value;
        if (!id || !title || !start || !end) return;
        if (end <= start) {
            showError('End time must be after start time.');
            return;
        }
        const btn = e.target.querySelector('[type=submit]');
        btn.disabled = true;
        btn.textContent = 'Saving…';
        try {
            await window.apiCall(`/api/tasks/${id}`, {
                method: 'PATCH',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({title, start, end}),
            });
            closeEditModal();
            showInfo('Task updated.');
            await loadTasks();
        } catch (err) {
            showError(err.message || 'Failed to update task.');
        }
        btn.disabled = false;
        btn.textContent = 'Save';
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
        btn.textContent = 'Loading…';
        try {
            await loadReport(from, to);
        } catch {
            showError('Network error. Please try again.');
        }
        btn.disabled = false;
        btn.textContent = 'Generate Report';
    });
});
