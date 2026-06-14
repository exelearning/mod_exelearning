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

// Unit tests for the secure-mode external-embed shim + relay (DEC-0059). The shim
// (in-iframe) decides what to promote; the relay (parent) validates each reported URL
// and rebuilds a safe player URL. Side-effect imports expose the API on window.* (and
// module.exports). globals (describe/it/expect) come from vitest.config.mjs.
import '../../js/exe_embed_shim.js';
import '../../js/exe_embed_relay.js';

const shim = window.exeEmbedShim;
const relay = window.exeEmbedRelay;

const HOSTS = [
    'www.youtube.com', 'youtube.com', 'www.youtube-nocookie.com',
    'youtube-nocookie.com', 'player.vimeo.com', 'vimeo.com',
    'www.dailymotion.com', 'dailymotion.com', 'geo.dailymotion.com',
    'mediateca.educa.madrid.org',
];
const WL = relay.buildWhitelist(HOSTS);
const ORIGIN = window.location.origin;
const CONTENT_SRC = ORIGIN + '/pluginfile.php/5/mod_exelearning/content/3/index.html';

describe('exe_embed_relay validate() — videos', () => {
    it('rebuilds the canonical youtube-nocookie URL from a youtube.com embed', () => {
        expect(relay.validate('https://www.youtube.com/embed/aqz-KE-bpKQ', CONTENT_SRC, WL))
            .toEqual({ url: 'https://www.youtube-nocookie.com/embed/aqz-KE-bpKQ', kind: 'video' });
    });

    it('accepts a youtube-nocookie embed and keeps the id', () => {
        expect(relay.validate('https://www.youtube-nocookie.com/embed/abc123', CONTENT_SRC, WL).url)
            .toBe('https://www.youtube-nocookie.com/embed/abc123');
    });

    it('rebuilds the canonical Vimeo player URL', () => {
        expect(relay.validate('https://player.vimeo.com/video/76979871', CONTENT_SRC, WL))
            .toEqual({ url: 'https://player.vimeo.com/video/76979871', kind: 'video' });
    });

    it('rebuilds the canonical Dailymotion embed URL', () => {
        expect(relay.validate('https://www.dailymotion.com/embed/video/x8abc12', CONTENT_SRC, WL))
            .toEqual({ url: 'https://www.dailymotion.com/embed/video/x8abc12', kind: 'video' });
    });

    it('rejects a malformed Dailymotion path', () => {
        expect(relay.validate('https://www.dailymotion.com/video/x8abc12', CONTENT_SRC, WL)).toBeNull();
    });

    it('rebuilds the canonical EducaMadrid embed URL (adds /fs)', () => {
        expect(relay.validate('https://mediateca.educa.madrid.org/video/u555bvi3bk5wsabh', CONTENT_SRC, WL))
            .toEqual({ url: 'https://mediateca.educa.madrid.org/video/u555bvi3bk5wsabh/fs', kind: 'video' });
        expect(relay.validate('https://mediateca.educa.madrid.org/video/u555bvi3bk5wsabh/fs', CONTENT_SRC, WL).url)
            .toBe('https://mediateca.educa.madrid.org/video/u555bvi3bk5wsabh/fs');
    });

    it('rejects an EducaMadrid look-alike host', () => {
        expect(relay.validate('https://mediateca.educa.madrid.org.evil.com/video/u555bvi3bk5wsabh', CONTENT_SRC, WL))
            .toBeNull();
    });

    it('rejects a look-alike host (youtube.com.evil.com)', () => {
        expect(relay.validate('https://www.youtube.com.evil.com/embed/aqz-KE-bpKQ', CONTENT_SRC, WL)).toBeNull();
    });

    it('rejects userinfo tricks (evil.com@youtube.com)', () => {
        expect(relay.validate('https://evil.com@www.youtube.com/embed/aqz-KE-bpKQ', CONTENT_SRC, WL)).toBeNull();
    });

    it('rejects non-https whitelisted hosts', () => {
        expect(relay.validate('http://www.youtube.com/embed/aqz-KE-bpKQ', CONTENT_SRC, WL)).toBeNull();
    });

    it('rejects malformed video ids', () => {
        expect(relay.validate('https://www.youtube.com/embed/', CONTENT_SRC, WL)).toBeNull();
        expect(relay.validate('https://player.vimeo.com/video/not-a-number', CONTENT_SRC, WL)).toBeNull();
    });
});

