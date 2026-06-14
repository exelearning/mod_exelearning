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

/**
 * Package iframe security mode and sandbox policy (DEC-0059).
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_exelearning\local\ui;

/**
 * Resolves the configured package iframe mode and the sandbox tokens it implies.
 *
 * A single site-wide admin setting (mod_exelearning/iframemode) selects how the
 * arbitrary author HTML/JS of an `.elpx` package is embedded in view.php:
 *
 *  - secure (default): the iframe drops allow-same-origin, so the package runs in
 *    an opaque origin and cannot read or modify Moodle's DOM, cookies or session.
 *    SCORM scoring is relayed to the parent over a validated postMessage bridge
 *    (js/scorm_bridge_shim.js in the iframe, js/scorm_bridge_relay.js in the
 *    parent), and the parent keeps the sesskey and performs the track.php request.
 *  - legacy: the historical same-origin sandbox, kept only as a compatibility
 *    fallback for packages that misbehave under an opaque origin.
 *
 * Centralised here so the token policy is unit-testable without rendering view.php.
 * See research ADR DEC-0059 (advances the Tier 2 roadmap of DEC-0019).
 */
final class player_iframe {
    /** @var string Secure mode: opaque-origin iframe + postMessage SCORM bridge. */
    public const MODE_SECURE = 'secure';

    /** @var string Legacy mode: historical same-origin iframe. */
    public const MODE_LEGACY = 'legacy';

    /**
     * Default host whitelist for external video embeds promoted to the parent page.
     *
     * In secure mode the package is opaque, so cross-origin players (YouTube/Vimeo)
     * load blank. Iframes whose src host is on this list are replaced by a placeholder
     * in the package (js/exe_embed_shim.js) and rendered as a real player by the parent
     * (js/exe_embed_relay.js). PDFs are handled separately (by .pdf extension) and need
     * no host entry.
     *
     * @var string[]
     */
    public const DEFAULT_EMBED_HOSTS = [
        'www.youtube.com',
        'youtube.com',
        'www.youtube-nocookie.com',
        'youtube-nocookie.com',
        'player.vimeo.com',
        'vimeo.com',
        'www.dailymotion.com',
        'dailymotion.com',
        'geo.dailymotion.com',
        'mediateca.educa.madrid.org',
    ];

    /**
     * Resolve the configured iframe mode, defaulting to secure for any unset or
     * unrecognised value (fail safe: an invalid config must not weaken isolation).
     *
     * @return string self::MODE_SECURE or self::MODE_LEGACY.
     */
    public static function resolve_mode(): string {
        $mode = get_config('mod_exelearning', 'iframemode');
        return ($mode === self::MODE_LEGACY) ? self::MODE_LEGACY : self::MODE_SECURE;
    }

    /**
     * Whether the configured mode isolates the package (opaque origin + bridge).
     *
     * @return bool True in secure mode.
     */
    public static function is_secure(): bool {
        return self::resolve_mode() === self::MODE_SECURE;
    }

    /**
     * Sandbox token list for a given mode.
     *
     * Both modes deliberately OMIT allow-top-navigation (a package must never be
     * able to change the parent URL) and allow-modals (alert/confirm/prompt are UX
     * traps). Secure mode additionally OMITS allow-same-origin (forcing an opaque
     * origin so the package cannot reach Moodle's DOM/cookies/session) and
     * allow-popups-to-escape-sandbox (an escaped popup would reopen at Moodle's real
     * origin without the sandbox). allow-scripts/allow-popups/allow-forms are kept
     * in both modes because eXeLearning v4 iDevices need jQuery + scripts, popups
     * (interactive-video, hidden-image) and forms (quick-questions, form,
     * scrambled-list). See research ADR DEC-0059 / DEC-0019 / AN-008.
     *
     * @param string $mode self::MODE_SECURE or self::MODE_LEGACY.
     * @return string Space-separated sandbox token list.
     */
    public static function sandbox_tokens(string $mode): string {
        if ($mode === self::MODE_LEGACY) {
            return 'allow-scripts allow-same-origin allow-popups allow-forms allow-popups-to-escape-sandbox';
        }
        return 'allow-scripts allow-popups allow-forms';
    }

