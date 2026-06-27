import { defineConfig } from 'vitest/config';

// JS unit tests for the frontend pure functions (util.js helpers + Series
// sort/filter/rating-highlight). jsdom is required because safeUrl() resolves
// against document.baseURI via the URL constructor.
export default defineConfig({
    test: {
        environment: 'jsdom',
        include: ['assets/**/*.test.js'],
        // assets/controllers/* is auto-loaded as Stimulus controllers by
        // bootstrap.js (webpackContext); test files live in assets/tests/ only.
        watch: false,
    },
});
