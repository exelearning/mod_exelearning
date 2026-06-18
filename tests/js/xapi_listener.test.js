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

// Side-effect import: the module exposes its API on window.exeXapiListener (and on
// module.exports). globals (describe/it/expect) are enabled in vitest.config.mts.
import '../../js/xapi_listener.js';

const { isStatementMessage, isTrustedOrigin, buildPayload, createListener } =
    window.exeXapiListener;

const HOST = 'https://moodle.example';

/**
 * Minimal XMLHttpRequest-like stub recording every open()/send() call.
 *
 * @returns {{open: Function, setRequestHeader: Function, send: Function, calls: Array}}
 */
function makeXhr() {
    const calls = [];
    return {
        calls,
        open(method, url, async) { calls.push({ method, url, async }); },
        setRequestHeader() {},
        send(body) { calls.push({ body }); },
    };
}

/**
 * Build a message event with the trusted host origin unless overridden.
 *
 * @param {Object} statement The statement to wrap, or a raw data override.
 * @param {Object} [opts] {origin, type, raw}
 * @returns {{origin: string, data: *}}
 */
function messageEvent(statement, opts = {}) {
    const origin = opts.origin !== undefined ? opts.origin : HOST;
    if (opts.raw !== undefined) { return { origin, data: opts.raw }; }
    return { origin, data: { type: opts.type || 'exe-xapi-statement', statement } };
}

function answered(id) {
    return { id, verb: { id: 'http://adlnet.gov/expapi/verbs/answered' }, result: { score: { scaled: 0.7 } } };
}

describe('xapi_listener pure helpers', () => {
    it('accepts only the exe-xapi-statement envelope', () => {
        expect(isStatementMessage({ type: 'exe-xapi-statement', statement: {} })).toBe(true);
        expect(isStatementMessage({ type: 'other', statement: {} })).toBe(false);
        expect(isStatementMessage({ type: 'exe-xapi-statement' })).toBe(false);
        expect(isStatementMessage(null)).toBe(false);
        expect(isStatementMessage('exe-xapi-statement')).toBe(false);
    });

    it('trusts only an exact host origin, never "*" or empty', () => {
        expect(isTrustedOrigin(HOST, HOST)).toBe(true);
        expect(isTrustedOrigin('https://evil.example', HOST)).toBe(false);
        expect(isTrustedOrigin('*', HOST)).toBe(false);
        expect(isTrustedOrigin('', HOST)).toBe(false);
        expect(isTrustedOrigin(HOST, '')).toBe(false);
    });

    it('builds the POST body with id/statement/registration/mode', () => {
        const body = JSON.parse(buildPayload(42, answered('a'), 'tok', 'grading'));
        expect(body.id).toBe(42);
        expect(body.statement.id).toBe('a');
        expect(body.registration).toBe('tok');
        expect(body.mode).toBe('grading');
    });
});

describe('xapi_listener createListener().handleMessage', () => {
    let xhr;
    let listener;

    beforeEach(() => {
        xhr = makeXhr();
        listener = createListener({
            cmid: 42,
            trackurl: '/mod/exelearning/xapi_track.php?id=42&sesskey=abc',
            registration: 'tok',
            mode: 'grading',
            allowedOrigin: HOST,
            xhrFactory: () => xhr,
        });
    });

    it('forwards a valid same-origin statement to xapi_track.php', () => {
        expect(listener.handleMessage(messageEvent(answered('s1')))).toBe(true);
        const open = xhr.calls.find((c) => c.method);
        const send = xhr.calls.find((c) => c.body);
        expect(open.method).toBe('POST');
        expect(open.url).toContain('xapi_track.php');
        expect(JSON.parse(send.body).statement.id).toBe('s1');
    });

    it('drops a statement from a mismatched origin', () => {
        expect(listener.handleMessage(messageEvent(answered('s1'), { origin: 'https://evil.example' }))).toBe(false);
        expect(xhr.calls.length).toBe(0);
    });

    it('drops a statement broadcast to "*" origin', () => {
        expect(listener.handleMessage(messageEvent(answered('s1'), { origin: '*' }))).toBe(false);
        expect(xhr.calls.length).toBe(0);
    });

    it('ignores a non-statement message', () => {
        expect(listener.handleMessage(messageEvent(null, { raw: { type: 'something-else' } }))).toBe(false);
        expect(xhr.calls.length).toBe(0);
    });

    it('de-duplicates by statement.id within the page', () => {
        expect(listener.handleMessage(messageEvent(answered('dup')))).toBe(true);
        expect(listener.handleMessage(messageEvent(answered('dup')))).toBe(false);
        // Exactly one open + one send for the first delivery.
        expect(xhr.calls.filter((c) => c.method).length).toBe(1);
    });
});
