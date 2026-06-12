import { defineConfig } from 'vitest/config';

// Vitest config for the plugin's own JavaScript unit tests. Scope is deliberately
// narrow: only the grade-critical SCORM tracker (js/scorm_tracker.js). UI glue
// (amd/src/fullscreen.js, resize.js, editor_modal.js, ...) and the vendored pipwerks
// wrappers (assets/scorm/*) are out of scope. The embedded editor (exelearning/) ships
// its own Vitest suite and is not retested here.
export default defineConfig({
    test: {
        globals: true,
        // happy-dom gives the tracker a window/document for resolveObjectMap and the
        // beforeunload wiring, matching the embedded editor's own setup.
        environment: 'happy-dom',
        include: ['tests/js/**/*.test.js'],
        coverage: {
            provider: 'v8',
            reporter: ['text', 'lcovonly', 'cobertura'],
            reportsDirectory: './coverage',
            include: ['js/**'],
        },
    },
});
