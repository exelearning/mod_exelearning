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
});
