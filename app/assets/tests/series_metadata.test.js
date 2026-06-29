import { describe, expect, it } from 'vitest';
import { readMetadataInputs } from '../series/detail-view.js';

// Builds a detached form holding the shared `.js-meta-*` catalog inputs, the
// same field set used by both the "New Series" modal and the "Edit details"
// form. jsdom is provided by vitest.config.js (environment: 'jsdom').
function buildForm({cover = '', year = '', status = '', description = ''} = {}) {
    const form = document.createElement('form');
    form.innerHTML = `
        <input class="js-meta-cover" type="url">
        <input class="js-meta-year" type="number">
        <select class="js-meta-status">
            <option value="">—</option>
            <option value="ongoing">Ongoing</option>
            <option value="ended">Ended</option>
        </select>
        <textarea class="js-meta-description"></textarea>
    `;
    form.querySelector('.js-meta-cover').value = cover;
    form.querySelector('.js-meta-year').value = year;
    form.querySelector('.js-meta-status').value = status;
    form.querySelector('.js-meta-description').value = description;
    return form;
}

describe('readMetadataInputs', () => {
    it('reads and trims populated catalog inputs', () => {
        const form = buildForm({
            cover: '  https://example.com/p.jpg  ',
            year: '2008',
            status: 'ended',
            description: '  A chemistry teacher.  ',
        });
        expect(readMetadataInputs(form)).toEqual({
            coverUrl: 'https://example.com/p.jpg',
            year: 2008,
            status: 'ended',
            description: 'A chemistry teacher.',
        });
    });

    it('maps empty/blank fields to null (full-replace clears them)', () => {
        const form = buildForm({cover: '   ', year: '', status: '', description: ''});
        expect(readMetadataInputs(form)).toEqual({
            coverUrl: null,
            year: null,
            status: null,
            description: null,
        });
    });

    it('parses the year as an integer', () => {
        expect(readMetadataInputs(buildForm({year: '1999'})).year).toBe(1999);
    });

    it('tolerates a root missing the catalog inputs entirely', () => {
        expect(readMetadataInputs(document.createElement('div'))).toEqual({
            coverUrl: null,
            year: null,
            status: null,
            description: null,
        });
    });
});
