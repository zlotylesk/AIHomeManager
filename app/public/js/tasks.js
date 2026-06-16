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
        const actions = status === 'pending'
            ? `<button class="btn btn-secondary btn-sm js-task-complete" data-id="${escHtml(t.id)}">Complete</button> <button class="btn btn-danger btn-sm js-task-cancel" data-id="${escHtml(t.id)}">Cancel</button>`
            : '';
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

    // Single delegated listener — survives every loadTasks() innerHTML reset.
    document.body.addEventListener('click', e => {
        const completeBtn = e.target.closest('.js-task-complete');
        if (completeBtn) {
            completeTask(completeBtn.dataset.id, completeBtn);
            return;
        }
        const cancelBtn = e.target.closest('.js-task-cancel');
        if (cancelBtn) {
            cancelTask(cancelBtn.dataset.id, cancelBtn);
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
