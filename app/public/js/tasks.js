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

function formatMinutes(m) {
    const h = Math.floor(m / 60);
    const min = m % 60;
    return h > 0 ? `${h}h ${min}m` : `${min}m`;
}

async function loadReport(from, to) {
    const result = $('report-result');
    const empty = $('report-empty');
    result.classList.add('hidden');
    empty.classList.add('hidden');

    const res = await fetch(`/api/tasks/time-report?from=${from}&to=${to}`);
    if (!res.ok) {
        const err = await res.json();
        showError(err.error || 'Failed to load report.');
        return;
    }
    const data = await res.json();

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