describe('exe_embed_relay validate() — PDFs', () => {
    it('accepts any cross-origin https PDF', () => {
        expect(relay.validate('https://example.com/docs/report.pdf', CONTENT_SRC, WL))
            .toEqual({ url: 'https://example.com/docs/report.pdf', kind: 'pdf' });
    });

    it('accepts a same-origin PDF under the content directory', () => {
        const pdf = ORIGIN + '/pluginfile.php/5/mod_exelearning/content/3/files/local.pdf';
        expect(relay.validate(pdf, CONTENT_SRC, WL)).toEqual({ url: pdf, kind: 'pdf' });
    });

    it('rejects a same-origin PDF outside the package (e.g. an admin route)', () => {
        expect(relay.validate(ORIGIN + '/admin/secret.pdf', CONTENT_SRC, WL)).toBeNull();
    });

    it('rejects non-pdf, non-whitelisted iframes', () => {
        expect(relay.validate('https://example.com/', CONTENT_SRC, WL)).toBeNull();
    });
});

describe('exe_embed_shim promotion decisions', () => {
    it('isPdfUrl detects .pdf paths (ignoring the query)', () => {
        expect(shim.isPdfUrl('https://x.test/a/b.pdf')).toBe(true);
        expect(shim.isPdfUrl('https://x.test/a/b.pdf?download=1')).toBe(true);
        expect(shim.isPdfUrl('https://x.test/a/b.html')).toBe(false);
    });

    it('isPromotable: whitelisted videos and PDFs yes, other iframes no', () => {
        expect(shim.isPromotable('https://www.youtube.com/embed/x12345', HOSTS)).toBe(true);
        expect(shim.isPromotable('https://files.test/manual.pdf', HOSTS)).toBe(true);
        expect(shim.isPromotable('https://example.com/', HOSTS)).toBe(false);
    });

    it('promote() replaces whitelisted/PDF iframes with sized placeholders and leaves others', () => {
        // Scan a DETACHED container: its iframes are never connected to a document, so
        // happy-dom never navigates their src and the test needs no network.
        const root = document.createElement('div');
        root.innerHTML =
            '<iframe id="yt" src="https://www.youtube-nocookie.com/embed/aqz-KE-bpKQ" width="560" height="315"></iframe>' +
            '<iframe id="pdf" src="https://example.com/x.pdf" width="600" height="400"></iframe>' +
            '<iframe id="ext" src="https://example.com/" width="320" height="120"></iframe>';

        const created = shim.promote(root, HOSTS, { n: 0 });

        expect(created.length).toBe(2);
        // The non-whitelisted iframe survives untouched; the promoted ones are gone.
        expect(root.querySelector('#ext')).not.toBeNull();
        expect(root.querySelector('#yt')).toBeNull();
        expect(root.querySelector('#pdf')).toBeNull();

        // The first placeholder reserves the YouTube box and carries the url + id.
        const ph = created[0];
        expect(ph.getAttribute('data-exe-embed-id')).toBe('exe-embed-1');
        expect(ph.getAttribute('data-exe-embed-url')).toContain('youtube-nocookie.com');
        expect(ph.style.width).toBe('560px');
        expect(ph.style.height).toBe('315px');
    });

    it('reports a RELATIVE src as an absolute URL (the parent relay cannot resolve relatives)', () => {
        // Regression: a local package PDF is referenced relatively (e.g. mod_exelearning
        // serves package assets without rewriting URLs). The shim runs inside the content,
        // so it must resolve the src against the content location before reporting; the
        // parent would otherwise resolve it against the host page and reject it.
        const root = document.createElement('div');
        root.innerHTML = '<iframe id="lpdf" src="files/local-sample.pdf" width="600" height="400"></iframe>';

        const created = shim.promote(root, HOSTS, { n: 0 });

        expect(created.length).toBe(1);
        const reported = created[0].getAttribute('data-exe-embed-url');
        expect(reported).toMatch(/^https?:\/\//); // absolute, not the raw "files/..."
        expect(reported).toMatch(/files\/local-sample\.pdf$/);
    });
});

describe('exe_embed_relay makePlayer() attributes', () => {
    it('builds a video player with autoplay/fullscreen grants + strict-origin referrer', () => {
        const frame = relay.makePlayer({ url: 'https://www.youtube-nocookie.com/embed/abc123', kind: 'video' });
        expect(frame.tagName).toBe('IFRAME');
        expect(frame.getAttribute('allow')).toContain('autoplay');
        expect(frame.getAttribute('allow')).toContain('fullscreen');
        expect(frame.hasAttribute('allowfullscreen')).toBe(true);
        expect(frame.getAttribute('referrerpolicy')).toBe('strict-origin-when-cross-origin');
        expect(frame.src).toContain('youtube-nocookie.com');
    });

    it('builds a PDF player with no-referrer and no autoplay grant', () => {
        const frame = relay.makePlayer({ url: 'https://files.test/manual.pdf', kind: 'pdf' });
        expect(frame.getAttribute('referrerpolicy')).toBe('no-referrer');
        expect(frame.getAttribute('allow') || '').not.toContain('autoplay');
    });
});

describe('exe_embed_relay createRelay() overlays players from messages', () => {
    let iframe;
    beforeEach(() => {
        document.body.innerHTML = '';
        iframe = document.createElement('iframe');
        document.body.appendChild(iframe);
    });

    it('creates an inline overlay player for a valid embed and removes it when no longer reported', () => {
        const r = relay.createRelay({ whitelist: HOSTS });
        // The instance validate() binds the configured whitelist.
        expect(r.validate('https://www.youtube.com/embed/abc123', 'http://x/content/1/index.html'))
            .toMatchObject({ kind: 'video' });
        r.onMessage({
            source: iframe.contentWindow,
            data: {
                type: 'exe-embed',
                action: 'sync',
                embeds: [{ id: 'e1', url: 'https://www.youtube.com/embed/abc123', x: 0, y: 0, w: 480, h: 270 }],
            },
        });
        const players = document.querySelectorAll('.exe-embed-overlay iframe');
        expect(players.length).toBe(1);
        expect(players[0].src).toMatch(/youtube-nocookie\.com\/embed\/abc123$/);

        // Stale removal: a later sync without the embed removes its player.
        r.onMessage({ source: iframe.contentWindow, data: { type: 'exe-embed', action: 'sync', embeds: [] } });
        expect(document.querySelectorAll('.exe-embed-overlay iframe').length).toBe(0);
    });

    it('replaces the player when a reused embed id navigates to a different URL (no lingering video)', () => {
        const r = relay.createRelay({ whitelist: HOSTS });
        // Page 1: YouTube reported at id exe-embed-1.
        r.onMessage({
            source: iframe.contentWindow,
            data: {
                type: 'exe-embed',
                action: 'sync',
                embeds: [{ id: 'exe-embed-1', url: 'https://www.youtube.com/embed/abc123', x: 0, y: 0, w: 480, h: 270 }],
            },
        });
        expect(document.querySelector('.exe-embed-overlay iframe').src).toMatch(/youtube-nocookie\.com\/embed\/abc123$/);

        // Page 2: the in-iframe shim restarts its counter, so the Vimeo embed REUSES
        // id exe-embed-1. The relay must swap the player, not just reposition it.
        r.onMessage({
            source: iframe.contentWindow,
            data: {
                type: 'exe-embed',
                action: 'sync',
                embeds: [{ id: 'exe-embed-1', url: 'https://player.vimeo.com/video/12345', x: 0, y: 0, w: 425, h: 350 }],
            },
        });
        const players = document.querySelectorAll('.exe-embed-overlay iframe');
        expect(players.length).toBe(1);
        expect(players[0].src).toMatch(/player\.vimeo\.com\/video\/12345$/);
        expect(players[0].src).not.toMatch(/youtube/);   // The previous page's video must be gone.
    });

    it('ignores a message whose source is not a known content iframe', () => {
        const r = relay.createRelay({ whitelist: HOSTS });
        r.onMessage({
            source: {},
            data: {
                type: 'exe-embed',
                action: 'sync',
                embeds: [{ id: 'x', url: 'https://www.youtube.com/embed/abc123', x: 0, y: 0, w: 1, h: 1 }],
            },
        });
        expect(document.querySelectorAll('.exe-embed-overlay iframe').length).toBe(0);
    });

    it('ignores non-embed messages', () => {
        const r = relay.createRelay({ whitelist: HOSTS });
        r.onMessage({ source: iframe.contentWindow, data: { type: 'scorm', action: 'track', cmi: {} } });
        expect(document.querySelectorAll('.exe-embed-overlay iframe').length).toBe(0);
    });
});

describe('exe_embed_shim collect() geometry report', () => {
    it('reports id + (absolute) url + numeric geometry for each placeholder', () => {
        const root = document.createElement('div');
        root.innerHTML =
            '<iframe src="https://www.youtube-nocookie.com/embed/aqz-KE-bpKQ" width="560" height="315"></iframe>';
        shim.promote(root, HOSTS, { n: 0 });

        const embeds = shim.collect(root);
        expect(embeds.length).toBe(1);
        expect(embeds[0].id).toMatch(/^exe-embed-/);
        expect(embeds[0].url).toContain('youtube-nocookie.com');
        ['x', 'y', 'w', 'h'].forEach((k) => expect(typeof embeds[0][k]).toBe('number'));
    });
});
