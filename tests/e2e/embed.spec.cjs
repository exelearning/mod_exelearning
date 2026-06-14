// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

// Cross-browser e2e for the secure-mode external-embed mechanism (DEC-0061), run in
// Firefox via playwright-embed.config.cjs. It loads the REAL shim + relay against a
// self-contained harness (no Moodle needed): an opaque-origin sandboxed iframe whose
// content has a whitelisted YouTube embed, a RELATIVE local PDF and a non-whitelisted
// iframe. Asserts the shim promotes the first two to inline parent overlays (with the
// canonical/absolute URLs) and never promotes the third.

const { test, expect } = require('@playwright/test');

test('promotes whitelisted video + relative local PDF to inline parent players (Firefox)', async ({ page }) => {
    await page.goto('/tests/e2e/embed/parent.html');

    const players = page.locator('.exe-embed-overlay iframe');
    await expect.poll(() => players.count(), { timeout: 15000 }).toBe(2);

    const srcs = await players.evaluateAll((els) => els.map((e) => e.src));
    const hosts = srcs.map((s) => {
        try { return new URL(s).hostname.toLowerCase(); } catch (e) { return ''; }
    });

    // The video is rebuilt to the canonical nocookie URL (anchored, not a substring match).
    expect(srcs.some((s) => /^https:\/\/www\.youtube-nocookie\.com\/embed\/aqz-KE-bpKQ\b/.test(s))).toBe(true);
    // The relative local PDF is reported absolute and rendered (the relative-URL fix).
    expect(srcs.some((s) => /\/local\.pdf$/.test(s))).toBe(true);
    // The non-whitelisted host is never promoted (exact hostname check, not a substring).
    expect(hosts).not.toContain('example.com');
});
