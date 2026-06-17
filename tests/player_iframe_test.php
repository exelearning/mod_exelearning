<?php
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

namespace mod_exelearning;

use advanced_testcase;
use mod_exelearning\local\ui\player_iframe;

/**
 * Tests for the package iframe security mode + sandbox policy (DEC-0059).
 *
 * @package    mod_exelearning
 * @category   test
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_exelearning\local\ui\player_iframe
 */
final class player_iframe_test extends advanced_testcase {
    /**
     * The default (no config set) must be the secure, isolated mode.
     */
    public function test_default_mode_is_secure(): void {
        $this->resetAfterTest();
        $this->assertSame(player_iframe::MODE_SECURE, player_iframe::resolve_mode());
        $this->assertTrue(player_iframe::is_secure());
    }

    /**
     * An unset/invalid config must fail safe to secure, never weakening isolation.
     */
    public function test_invalid_mode_falls_back_to_secure(): void {
        $this->resetAfterTest();
        set_config('iframemode', 'not-a-real-mode', 'mod_exelearning');
        $this->assertSame(player_iframe::MODE_SECURE, player_iframe::resolve_mode());
        $this->assertTrue(player_iframe::is_secure());
    }

    /**
     * The legacy fallback is honoured when explicitly configured.
     */
    public function test_legacy_mode_is_respected(): void {
        $this->resetAfterTest();
        set_config('iframemode', player_iframe::MODE_LEGACY, 'mod_exelearning');
        $this->assertSame(player_iframe::MODE_LEGACY, player_iframe::resolve_mode());
        $this->assertFalse(player_iframe::is_secure());
    }

    /**
     * Secure mode runs in an opaque origin: it MUST drop allow-same-origin and
     * allow-popups-to-escape-sandbox, and MUST keep the scripts/popups/forms the
     * iDevices need. It must never grant top navigation or modals.
     */
    public function test_secure_sandbox_tokens(): void {
        $tokens = player_iframe::sandbox_tokens(player_iframe::MODE_SECURE);
        $list = explode(' ', $tokens);

        $this->assertContains('allow-scripts', $list);
        $this->assertContains('allow-popups', $list);
        $this->assertContains('allow-forms', $list);

        $this->assertNotContains('allow-same-origin', $list);
        $this->assertNotContains('allow-popups-to-escape-sandbox', $list);
        $this->assertNotContains('allow-top-navigation', $list);
        $this->assertNotContains('allow-top-navigation-by-user-activation', $list);
        $this->assertNotContains('allow-modals', $list);
    }

    /**
     * Legacy mode keeps the historical same-origin tokens (incl. allow-same-origin
     * and allow-popups-to-escape-sandbox) but still never grants top navigation or
     * modals.
     */
    public function test_legacy_sandbox_tokens(): void {
        $tokens = player_iframe::sandbox_tokens(player_iframe::MODE_LEGACY);
        $list = explode(' ', $tokens);

        $this->assertContains('allow-scripts', $list);
        $this->assertContains('allow-same-origin', $list);
        $this->assertContains('allow-popups', $list);
        $this->assertContains('allow-forms', $list);
        $this->assertContains('allow-popups-to-escape-sandbox', $list);

        $this->assertNotContains('allow-top-navigation', $list);
        $this->assertNotContains('allow-modals', $list);
    }

    /**
     * Permissions-Policy denies sensors/hardware but never fullscreen (the iframe
     * grants it and iDevices use it).
     */
    public function test_permissions_policy(): void {
        $pp = player_iframe::permissions_policy();
        $this->assertStringContainsString('camera=()', $pp);
        $this->assertStringContainsString('microphone=()', $pp);
        $this->assertStringContainsString('geolocation=()', $pp);
        $this->assertStringNotContainsString('fullscreen', $pp);
    }

    /**
     * The CSP hardens object/base/framing and pins connect-src to this site (so the
     * file token cannot be fetch-exfiltrated), while keeping the inline/eval scripts
     * eXeLearning needs. The passed site origin must appear in the source lists.
     */
    public function test_content_security_policy(): void {
        $origin = 'https://moodle.example.net';
        $csp = player_iframe::content_security_policy($origin);

        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("base-uri 'none'", $csp);
        $this->assertStringContainsString("frame-ancestors 'self'", $csp);
        // A sandbox directive keeps the document opaque even when opened outside the
        // iframe (e.g. the token URL opened in a new tab), so author JS cannot run as
        // Moodle's origin. Tokens mirror the secure iframe sandbox.
        $this->assertStringContainsString('sandbox allow-scripts allow-popups allow-forms', $csp);
        $this->assertStringContainsString("connect-src 'self' $origin;", $csp);
        // Inline + eval'd scripts are required by the eXeLearning engine.
        $this->assertStringContainsString("'unsafe-inline'", $csp);
        $this->assertStringContainsString("'unsafe-eval'", $csp);
        // The connect-src must NOT open to the bare `https:` wildcard (any host) — that
        // would let the file token be exfiltrated. The explicit site origin
        // (https://host) is fine; the negative lookahead excludes `https://...`.
        $this->assertDoesNotMatchRegularExpression('~connect-src[^;]*\bhttps:(?!//)~', $csp);
    }

    /**
     * content_headers() emits Referrer-Policy + nosniff on every secure-mode file and adds
     * the document-level CSP + Permissions-Policy for an HTML document, deriving the CSP
     * origin from $CFG->wwwroot (path stripped). Legacy mode emits nothing.
     */
    public function test_content_headers(): void {
        $this->resetAfterTest();

        // Secure (default) + HTML document: all four headers, origin stripped from wwwroot.
        $headers = player_iframe::content_headers('index.html', 'https://moodle.example.net/sub');
        $this->assertArrayHasKey('Content-Security-Policy', $headers);
        $this->assertArrayHasKey('Permissions-Policy', $headers);
        $this->assertSame('no-referrer', $headers['Referrer-Policy']);
        $this->assertSame('nosniff', $headers['X-Content-Type-Options']);
        $this->assertStringContainsString("'self' https://moodle.example.net;", $headers['Content-Security-Policy']);
        $this->assertStringNotContainsString('/sub', $headers['Content-Security-Policy']);

        // Secure + non-HTML subresource: the per-file token-protection headers still apply
        // (the token rides in the URL of CSS/JS too), but not the document-level CSP.
        $sub = player_iframe::content_headers('libs/base.css', 'https://moodle.example.net');
        $this->assertSame('no-referrer', $sub['Referrer-Policy']);
        $this->assertSame('nosniff', $sub['X-Content-Type-Options']);
        $this->assertArrayNotHasKey('Content-Security-Policy', $sub);
        $this->assertArrayNotHasKey('Permissions-Policy', $sub);

        // Legacy mode: no headers regardless of file type.
        set_config('iframemode', player_iframe::MODE_LEGACY, 'mod_exelearning');
        $this->assertSame([], player_iframe::content_headers('index.html', 'https://moodle.example.net'));
    }
}
