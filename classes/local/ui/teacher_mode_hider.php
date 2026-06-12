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
 * Teacher-mode toggle hider for the package iframe (mod_exeweb parity).
 *
 * Extracted from lib.php (DEC-0054). The behaviour is unchanged: it queues the
 * same parent-page JS that injects a <style> into the same-origin iframe content
 * document; lib.php keeps a thin delegator.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_exelearning\local\ui;

/**
 * Queues the iframe teacher-mode toggle hider on the current page.
 */
final class teacher_mode_hider {
    /**
     * Hide eXeLearning's teacher-mode toggle (#teacher-mode-toggler-wrapper) inside
     * the package iframe (mod_exeweb parity). Queues parent-page JS that injects a
     * <style> into the iframe's content document once it loads. The iframe is
     * same-origin (served via pluginfile.php), so this DOM access is allowed.
     *
     * @param string $iframeid The id attribute of the package iframe.
     * @return void
     */
    public static function require_for_iframe(string $iframeid): void {
        global $PAGE;

        $iframeidjson = json_encode($iframeid);
        $cssjson = json_encode('#teacher-mode-toggler-wrapper { visibility: hidden !important; }');

        $js = "(function(){"
            . "var iframe=document.getElementById(" . $iframeidjson . ");"
            . "if(!iframe){return;}"
            . "var css=" . $cssjson . ";"
            . "var inject=function(){try{if(!iframe.contentDocument){return;}"
            . "var d=iframe.contentDocument;var st=d.createElement('style');st.textContent=css;"
            . "(d.head||d.documentElement).appendChild(st);}catch(e){}};"
            . "iframe.addEventListener('load', inject);inject();"
            . "})();";

        $PAGE->requires->js_init_code($js);
    }
}
