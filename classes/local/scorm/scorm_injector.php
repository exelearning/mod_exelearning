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
 * SCORM wrapper loader injection for extracted eXeLearning packages.
 *
 * Extracted verbatim from lib.php (DEC-0054). The HTML mutation logic is
 * unchanged; lib.php keeps a thin delegator. See the known-debt note in
 * docs/ARCHITECTURE.md and DEC-0045/DEC-0046 for the planned exit.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_exelearning\local\scorm;

/**
 * Injects the SCORM wrapper script tags into the package HTML at extraction time.
 */
final class scorm_injector {
    /**
     * Injects the SCORM client script tags into the <head> of index.html and all
     * html/<slug>.html pages of the extracted package.
     *
     * Two independent, idempotent blocks are injected:
     *  - The bridge client (libs/scorm_tracker.js + libs/exe_scorm_bridge.js) at the
     *    TOP of <head>, so its in-memory storage polyfill and local window.API are in
     *    place before any package script runs. It self-activates only in the secure
     *    opaque-origin iframe mode and is dormant otherwise (DEC-0059).
     *  - The pipwerks SCORM wrapper (libs/SCORM_API_wrapper.js + libs/SCOFunctions.js)
     *    plus an init kick, just before </head>, used by both iframe modes.
     *
     * @param int $contextid
     * @param int $revision
     */
    public static function inject(int $contextid, int $revision): void {
        $fs = get_file_storage();
        $marker = '<!-- mod_exelearning:scorm-loader -->';
        $bridgemarker = '<!-- mod_exelearning:scorm-bridge -->';
        // After loading the wrapper, force `pipwerks.SCORM.init()` so that
        // connection.isActive=true and subsequent `set()` calls DO reach
        // window.parent.API.LMSSetValue. eXeLearning only invokes init() in the
        // on-click flow; with isScorm==1 (auto-save after each question) it never
        // gets called, so we trigger it here.
        $initscript = "\n    <script>\n" .
                "      (function(){\n" .
                "        var t = setInterval(function(){\n" .
                "          if (window.pipwerks && window.pipwerks.SCORM) {\n" .
                "            clearInterval(t);\n" .
                "            try { window.pipwerks.SCORM.init(); } catch(e){}\n" .
                "          }\n" .
                "        }, 50);\n" .
                "      })();\n" .
                "    </script>\n";
        $tags = $marker .
                "\n    <script src=\"libs/SCORM_API_wrapper.js\"></script>" .
                "\n    <script src=\"libs/SCOFunctions.js\"></script>" .
                $initscript;
        $tagshtml = $marker .
                "\n    <script src=\"../libs/SCORM_API_wrapper.js\"></script>" .
                "\n    <script src=\"../libs/SCOFunctions.js\"></script>" .
                $initscript;
        // Bridge client. scorm_tracker.js must load before exe_scorm_bridge.js (the
        // shim calls window.exeScormTracker.createScormApi).
        $bridge = $bridgemarker .
                "\n    <script src=\"libs/scorm_tracker.js\"></script>" .
                "\n    <script src=\"libs/exe_scorm_bridge.js\"></script>\n";
        $bridgehtml = $bridgemarker .
                "\n    <script src=\"../libs/scorm_tracker.js\"></script>" .
                "\n    <script src=\"../libs/exe_scorm_bridge.js\"></script>\n";

        // External-embed shim (independent of SCORM). It self-activates only in the
        // secure opaque-origin iframe, replacing whitelisted/PDF iframes with
        // placeholders whose geometry is relayed to the parent (js/exe_embed_relay.js).
        // The host whitelist is baked as a JS global the shim reads.
        $embedmarker = '<!-- mod_exelearning:embed-shim -->';
        $embedwl = json_encode(
            \mod_exelearning\local\ui\player_iframe::embed_whitelist(),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
        $embed = $embedmarker .
                "\n    <script>window.__exeEmbedWhitelist=$embedwl;</script>" .
                "\n    <script src=\"libs/exe_embed_shim.js\"></script>\n";
        $embedhtml = $embedmarker .
                "\n    <script>window.__exeEmbedWhitelist=$embedwl;</script>" .
                "\n    <script src=\"../libs/exe_embed_shim.js\"></script>\n";

        // Iterate over all HTML files in the filearea.
        $files = $fs->get_area_files(
            $contextid,
            'mod_exelearning',
            'content',
            $revision,
            'filepath, filename',
            false
        );
        foreach ($files as $file) {
            if ($file->is_directory()) {
                continue;
            }
            $name = $file->get_filename();
            if (!preg_match('~\.html?$~i', $name)) {
                continue;
            }
            $html = $file->get_content();
            if ($html === '') {
                continue;
            }
            $path = $file->get_filepath();
            $newhtml = $html;
            $changed = false;

            // Bridge client at the top of <head> (case-insensitive, first match).
            if (strpos($newhtml, $bridgemarker) === false) {
                $bridgepayload = ($path === '/') ? $bridge : $bridgehtml;
                $replaced = preg_replace('~(<head[^>]*>)~i', '${1}' . $bridgepayload, $newhtml, 1);
                if ($replaced !== null && $replaced !== $newhtml) {
                    $newhtml = $replaced;
                    $changed = true;
                }
            }

            // External-embed shim at the top of <head> (independent of the bridge).
            if (strpos($newhtml, $embedmarker) === false) {
                $embedpayload = ($path === '/') ? $embed : $embedhtml;
                $replaced = preg_replace('~(<head[^>]*>)~i', '${1}' . $embedpayload, $newhtml, 1);
                if ($replaced !== null && $replaced !== $newhtml) {
                    $newhtml = $replaced;
                    $changed = true;
                }
            }

            // SCORM wrapper just before </head> (case-insensitive, first match).
            if (strpos($newhtml, $marker) === false) {
                $payload = ($path === '/') ? $tags : $tagshtml;
                $replaced = preg_replace('~</head>~i', $payload . '</head>', $newhtml, 1);
                if ($replaced !== null && $replaced !== $newhtml) {
                    $newhtml = $replaced;
                    $changed = true;
                }
            }

            if (!$changed) {
                continue;
            }
            // Replace content in the filearea: delete and recreate.
            $record = [
                'contextid' => $contextid,
                'component' => 'mod_exelearning',
                'filearea'  => 'content',
                'itemid'    => $revision,
                'filepath'  => $path,
                'filename'  => $name,
            ];
            $file->delete();
            $fs->create_file_from_string($record, $newhtml);
        }
    }
}
