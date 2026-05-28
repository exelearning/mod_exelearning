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
 * Admin setting that renders the embedded editor management card.
 *
 * @package    mod_exelearning
 * @copyright  2025 eXeLearning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_exelearning\admin;

defined('MOODLE_INTERNAL') || die();

use admin_setting;

/**
 * Custom admin setting that renders the embedded editor management UI.
 *
 * This is a read-only "setting" (it stores nothing itself) that renders the
 * admin card for installing/updating/repairing/uninstalling the embedded
 * editor. All actions happen through AJAX via the manage_embedded_editor
 * external service; this widget only renders the initial markup and wires up
 * the JS module.
 */
class admin_setting_embeddededitor extends admin_setting {

    /**
     * Constructor.
     */
    public function __construct() {
        $this->nosave = true;
        parent::__construct(
            'mod_exelearning/embeddededitor_manage',
            get_string('embeddededitorstatus', 'mod_exelearning'),
            '',
            ''
        );
    }

    /**
     * Always return true: there is no stored setting.
     *
     * @return mixed
     */
    public function get_setting() {
        return true;
    }

    /**
     * Never writes anything.
     *
     * @param mixed $data
     * @return string Always empty (success).
     */
    public function write_setting($data) {
        return '';
    }

    /**
     * Render the admin card markup and wire up the JS module.
     *
     * @param mixed $data
     * @param string $query
     * @return string HTML.
     */
    public function output_html($data, $query = '') {
        global $PAGE, $OUTPUT;

        $status = \mod_exelearning\local\embedded_editor_source_resolver::get_status();

        $installerversion = \mod_exelearning\local\embedded_editor_installer::get_installed_version();
        $installedat = \mod_exelearning\local\embedded_editor_installer::get_installed_at();

        $context = [
            'moodledataAvailable' => $status->moodledata_available,
            'moodledataVersion' => $status->moodledata_version ?? '',
            'installedAt' => $installedat ?? '',
            'bundledAvailable' => $status->bundled_available,
            'activeSource' => $status->active_source,
            'sesskey' => sesskey(),
            'uploadUrl' => (new \moodle_url('/mod/exelearning/manage_embedded_editor_upload.php'))->out(false),
        ];

        $html = $OUTPUT->render_from_template('mod_exelearning/admin_embedded_editor', $context);

        $PAGE->requires->js_call_amd('mod_exelearning/admin_embedded_editor', 'init');

        return format_admin_setting(
            $this,
            $this->visiblename,
            $html,
            $this->description,
            true,
            '',
            null,
            $query
        );
    }
}
