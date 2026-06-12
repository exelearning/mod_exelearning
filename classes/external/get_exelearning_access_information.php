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
 * External function: return the current user's capability flags on an activity.
 *
 * Mirrors mod_scorm_get_scorm_access_information: returns one `can<capability>`
 * boolean per plugin capability so a client can decide which actions to offer.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_exelearning_access_information extends external_api {
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
     * Return the user's capability flags on the activity.
     *
     * @param int $exelearningid The exelearning instance id.
     * @return array can<capability> flags + warnings.
     */
    public static function execute(int $exelearningid): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), ['exelearningid' => $exelearningid]);

        $exelearning = $DB->get_record('exelearning', ['id' => $params['exelearningid']], '*', MUST_EXIST);
        [$course, $cm] = get_course_and_cm_from_instance($exelearning, 'exelearning');

        $context = context_module::instance($cm->id);
        self::validate_context($context);

        $result = ['warnings' => []];
        foreach (array_keys(load_capability_def('mod_exelearning')) as $capname) {
            // Expose each capability as can<short> so the field set is consistent
            // with the access_information functions of other activity modules.
            $field = 'can' . str_replace('mod/exelearning:', '', $capname);
            $result[$field] = has_capability($capname, $context);
        }

        return $result;
    }

    /**
     * Return description for execute().
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        $structure = ['warnings' => new external_warnings()];
        foreach (array_keys(load_capability_def('mod_exelearning')) as $capname) {
            $field = 'can' . str_replace('mod/exelearning:', '', $capname);
            $structure[$field] = new external_value(
                PARAM_BOOL,
                'Whether the user has the ' . $capname . ' capability',
                VALUE_OPTIONAL
            );
        }
        return new external_single_structure($structure);
    }
}
