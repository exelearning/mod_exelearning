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

namespace mod_exelearning\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use core_external\external_warnings;
use context_module;

/**
 * External function: simulate viewing a mod_exelearning activity.
 *
 * Triggers the course_module_viewed event and updates completion, exactly like
 * opening view.php, so the mobile app / external clients can record a view. Mirrors
 * mod_scorm_view_scorm and mod_exeweb_view_exeweb.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class view_exelearning extends external_api {
    /**
     * Parameters for execute().
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'exelearningid' => new external_value(PARAM_INT, 'exelearning instance id'),
        ]);
    }

    /**
     * Trigger the viewed event and update completion.
     *
     * @param int $exelearningid The exelearning instance id.
     * @return array status + warnings.
     */
    public static function execute(int $exelearningid): array {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/mod/exelearning/lib.php');

        $params = self::validate_parameters(self::execute_parameters(), ['exelearningid' => $exelearningid]);

        $exelearning = $DB->get_record('exelearning', ['id' => $params['exelearningid']], '*', MUST_EXIST);
        // Resolve a plain stdClass cm (not the cm_info get_course_and_cm_from_instance
        // returns), because exelearning_view() type-hints stdClass.
        $cm = get_coursemodule_from_instance('exelearning', $exelearning->id, 0, false, MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);

        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/exelearning:view', $context);

        exelearning_view($exelearning, $course, $cm, $context);

        return ['status' => true, 'warnings' => []];
    }

    /**
     * Return description for execute().
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status'   => new external_value(PARAM_BOOL, 'status: true if success'),
            'warnings' => new external_warnings(),
        ]);
    }
}
