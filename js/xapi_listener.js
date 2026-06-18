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
 * xAPI statement listener for the eXeLearning player (DEC-0064, single source of truth).
 *
 * For packages that bundle the upstream xAPI emitter (eXeLearning PR #1867), grading
 * flows through xAPI instead of the SCORM bridge: the emitter posts
 * `{type:'exe-xapi-statement', statement}` to the host via postMessage; this listener,
 * running in the parent page (view.php), validates the sender origin, de-duplicates by
 * statement.id and forwards each statement to xapi_track.php.
 *
 * Like js/scorm_tracker.js this is grade-critical client code, so it is a plain inline
 * script (NOT an AMD module): view.php reads this file and injects it inline, and the
 * same file is unit-tested with Vitest (tests/js/xapi_listener.test.js). It is exposed
 * two ways from a single body: window.exeXapiListener for the browser bootstrap, and
 * module.exports for the test runner.
 *
 * Security (RIE-013): the only trusted transport is a postMessage whose event.origin
 * equals the host origin (the iframe is served same-origin via pluginfile.php). A '*'
 * or mismatched origin is dropped. The statement actor is never read here — the server
 * attributes the grade to the authenticated user.
 */
(function () {
    'use strict';

    /** @const {string} The envelope type the eXeLearning emitter uses. */
    var MESSAGE_TYPE = 'exe-xapi-statement';

    /**
     * Whether a postMessage payload is an eXeLearning xAPI statement envelope.
     *
     * @param {*} data The event.data value.
     * @returns {boolean}
     */
    function isStatementMessage(data) {
        return !!data && typeof data === 'object' && data.type === MESSAGE_TYPE
            && !!data.statement && typeof data.statement === 'object';
    }

    /**
     * Whether a message origin is the trusted host origin. Rejects '' and the '*'
     * wildcard (defense in depth even though the emitter strips PII when broadcasting
     * to '*'); only an exact match to the expected host origin is accepted (RIE-013).
     *
     * @param {string} origin  event.origin of the message.
     * @param {string} allowed The expected host origin.
     * @returns {boolean}
     */
    function isTrustedOrigin(origin, allowed) {
        return typeof origin === 'string' && origin !== '' && origin !== '*'
            && typeof allowed === 'string' && allowed !== '' && origin === allowed;
    }

    /**
     * Serialize the xapi_track.php POST body.
     *
     * @param {number} cmid         Course module id.
     * @param {Object} statement    The xAPI statement to forward.
     * @param {string} registration Attempt-grouping token (the page-load token).
     * @param {string} mode         grading|preview.
     * @returns {string} JSON payload.
     */
    function buildPayload(cmid, statement, registration, mode) {
        return JSON.stringify({ id: cmid, statement: statement, registration: registration, mode: mode });
    }

    /**
     * Build the message listener and its supporting state.
     *
     * Dependencies are injectable so the origin/dedup/forward behaviour can be
     * unit-tested without a real window or XHR:
     *   - cmid, trackurl, registration, mode: identity and endpoint.
     *   - allowedOrigin: the trusted host origin (defaults to window.location.origin).
     *   - xhrFactory(): returns an XMLHttpRequest-like object (default: real XHR).
     *
     * @param {Object} config
     * @returns {{handleMessage: Function, send: Function, start: Function}}
     */
    function createListener(config) {
        config = config || {};
        var cmid = config.cmid;
        var trackurl = config.trackurl;
        var registration = config.registration || '';
        var mode = config.mode || 'grading';
        var allowed = config.allowedOrigin
            || ((typeof window !== 'undefined' && window.location) ? window.location.origin : '');
        var xhrFactory = config.xhrFactory || function () { return new XMLHttpRequest(); };
        var seen = {};   // De-dup set keyed by statement.id within this page session.

        // Forward one statement to the server (always async: the grade-bearing
        // 'answered'/package statements arrive during interaction, never at unload).
        function send(statement) {
            try {
                var xhr = xhrFactory();
                xhr.open('POST', trackurl, true);
                xhr.setRequestHeader('Content-Type', 'application/json');
                xhr.send(buildPayload(cmid, statement, registration, mode));
                return true;
            } catch (e) {
                // Never let tracking break the activity.
                return false;
            }
        }

        // Validate, de-dup and forward a single message. Returns true when forwarded.
        function handleMessage(event) {
            if (!event || !isTrustedOrigin(event.origin, allowed)) { return false; }
            if (!isStatementMessage(event.data)) { return false; }
            var statement = event.data.statement;
            var id = statement.id;
            if (id) {
                if (seen[id]) { return false; }
                seen[id] = true;
            }
            return send(statement);
        }

        function start() {
            if (typeof window !== 'undefined' && window.addEventListener) {
                window.addEventListener('message', handleMessage);
            }
        }

        return { handleMessage: handleMessage, send: send, start: start };
    }

    var exp = {
        isStatementMessage: isStatementMessage,
        isTrustedOrigin: isTrustedOrigin,
        buildPayload: buildPayload,
        createListener: createListener
    };
    // Test runner (Vitest/Node) consumes module.exports; the guard keeps a browser
    // <script> from throwing on the undefined `module`.
    if (typeof module !== 'undefined' && module.exports) { module.exports = exp; }
    // Browser bootstrap (view.php) consumes window.exeXapiListener.
    if (typeof window !== 'undefined') { window.exeXapiListener = exp; }
})();
