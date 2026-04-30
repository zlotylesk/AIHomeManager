'use strict';

const $ = id => document.getElementById(id);

function showError(msg) {
    const b = $('error-banner');
    b.textContent = msg;
    b.classList.remove('hidden');
    setTimeout(() => b.classList.add('hidden'), 8000);
}

function escHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function albumRow(a) {
    const img = a.imageUrl ? `<img class="album-thumb" src="${escHtml(a.imageUrl)}" alt="" loading="lazy">` : '<div class="album-thumb album-thumb--placeholder">🎵</div>';
    const plays = a.playCount != null ? `<span class="plays">${a.playCount} plays</span>` : '';
    return `<div class="album-row">${img}<div class="album-info"><strong>${escHtml(a.title ?? '—')}</strong><span>${escHtml(a.artist ?? '—')}</span></div>${plays}</div>`;
}

function vinylRow(r) {
    return `<div class="vinyl-row"><div class="vinyl-info"><strong>${escHtml(r.title ?? '—')}</strong><span>${escHtml(r.artist ?? '—')} ${r.year ? `· ${r.year}` : ''}</span></div><span class="vinyl-format">${escHtml(r.format ?? '')}</span></div>`;
}

async function loadMusic(period) {
    const content = $('music-content');
    const loading = $('music-loading');
    const errDiv = $('music-error');

    content.classList.add('hidden');
    errDiv.classList.add('hidden');
    loading.classList.remove('hidden');

    try {
        const [topRes, collRes, cmpRes] = await Promise.all([
            fetch(`/api/music/top-albums?period=${period}&limit=20`),
            fetch('/api/music/collection'),
            fetch(`/api/music/comparison?period=${period}&limit=50`),
        ]);

        loading.classList.add('hidden');

        const errors = [];
        let topAlbums = [], collection = [], comparison = null;

        if (topRes.ok) {
            topAlbums = await topRes.json();
        } else {
            const e = await topRes.json();
            errors.push(`Top albums: ${e.error ?? topRes.statusText}`);
        }

        if (collRes.ok) {
            collection = await collRes.json();
        } else {
            const e = await collRes.json();
            errors.push(`Collection: ${e.error ?? collRes.statusText}`);
        }

        if (cmpRes.ok) {
            comparison = await cmpRes.json();
        } else {
            const e = await cmpRes.json();
            errors.push(`Comparison: ${e.error ?? cmpRes.statusText}`);
        }

        if (errors.length) {
            errDiv.innerHTML = errors.map(e => `<div class="error-banner" style="margin-bottom:.5rem">${escHtml(e)}</div>`).join('');
            errDiv.classList.remove('hidden');
        }

        $('top-albums-period').textContent = `(${period})`;
        $('top-albums-list').innerHTML = topAlbums.length
            ? topAlbums.map(albumRow).join('')
            : '<div class="empty-state">No data.</div>';

        $('collection-list').innerHTML = collection.length
            ? collection.map(vinylRow).join('')
            : '<div class="empty-state">No vinyl records.</div>';

        if (comparison) {
            const score = comparison.matchScore ?? 0;
            $('match-score-badge').innerHTML = `<span class="match-score-value">${Math.round(score)}%</span> match`;

            $('owned-list').innerHTML = comparison.ownedAndListened?.length
                ? comparison.ownedAndListened.map(a => albumRow(a)).join('')
                : '<div class="empty-state">None.</div>';

            $('want-list').innerHTML = comparison.wantList?.length
                ? comparison.wantList.map(a => albumRow(a)).join('')
                : '<div class="empty-state">None.</div>';

            $('dusty-list').innerHTML = comparison.dustyShelf?.length
                ? comparison.dustyShelf.map(r => vinylRow(r)).join('')
                : '<div class="empty-state">None.</div>';
        } else {
            document.querySelector('.comparison-header').closest('section').style.display = 'none';
        }

        content.classList.remove('hidden');
    } catch {
        loading.classList.add('hidden');
        showError('Network error while loading music data.');
    }
}

document.addEventListener('DOMContentLoaded', () => {
    $('btn-load-music').addEventListener('click', () => {
        loadMusic($('period-select').value);
    });
    loadMusic('1month');
});
