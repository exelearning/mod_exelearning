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
 * Defines the backup task for mod_exelearning.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/exelearning/backup/moodle2/backup_exelearning_stepslib.php');

/**
 * Provides the steps to perform one complete backup of the exelearning instance.
 */
class backup_exelearning_activity_task extends backup_activity_task {
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
        // Generate the exelearning.xml file containing all the activity information.
        $this->add_step(new backup_exelearning_activity_structure_step('exelearning_structure', 'exelearning.xml'));
    }

    /**
     * Encodes URLs to the index.php and view.php scripts.
     *
     * @param string $content some HTML text that eventually contains URLs to the activity instance scripts
     * @return string the content with the URLs encoded
     */
    public static function encode_content_links($content) {
        global $CFG;

        $base = preg_quote($CFG->wwwroot, '/');

        // Link to the list of exelearning activities.
        $search = '/(' . $base . '\/mod\/exelearning\/index\.php\?id\=)([0-9]+)/';
        $content = preg_replace($search, '$@EXELEARNINGINDEX*$2@$', $content);

        // Link to exelearning view by moduleid.
        $search = '/(' . $base . '\/mod\/exelearning\/view\.php\?id\=)([0-9]+)/';
        $content = preg_replace($search, '$@EXELEARNINGVIEWBYID*$2@$', $content);

        return $content;
    }
}
