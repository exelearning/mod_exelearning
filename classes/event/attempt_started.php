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
 * The mod_exelearning attempt started event.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_exelearning\event;

/**
 * Fired the first time a learner persists a tracking commit for a new attempt.
 *
 * Emitted by {@see \mod_exelearning\local\track::ingest()} — the single pipeline
 * shared by the web `track.php` endpoint and the `save_track` web service — so the
 * signal is identical whether the learner works on the web or in the mobile app. It
 * carries the attempt number in `other['attempt']` and the learner in
 * `relateduserid`. Logged at LEVEL_PARTICIPATING (a normal learning interaction).
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class attempt_started extends \core\event\base {
    /**
     * Init method.
     */
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'exelearning';
    }

    /**
     * Returns the event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventattemptstarted', 'mod_exelearning');
    }

    /**
     * Returns a human-readable description of the event.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->relateduserid}' started attempt '{$this->other['attempt']}' "
            . "in the exelearning activity with course module id '{$this->contextinstanceid}'.";
    }

    /**
     * Returns the URL related to the event.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/mod/exelearning/view.php', ['id' => $this->contextinstanceid]);
    }

    /**
     * Validates the custom event data.
     *
     * @return void
     */
    protected function validate_data() {
        parent::validate_data();
        if (!isset($this->other['attempt'])) {
            throw new \coding_exception('The \'attempt\' value must be set in other.');
        }
        if (!isset($this->relateduserid)) {
            throw new \coding_exception('The \'relateduserid\' must be set.');
        }
    }

    /**
     * Object id mapping for backup/restore.
     *
     * @return array
     */
    public static function get_objectid_mapping() {
        return ['db' => 'exelearning', 'restore' => 'exelearning'];
    }
}