    /**
     * Normalized host whitelist for external video embeds (lowercase, de-duplicated).
     *
     * @return string[]
     */
    public static function embed_whitelist(): array {
        $clean = [];
        foreach (self::DEFAULT_EMBED_HOSTS as $host) {
            $host = strtolower(trim((string) $host));
            if ($host !== '') {
                $clean[$host] = true;
            }
        }
        return array_keys($clean);
    }

    /**
     * Permissions-Policy header value for the embedded package (DEC-0060).
     *
     * Denies hardware/sensor features the package never needs. `fullscreen` is
     * intentionally NOT denied: the iframe grants it via its allow= attribute and
     * iDevices use it. Emitted by exelearning_pluginfile() in secure mode.
     *
     * @return string The Permissions-Policy header value.
     */
    public static function permissions_policy(): string {
        return 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), serial=(), '
            . 'bluetooth=(), hid=(), magnetometer=(), accelerometer=(), gyroscope=(), '
            . 'midi=(), display-capture=()';
    }

    /**
     * Content-Security-Policy header value for the embedded package (DEC-0060).
     *
     * Tuned to harden without breaking eXeLearning, which relies on inline and eval'd
     * scripts: object-src and base-uri are closed, framing is restricted to Moodle
     * (frame-ancestors 'self'), and connect-src is limited to this site so the file
     * token carried in the URL cannot be exfiltrated to a third-party host via
     * fetch/XHR/beacon. External script/style/img/media/frame over https: is still
     * allowed so MathJax, YouTube and author embeds keep working (a stricter,
     * exfil-proof profile that also blocks those is left as a future admin toggle).
     *
     * @param string $siteorigin The scheme://host[:port] origin of this Moodle site.
     * @return string The Content-Security-Policy header value.
     */
    public static function content_security_policy(string $siteorigin): string {
        return "default-src 'self' $siteorigin; "
            . "script-src 'self' $siteorigin 'unsafe-inline' 'unsafe-eval' https:; "
            . "style-src 'self' $siteorigin 'unsafe-inline'; "
            . "img-src 'self' $siteorigin data: blob: https:; "
            . "media-src 'self' $siteorigin data: blob: https:; "
            . "font-src 'self' $siteorigin data:; "
            . "connect-src 'self' $siteorigin; "
            . "frame-src 'self' $siteorigin https:; "
            . "object-src 'none'; base-uri 'none'; form-action 'self' $siteorigin; "
            . "frame-ancestors 'self'; "
            // Keep the document opaque even if opened outside the iframe (e.g. the token
            // URL opened in a new tab); tokens mirror the secure iframe sandbox.
            . "sandbox allow-scripts allow-popups allow-forms";
    }

    /**
     * Defense-in-depth response headers for a served package file (DEC-0060).
     *
     * Returns the Permissions-Policy + Content-Security-Policy to emit for the package
     * HTML document, but ONLY in secure mode and ONLY for an HTML document (subresources
     * ignore these headers). Returns an empty array otherwise, so the caller
     * (exelearning_pluginfile) is just a header-emitting loop. Keeping the decision and
     * the values here makes them unit-testable (the pluginfile callback that emits them
     * exits via send_stored_file and cannot be unit-tested directly).
     *
     * @param string $filename The served file name (only *.html(?) get the headers).
     * @param string $wwwroot This Moodle site's $CFG->wwwroot (origin is derived from it).
     * @return array Map of header name => value (empty when no headers apply).
     */
    public static function content_headers(string $filename, string $wwwroot): array {
        if (!self::is_secure() || !preg_match('~\.html?$~i', $filename)) {
            return [];
        }
        $siteorigin = preg_replace('~^(https?://[^/]+).*~i', '$1', $wwwroot);
        return [
            'Permissions-Policy' => self::permissions_policy(),
            'Content-Security-Policy' => self::content_security_policy($siteorigin),
        ];
    }
}
