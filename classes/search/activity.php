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
 * Search area for mod_exelearning activities.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_exelearning\search;

/**
 * Search area for mod_exelearning activities.
 *
 * Indexes the activity intro and, through file indexing, the text extracted from
 * the eXeLearning package stored under the `content` file area so the authored
 * content becomes findable from Moodle global search. The visibility, context
 * resolution and access checks are inherited from \core_search\base_activity;
 * we only declare which file areas carry indexable content, mirroring mod_scorm.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class activity extends \core_search\base_activity {
    /**
     * Returns true so that the files attached to the activity are indexed.
     *
     * @return bool
     */
    public function uses_file_indexing() {
        return true;
    }

    /**
     * Returns the file areas whose files should be indexed.
     *
     * `intro` covers the activity description attachments and `content` carries
     * the HTML/text extracted from the eXeLearning package (see
     * exelearning_get_file_areas() and exelearning_pluginfile() in lib.php).
     *
     * @return array
     */
    public function get_search_fileareas() {
        return ['intro', 'content'];
    }
}
