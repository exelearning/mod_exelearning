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
 * Gradebook redirect for mod_exelearning.
 *
 * The Moodle gradebook links every activity grade item to this script, passing
 * the clicked item's `itemnumber`. We map that itemnumber to the owning iDevice
 * and forward to the activity view, deep-linking straight to the iDevice that the
 * grade belongs to instead of the resource front page (issue #13 #4, DEC-0023).
 * Mirrors the redirect pattern of core mod_h5pactivity/grade.php.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/exelearning/lib.php');

$id = required_param('id', PARAM_INT); // Course module id.
$itemnumber = optional_param('itemnumber', 0, PARAM_INT); // Grade item number (0 = overall grade).

$cm = get_coursemodule_from_id('exelearning', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$exelearning = $DB->get_record('exelearning', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);

// Forward to the activity view, deep-linking to the iDevice that owns this grade item.
redirect(exelearning_grade_item_view_url($exelearning, $cm->id, $itemnumber));
