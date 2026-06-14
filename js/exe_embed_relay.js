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

/**
 * Parent-side external-embed relay for the secure (opaque-origin) package mode.
 *
 * Companion to js/exe_embed_shim.js (baked into the package, runs INSIDE the iframe).
 * In secure mode the package is opaque, so cross-origin players (YouTube, Vimeo) and
 * PDFs render blank. The shim replaces each whitelisted/PDF iframe with a placeholder
 * and postMessages its geometry here; this relay (the trusted half) validates each
 * URL, rebuilds the canonical player URL, and overlays the real player inline over the
 * placeholder. The player is cross-origin (or a package PDF served as application/pdf)
 * and cannot script this page; the whitelist + strict validation stop the untrusted
 * content from making the host load an arbitrary URL. Messages are authenticated by
 * window identity (event.source === iframe.contentWindow); the opaque origin has no
 * useful event.origin.
 *
 * Exposed two ways from a single body: window.exeEmbedRelay (browser bootstrap) and
 * module.exports (Vitest). See research ADR DEC-0059.
 */
(function () {
    'use strict';

    /**
     * Build a host lookup map from a whitelist array (lowercased).
     *
     * @param {string[]} list
     * @returns {Object}
     */
    function buildWhitelist(list) {
        var map = {};
        (list || []).forEach(function (host) {
            map[String(host).toLowerCase()] = true;
        });
        return map;
    }

    /**
     * Directory portion of the content iframe src (everything up to the last '/').
     *
     * @param {string} src
     * @returns {string}
     */
    function contentDir(src) {
        try {
            return new URL(src, window.location.href).href.replace(/[^/]*([?#].*)?$/, '');
        } catch (e) {
            return '';
        }
    }

    /**
     * Long hex token shared by the content URL and its assets (null when there is
     * none, e.g. content URLs that use numeric ids).
     *
     * @param {string} src
     * @returns {?string}
     */
    function packageId(src) {
        var match = String(src).match(/[a-f0-9]{12,}/i);
        return match ? match[0] : null;
    }

    /**
     * Whether a same-origin URL is one of this package's own extracted files: under
     * the content's own directory, or carrying the package hash as a path segment.
     *
     * @param {URL} url
     * @param {string} contentSrc
     * @returns {boolean}
     */
    function isSameOriginPackageFile(url, contentSrc) {
        var dir = contentDir(contentSrc);
        if (dir && url.href.indexOf(dir) === 0) {
            return true;
        }
        var id = packageId(contentSrc);
        return !!(id && url.pathname.indexOf('/' + id + '/') !== -1);
    }

    /**
     * Validate an embed URL. Returns {url, kind} ('video'|'pdf') or null.
     *
     * @param {string} raw         The reported embed URL.
     * @param {string} contentSrc  The src of the content iframe that reported it.
     * @param {Object} whitelist   Host lookup map from buildWhitelist().
     * @returns {?Object}
     */
    function validate(raw, contentSrc, whitelist) {
        var url;
        try {
            url = new URL(raw, window.location.href);
        } catch (e) {
            return null;
        }
        if (String(raw).indexOf('@') !== -1) {
            return null; // Reject userinfo, e.g. evil.com@youtube.com.
        }
        var host = url.hostname.toLowerCase();

        if (whitelist[host] && url.protocol === 'https:') {
            var match;
            if (host.indexOf('youtube') !== -1) {
                match = url.pathname.match(/^\/embed\/([A-Za-z0-9_-]{6,})$/);
                return match ? { url: 'https://www.youtube-nocookie.com/embed/' + match[1], kind: 'video' } : null;
            }
            if (host.indexOf('vimeo') !== -1) {
                match = url.pathname.match(/^\/video\/([0-9]+)$/);
                return match ? { url: 'https://player.vimeo.com/video/' + match[1], kind: 'video' } : null;
            }
        }

        if (/\.pdf$/i.test(url.pathname)) {
            if (url.origin === window.location.origin) {
                // Same-origin: only this package's own files (served as application/pdf,
                // never executable HTML), so author-supplied absolute URLs are rejected.
                return isSameOriginPackageFile(url, contentSrc) ? { url: url.href, kind: 'pdf' } : null;
            }
            return url.protocol === 'https:' ? { url: url.href, kind: 'pdf' } : null;
        }

        return null;
    }

    /**
     * Create a player iframe for a validated embed.
     *
     * @param {Object} result {url, kind} from validate().
     * @returns {HTMLIFrameElement}
     */
    function makePlayer(result) {
        var frame = document.createElement('iframe');
        frame.style.cssText = 'position:absolute;border:0;pointer-events:auto;';
        if (result.kind === 'video') {
            frame.setAttribute('allow', 'autoplay; encrypted-media; fullscreen; picture-in-picture; clipboard-write');
            frame.setAttribute('allowfullscreen', '');
            frame.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
        } else {
            frame.setAttribute('allow', 'fullscreen');
            frame.setAttribute('referrerpolicy', 'no-referrer');
        }
        frame.src = result.url;
        return frame;
    }

    /**
     * Create a relay instance bound to a whitelist.
     *
     * @param {Object} config {whitelist: string[]}
     * @returns {Object}
     */
    function createRelay(config) {
        var whitelist = buildWhitelist((config || {}).whitelist);
        var overlays = [];

        function findOverlay(iframe) {
            for (var i = 0; i < overlays.length; i++) {
                if (overlays[i].iframe === iframe) {
                    return overlays[i];
                }
            }
            return null;
        }

        function frameForSource(source) {
            var frames = document.getElementsByTagName('iframe');
            for (var i = 0; i < frames.length; i++) {
                if (frames[i].contentWindow === source) {
                    return frames[i];
                }
            }
            return null;
        }

        function overlayFor(iframe) {
            var entry = findOverlay(iframe);
            if (entry) {
                return entry;
            }
            var el = document.createElement('div');
            el.className = 'exe-embed-overlay';
            el.style.cssText = 'position:absolute;overflow:hidden;pointer-events:none;z-index:2147483646;';
            document.body.appendChild(el);
            entry = { iframe: iframe, el: el, players: {} };
            overlays.push(entry);
            return entry;
        }

        function positionOverlay(entry) {
            var rect = entry.iframe.getBoundingClientRect();
            var scrollX = window.pageXOffset || document.documentElement.scrollLeft || 0;
            var scrollY = window.pageYOffset || document.documentElement.scrollTop || 0;
            entry.el.style.left = (rect.left + scrollX) + 'px';
            entry.el.style.top = (rect.top + scrollY) + 'px';
            entry.el.style.width = rect.width + 'px';
            entry.el.style.height = rect.height + 'px';
        }

        function sync(entry, embeds, contentSrc) {
            positionOverlay(entry);
            var seen = {};
            embeds.forEach(function (embed) {
                if (!embed || typeof embed.id !== 'string') {
                    return;
                }
                if (!isFinite(embed.x) || !isFinite(embed.y) || !isFinite(embed.w) || !isFinite(embed.h)) {
                    return;
                }
                var result = validate(embed.url, contentSrc, whitelist);
                if (!result) {
                    return;
                }
                seen[embed.id] = true;
                var player = entry.players[embed.id];
                if (!player) {
                    player = makePlayer(result);
                    entry.el.appendChild(player);
                    entry.players[embed.id] = player;
                }
                player.style.left = embed.x + 'px';
                player.style.top = embed.y + 'px';
                player.style.width = embed.w + 'px';
                player.style.height = embed.h + 'px';
            });
            Object.keys(entry.players).forEach(function (id) {
                if (!seen[id]) {
                    entry.players[id].parentNode.removeChild(entry.players[id]);
                    delete entry.players[id];
                }
            });
        }

        function onMessage(event) {
            var data = event.data;
            if (!data || data.type !== 'exe-embed' || data.action !== 'sync' || !Array.isArray(data.embeds)) {
                return;
            }
            var iframe = frameForSource(event.source);
            if (!iframe) {
                return;
            }
            sync(overlayFor(iframe), data.embeds, iframe.src);
        }

        // Browser-only glue below (window listeners, reflow on scroll/resize, pinging
        // the content iframes). Exercised by the Playwright/Firefox e2e
        // (tests/e2e/embed.spec.cjs), not the happy-dom unit tests.
        /* v8 ignore start */
        function pingAll() {
            var frames = document.getElementsByTagName('iframe');
            for (var i = 0; i < frames.length; i++) {
                try {
                    frames[i].contentWindow.postMessage({ type: 'exe-embed', action: 'request' }, '*');
                } catch (e) {
                    // Cross-origin player iframes reject this; harmless.
                }
            }
        }

        var scheduled = false;
        function scheduleReflow() {
            if (scheduled) {
                return;
            }
            scheduled = true;
            window.requestAnimationFrame(function () {
                scheduled = false;
                for (var i = 0; i < overlays.length; i++) {
                    positionOverlay(overlays[i]);
                }
            });
        }
        /* v8 ignore stop */

        return {
            onMessage: onMessage,
            sync: sync,
            validate: function (raw, contentSrc) {
                return validate(raw, contentSrc, whitelist);
            },
            /* v8 ignore start */
            init: function () {
                window.addEventListener('message', onMessage);
                window.addEventListener('resize', scheduleReflow);
                window.addEventListener('scroll', scheduleReflow, true);
                window.addEventListener('load', pingAll);
                pingAll();
                window.setTimeout(pingAll, 500);
                return this;
            }
            /* v8 ignore stop */
        };
    }

    /**
     * Bootstrap: create a relay from config and start listening.
     *
     * @param {Object} config {whitelist: string[]}
     * @returns {Object}
     */
    /* v8 ignore next 3 */
    function init(config) {
        return createRelay(config).init();
    }

    var exp = {
        buildWhitelist: buildWhitelist,
        contentDir: contentDir,
        packageId: packageId,
        isSameOriginPackageFile: isSameOriginPackageFile,
        validate: validate,
        makePlayer: makePlayer,
        createRelay: createRelay,
        init: init
    };
    // Test runner (Vitest/Node) consumes module.exports.
    if (typeof module !== 'undefined' && module.exports) { module.exports = exp; }
    // Browser bootstrap (view.php) consumes window.exeEmbedRelay.
    if (typeof window !== 'undefined') { window.exeEmbedRelay = exp; }
})();
