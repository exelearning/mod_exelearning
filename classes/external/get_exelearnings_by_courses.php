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
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use core_external\external_warnings;
use core_external\util as external_util;
use core_course\external\helper_for_get_mods_by_courses;
use context_module;

/**
 * External function: list mod_exelearning instances in a set of courses.
 *
 * Canonical "get mods by courses" listing (mirrors mod_scorm / mod_exeweb): when no
 * course ids are given it lists the activities in the user's enrolled courses, and
 * it returns a warning for any course the user cannot access rather than failing.
 * Visibility is enforced by get_all_instances_in_courses().
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_exelearnings_by_courses extends external_api {
    /**
     * Parameters for execute().
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Course id'),
                'Array of course ids',
                VALUE_DEFAULT,
                []
            ),
        ]);
    }

    /**
     * List the accessible exelearning instances in the given courses.
     *
     * @param int[] $courseids Course ids (empty = the user's enrolled courses).
     * @return array exelearnings + warnings.
     */
    public static function execute(array $courseids = []): array {
        global $CFG;
        require_once($CFG->dirroot . '/mod/exelearning/lib.php');

        $warnings = [];
        $returned = [];
        $params = self::validate_parameters(self::execute_parameters(), ['courseids' => $courseids]);

        $mycourses = [];
        if (empty($params['courseids'])) {
            $mycourses = enrol_get_my_courses();
            $params['courseids'] = array_keys($mycourses);
        }

        if (!empty($params['courseids'])) {
            [$courses, $warnings] = external_util::validate_courses($params['courseids'], $mycourses);

            // Visibility/permission filtering is done by get_all_instances_in_courses().
            $instances = get_all_instances_in_courses('exelearning', $courses);
            foreach ($instances as $instance) {
                $context = context_module::instance($instance->coursemodule);
                helper_for_get_mods_by_courses::format_name_and_intro($instance, 'mod_exelearning');

                // The source ELPX download is teacher-only (pluginfile enforces it);
                // only surface its URL to users who can manage activities.
                if (has_capability('moodle/course:manageactivities', $context)) {
                    $url = exelearning_get_package_url($instance, $context);
                    if ($url) {
                        $instance->packageurl = $url->out(false);
                    }
                }

                $returned[] = $instance;
            }
        }

        return ['exelearnings' => $returned, 'warnings' => $warnings];
    }

    /**
     * Return description for execute().
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'exelearnings' => new external_multiple_structure(
                new external_single_structure(array_merge(
                    helper_for_get_mods_by_courses::standard_coursemodule_elements_returns(),
                    [
                        'revision'     => new external_value(PARAM_INT, 'Package revision (cache buster)', VALUE_OPTIONAL),
                        'grademodel'   => new external_value(PARAM_INT, '0=overall column, 1=per-iDevice columns', VALUE_OPTIONAL),
                        'grademethod'  => new external_value(PARAM_INT, 'Attempt aggregation method', VALUE_OPTIONAL),
                        'grademax'     => new external_value(PARAM_FLOAT, 'Maximum grade', VALUE_OPTIONAL),
                        'gradepass'    => new external_value(PARAM_FLOAT, 'Passing grade (0 = none)', VALUE_OPTIONAL),
                        'gradeenabled' => new external_value(PARAM_INT, 'Whether the activity is graded', VALUE_OPTIONAL),
                        'maxattempt'   => new external_value(PARAM_INT, 'Max attempts (0 = unlimited)', VALUE_OPTIONAL),
                        'reviewmode'   => new external_value(PARAM_INT, 'When students may review attempts', VALUE_OPTIONAL),
                        'packageurl'   => new external_value(PARAM_URL, 'Source ELPX download url (teachers only)', VALUE_OPTIONAL),
                        'timemodified' => new external_value(PARAM_INT, 'Last modification time', VALUE_OPTIONAL),
                    ]
                ), 'exelearning instance')
            ),
            'warnings' => new external_warnings(),
        ]);
    }
}
