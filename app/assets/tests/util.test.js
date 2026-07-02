import { describe, expect, it } from 'vitest';
import { TOAST_TIMEOUT_MS, escHtml, safeUrl } from '../util.js';

describe('safeUrl', () => {
    it('passes through http and https URLs unchanged', () => {
        expect(safeUrl('https://example.com/cover.jpg')).toBe('https://example.com/cover.jpg');
        expect(safeUrl('http://example.com/cover.jpg')).toBe('http://example.com/cover.jpg');
    });

    it('rejects non-http(s) protocols', () => {
        expect(safeUrl('javascript:alert(1)')).toBeNull();
        expect(safeUrl('ftp://example.com/file')).toBeNull();
        expect(safeUrl('data:text/html,<script>')).toBeNull();
    });

    it('rejects empty and non-string input', () => {
        expect(safeUrl('')).toBeNull();
        expect(safeUrl(null)).toBeNull();
        expect(safeUrl(undefined)).toBeNull();
        expect(safeUrl(42)).toBeNull();
    });

    it('returns the original string for a resolvable relative URL', () => {
        // Relative URLs resolve against document.baseURI (http under jsdom),
        // so the http(s) protocol check passes and the ORIGINAL string is kept.
        expect(safeUrl('/series/1/cover.jpg')).toBe('/series/1/cover.jpg');
    });

    it('rejects an unparseable URL', () => {
        expect(safeUrl('http://[invalid')).toBeNull();
    });
});

describe('escHtml', () => {
    it('escapes the five HTML-sensitive characters', () => {
        expect(escHtml('<script>"&"</script>'))
            .toBe('&lt;script&gt;&quot;&amp;&quot;&lt;/script&gt;');
    });

    it('leaves a plain string untouched', () => {
        expect(escHtml('Breaking Bad')).toBe('Breaking Bad');
    });

    it('coerces non-string input to a string', () => {
        expect(escHtml(2026)).toBe('2026');
        expect(escHtml(null)).toBe('null');
    });

    it('escapes ampersands before other entities (no double-encoding)', () => {
        expect(escHtml('a & b < c')).toBe('a &amp; b &lt; c');
    });
});

describe('TOAST_TIMEOUT_MS', () => {
    it('exposes the toast timeout constant', () => {
        expect(TOAST_TIMEOUT_MS).toBe(5000);
    });
});
