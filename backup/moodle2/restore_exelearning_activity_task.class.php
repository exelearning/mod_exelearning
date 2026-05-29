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
 * Defines the restore task for mod_exelearning.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/exelearning/backup/moodle2/restore_exelearning_stepslib.php');

/**
 * exelearning restore task that provides all the settings and steps to perform one complete restore of the activity.
 */
class restore_exelearning_activity_task extends restore_activity_task {
    /**
     * Defines particular settings this activity can have.
     */
    protected function define_my_settings() {
        // No particular settings for this activity.
    }

    /**
     * Defines particular steps this activity can have.
     */
    protected function define_my_steps() {
        // The exelearning only has one structure step.
        $this->add_step(new restore_exelearning_activity_structure_step('exelearning_structure', 'exelearning.xml'));
    }

    /**
     * Defines the contents in the activity that must be processed by the link decoder.
     *
     * @return array
     */
    public static function define_decode_contents() {
        $contents = [];

        $contents[] = new restore_decode_content('exelearning', ['intro'], 'exelearning');

        return $contents;
    }

    /**
     * Defines the decoding rules for links belonging to the activity to be executed by the link decoder.
     *
     * @return array
     */
    public static function define_decode_rules() {
        $rules = [];

        // Link to the list of exelearning activities.
        $rules[] = new restore_decode_rule('EXELEARNINGINDEX', '/mod/exelearning/index.php?id=$1', 'course');

        // Link to exelearning view by moduleid.
        $rules[] = new restore_decode_rule('EXELEARNINGVIEWBYID', '/mod/exelearning/view.php?id=$1', 'course_module');

        return $rules;
    }

    /**
     * Defines the restore log rules that will be applied by the
     * {@see restore_logs_processor} when restoring exelearning logs.
     *
     * @return array
     */
    public static function define_restore_log_rules() {
        $rules = [];

        $rules[] = new restore_log_rule('exelearning', 'add', 'view.php?id={course_module}', '{exelearning}');
        $rules[] = new restore_log_rule('exelearning', 'update', 'view.php?id={course_module}', '{exelearning}');
        $rules[] = new restore_log_rule('exelearning', 'view', 'view.php?id={course_module}', '{exelearning}');

        return $rules;
    }
}
