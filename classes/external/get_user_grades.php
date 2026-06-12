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
use context_module;
use core_user;

/**
 * External function: return a user's per-item grades for a mod_exelearning activity.
 *
 * Reflects the real gradebook columns (the per-iDevice items in PERITEM mode, or the
 * overall item in OVERALL mode), enriched with each iDevice's type/name. A user may
 * read their own grades; reading another user's grades requires
 * mod/exelearning:viewreport.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_user_grades extends external_api {
    /**
     * Parameters for execute().
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'exelearningid' => new external_value(PARAM_INT, 'exelearning instance id'),
            'userid'        => new external_value(PARAM_INT, 'User id (0 = current user)', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Return the user's per-item grades.
     *
     * @param int $exelearningid The exelearning instance id.
     * @param int $userid The user id (0 = current user).
     * @return array grades + warnings.
     */
    public static function execute(int $exelearningid, int $userid = 0): array {
        global $DB, $USER, $CFG;
        require_once($CFG->libdir . '/gradelib.php');

        $params = self::validate_parameters(self::execute_parameters(), [
            'exelearningid' => $exelearningid,
            'userid'        => $userid,
        ]);

        $exelearning = $DB->get_record('exelearning', ['id' => $params['exelearningid']], '*', MUST_EXIST);
        [$course, $cm] = get_course_and_cm_from_instance($exelearning, 'exelearning');

        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/exelearning:view', $context);

        $targetuserid = $params['userid'] ?: $USER->id;
        $user = core_user::get_user($targetuserid, '*', MUST_EXIST);
        core_user::require_active_user($user);
        if ($user->id != $USER->id) {
            require_capability('mod/exelearning:viewreport', $context);
        }

        // Per-iDevice metadata (type/name) keyed by itemnumber, to enrich columns.
        $meta = $DB->get_records(
            'exelearning_grade_item',
            ['exelearningid' => $exelearning->id, 'deleted' => 0],
            '',
            'itemnumber, name, idevicetype'
        );

        $gradeinfo = grade_get_grades($exelearning->course, 'mod', 'exelearning', $exelearning->id, $user->id);
        $grades = [];
        foreach ($gradeinfo->items as $itemnumber => $item) {
            $itemnumber = (int) $itemnumber;
            $grademax = (float) $item->grademax;
            $value = $item->grades[$user->id]->grade ?? null;
            $entry = [
                'itemnumber'  => $itemnumber,
                'name'        => isset($meta[$itemnumber]) ? (string) $meta[$itemnumber]->name : (string) $item->itemname,
                'idevicetype' => isset($meta[$itemnumber]) ? (string) $meta[$itemnumber]->idevicetype : '',
                'grademax'    => $grademax,
            ];
            if ($value !== null) {
                $entry['grade'] = (float) $value;
                $entry['percent'] = ($grademax > 0) ? round((float) $value / $grademax * 100, 5) : 0.0;
            }
            $grades[] = $entry;
        }

        return ['grades' => $grades, 'warnings' => []];
    }

    /**
     * Return description for execute().
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'grades' => new external_multiple_structure(
                new external_single_structure([
                    'itemnumber'  => new external_value(PARAM_INT, '0 = overall, > 0 = iDevice'),
                    'name'        => new external_value(PARAM_TEXT, 'Gradebook column name'),
                    'idevicetype' => new external_value(PARAM_NOTAGS, 'iDevice type slug (empty for the overall)'),
                    'grademax'    => new external_value(PARAM_FLOAT, 'Maximum grade for the item'),
                    'grade'       => new external_value(PARAM_FLOAT, 'The user grade (absent when ungraded)', VALUE_OPTIONAL),
                    'percent'     => new external_value(PARAM_FLOAT, 'The grade as a 0..100 percentage', VALUE_OPTIONAL),
                ])
            ),
            'warnings' => new external_warnings(),
        ]);
    }
}
