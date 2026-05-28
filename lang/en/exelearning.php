<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * English strings for mod_exelearning.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname']        = 'eXeLearning resource';
$string['pluginnameplural']  = 'eXeLearning resources';
$string['modulename']        = 'eXeLearning resource';
$string['modulenameplural']  = 'eXeLearning resources';
$string['modulename_help']   = 'The eXeLearning resource module embeds a published eXeLearning v4 package in a Moodle course while preserving its native navigation sidebar and recording one or more gradable items in the Moodle gradebook.';
$string['pluginadministration'] = 'eXeLearning resource administration';

$string['exelearning:addinstance']           = 'Add a new eXeLearning resource';
$string['exelearning:view']                  = 'View an eXeLearning resource';
$string['exelearning:savetrack']             = 'Save tracking interactions';
$string['exelearning:viewreport']            = 'View reports';
$string['exelearning:manageembeddededitor']  = 'Manage the embedded eXeLearning editor settings';

$string['package']       = 'Package file (.elpx)';
$string['package_help']  = 'Upload an eXeLearning v4 package (.elpx). The package is extracted server-side and rendered with its native sidebar preserved.';
$string['intro']         = 'Description';

$string['gradingheading'] = 'Grading';
$string['grademax']       = 'Maximum grade per item';
$string['grademax_help']  = 'Each gradable iDevice in the package (trueorfalse, guess, drag-and-drop, quizzes…) is registered as a separate column in the gradebook with this maximum value.';
$string['grademin']       = 'Minimum grade per item';
$string['gradeitem_overall'] = 'Overall';

$string['gradedisplay']                  = 'Grade display';
$string['gradedisplay_help']             = 'Choose how each gradebook column for this activity is displayed to students and teachers. Moodle always stores grades numerically; this setting only changes the format shown in the grader report and the student dashboard. "Default" inherits from the course-wide setting.';
$string['gradedisplay_default']          = 'Default (inherit from course)';
$string['gradedisplay_real']             = 'Real (0-100)';
$string['gradedisplay_percentage']       = 'Percentage';
$string['gradedisplay_letter']           = 'Letter (A, B, …)';
$string['gradedisplay_real_percentage']  = 'Real and percentage';

$string['areacontent']      = 'Extracted package files';
$string['areapackage']      = 'Original package file (.elpx)';
$string['packagenotfound']  = 'The eXeLearning package could not be found or has not finished extracting yet.';
$string['detecteditems']    = 'Gradable iDevices detected:';

$string['viewstub']      = 'This is a placeholder view. The full mod_exelearning render (iframe + sidebar + xAPI bridge) is under construction. See <code>research/</code> in the plugin source for the design history.';
$string['noexelearningactivities'] = 'There are no eXeLearning resources in this course yet.';

$string['privacy:metadata'] = 'mod_exelearning does not store personal data by itself yet. Gradebook and xAPI interactions are handled by Moodle core subsystems.';

// Strings generadas para multi-grade-items (mod_exelearning\grades\gradeitems).
// Moodle 5 form_trait pide get_string("grade_<itemname>_name", $component) por
// cada itemnumber del mapeo.
$string['grade_overall_name'] = 'Overall (aggregated)';
for ($exelearningstringi = 1; $exelearningstringi <= 100; $exelearningstringi++) {
    $string['grade_idevice' . $exelearningstringi . '_name'] = 'iDevice ' . $exelearningstringi;
}
unset($exelearningstringi);

// DEC-0006: modos preview/grading.
$string['previewmode']        = 'Preview mode (test):';
$string['previewmode_desc']   = 'Nothing you do here will be saved to the gradebook.';
$string['previewmode_enter']  = 'Try as a student (preview)';
$string['previewmode_exit']   = 'Exit preview mode';

// DEC-0005 settings.
$string['embeddededitor']        = 'Embedded eXeLearning editor';
$string['embeddededitor_desc']   = 'Enable the in-browser eXeLearning editor for authors. Requires running `make build-editor`.';
$string['editormode']            = 'Editor mode';
$string['editormode_desc']       = 'Where to load the eXeLearning editor from when teachers click "Edit".';
$string['editormode_embedded']   = 'Embedded (bundled static editor)';
$string['editormode_online']     = 'eXeLearning Online (external service)';
$string['exeonline_baseuri']     = 'eXeLearning Online base URL';
$string['exeonline_baseuri_desc'] = 'Only used when editor mode is "online". Leave empty to disable.';
$string['exeonline_hmackey1']    = 'HMAC signing key';
$string['exeonline_hmackey1_desc'] = 'Shared secret used to sign the handshake with eXeLearning Online.';
