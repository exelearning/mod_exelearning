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
 * Define the complete exelearning structure for backup, with file and id annotations.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

/**
 * Define the complete exelearning structure for backup, with file and id annotations.
 */
class backup_exelearning_activity_structure_step extends backup_activity_structure_step {

    /**
     * Defines the backup structure of the module.
     *
     * @return backup_nested_element
     */
    protected function define_structure() {

        // To know if we are including userinfo.
        $userinfo = $this->get_setting_value('userinfo');

        // Define the root element describing the exelearning instance.
        $exelearning = new backup_nested_element('exelearning', ['id'], [
            'course', 'name', 'intro', 'introformat', 'display', 'displayoptions',
            'entrypath', 'entryname', 'revision', 'grademax', 'grademin',
            'gradedisplaytype', 'grademethod', 'timecreated', 'timemodified',
            'usermodified',
        ]);

        // Define each element separated.
        $gradeitems = new backup_nested_element('gradeitems');

        $gradeitem = new backup_nested_element('gradeitem', ['id'], [
            'itemnumber', 'objectid', 'pageid', 'idevicetype', 'name',
            'grademax', 'grademin', 'deleted', 'timecreated', 'timemodified',
        ]);

        $attempts = new backup_nested_element('attempts');

        $attempt = new backup_nested_element('attempt', ['id'], [
            'userid', 'attempt', 'itemnumber', 'rawscore', 'maxscore',
            'scaledscore', 'status', 'sessiontoken', 'timecreated', 'timemodified',
        ]);

        // Build the tree.
        $exelearning->add_child($gradeitems);
        $gradeitems->add_child($gradeitem);

        $exelearning->add_child($attempts);
        $attempts->add_child($attempt);

        // Define sources.
        $exelearning->set_source_table('exelearning', ['id' => backup::VAR_ACTIVITYID]);

        // The grade items are package metadata (not user data), so they are
        // always backed up regardless of userinfo.
        $gradeitem->set_source_table('exelearning_grade_item',
            ['exelearningid' => backup::VAR_PARENTID]);

        // All the rest of elements only happen if we are including user info.
        if ($userinfo) {
            $attempt->set_source_table('exelearning_attempt',
                ['exelearningid' => backup::VAR_PARENTID]);
        }

        // Define id annotations.
        $attempt->annotate_ids('user', 'userid');

        // Define file annotations.
        $exelearning->annotate_files('mod_exelearning', 'intro', null);
        $exelearning->annotate_files('mod_exelearning', 'package', null);
        $exelearning->annotate_files('mod_exelearning', 'content', null);

        // Return the root element (exelearning), wrapped into standard activity structure.
        return $this->prepare_activity_structure($exelearning);
    }
}
