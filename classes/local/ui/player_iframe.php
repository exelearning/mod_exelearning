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
}
