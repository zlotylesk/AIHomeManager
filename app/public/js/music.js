'use strict';

const $ = id => document.getElementById(id);

function showError(msg) {
    const b = $('error-banner');
    b.textContent = msg;
    b.classList.remove('hidden');
    setTimeout(() => b.classList.add('hidden'), window.TOAST_TIMEOUT_MS);
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
        const topParams = new URLSearchParams({period, limit: '20'});
        const cmpParams = new URLSearchParams({period, limit: '50'});
        const [topResult, collResult, cmpResult] = await Promise.allSettled([
            window.apiCall(`/api/music/top-albums?${topParams}`),
            window.apiCall('/api/music/collection'),
            window.apiCall(`/api/music/comparison?${cmpParams}`),
        ]);

        loading.classList.add('hidden');

        const errors = [];

        // Promise.allSettled isolates each fetch — a Last.fm 5xx no longer
        // wipes out the Discogs collection panel and vice versa. apiCall
        // unwraps JSON and rejects on !res.ok, so a rejected result is the
        // single signal for "this section failed".
        function readSection(result, label) {
            if (result.status === 'rejected') {
                errors.push(`${label}: ${result.reason.message ?? 'network error'}`);
                return null;
            }
            return result.value;
        }

        const topAlbums = readSection(topResult, 'Top albums') ?? [];
        const collection = readSection(collResult, 'Collection') ?? [];
        const comparison = readSection(cmpResult, 'Comparison');

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
