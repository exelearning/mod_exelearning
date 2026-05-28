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
 * Admin setting: list built-in themes with enable/disable toggles.
 *
 * @package    mod_exelearning
 * @copyright  2025 eXeLearning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_exelearning\admin;

defined('MOODLE_INTERNAL') || die();

use admin_setting;

/**
 * Renders built-in themes discovered from the editor bundle with toggles.
 */
class admin_setting_stylesbuiltins extends admin_setting {

    /**
     * Constructor.
     */
    public function __construct() {
        $this->nosave = true;
        parent::__construct(
            'mod_exelearning/stylesbuiltins',
            get_string('stylesbuiltin', 'mod_exelearning'),
            get_string('stylesbuiltin_hint', 'mod_exelearning'),
            ''
        );
    }

    /**
     * No stored value.
     *
     * @return mixed
     */
    public function get_setting() {
        return true;
    }

    /**
     * Never writes.
     *
     * @param mixed $data
     * @return string
     */
    public function write_setting($data) {
        return '';
    }

    /**
     * Render the built-in themes list.
     *
     * @param mixed $data
     * @param string $query
     * @return string
     */
    public function output_html($data, $query = '') {
        $builtins = \mod_exelearning\local\styles_service::list_builtin_themes();
        $registry = \mod_exelearning\local\styles_service::get_registry();
        $disabled = $registry['disabled_builtins'];
        $baseurl = new \moodle_url('/mod/exelearning/admin/styles.php');

        $html = '';
        if (empty($builtins)) {
            $html .= \html_writer::tag('p', get_string('stylesbuiltin_empty', 'mod_exelearning'), [
                'class' => 'text-muted',
            ]);
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

        $table = new \html_table();
        $table->head = [
            get_string('stylestable_title', 'mod_exelearning'),
            get_string('stylestable_id', 'mod_exelearning'),
            get_string('stylestable_version', 'mod_exelearning'),
            get_string('stylestable_enabled', 'mod_exelearning'),
            get_string('stylestable_actions', 'mod_exelearning'),
        ];
        $table->attributes['class'] = 'generaltable';

        foreach ($builtins as $theme) {
            $id = $theme['id'];
            $isenabled = !in_array($id, $disabled, true);

            $togglelabel = $isenabled
                ? get_string('stylesdisable', 'mod_exelearning')
                : get_string('stylesenable', 'mod_exelearning');
            $toggleaction = $isenabled ? 'disablebuiltin' : 'enablebuiltin';

            $toggleform = $this->action_form($baseurl, $toggleaction, $id, $togglelabel,
                $isenabled ? 'btn-secondary' : 'btn-success');

            $statusbadge = $isenabled
                ? \html_writer::tag('span', get_string('yes'), ['class' => 'badge badge-success'])
                : \html_writer::tag('span', get_string('no'), ['class' => 'badge badge-secondary']);

            $table->data[] = [
                s($theme['title'] ?? $id),
                \html_writer::tag('code', s($id)),
                s($theme['version'] ?? ''),
                $statusbadge,
                $toggleform,
            ];
        }

        $html .= \html_writer::table($table);

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

    /**
     * Build a small inline POST form for a single action.
     *
     * @param \moodle_url $baseurl
     * @param string $action
     * @param string $id
     * @param string $label
     * @param string $btnclass
     * @return string
     */
    private function action_form(\moodle_url $baseurl, string $action, string $id, string $label,
            string $btnclass): string {
        $form = \html_writer::start_tag('form', [
            'method' => 'post',
            'action' => $baseurl->out(false),
            'class' => 'd-inline',
        ]);
        $form .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        $form .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => $action]);
        $form .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $id]);
        $form .= \html_writer::tag('button', $label, ['type' => 'submit', 'class' => 'btn btn-sm ' . $btnclass]);
        $form .= \html_writer::end_tag('form');
        return $form;
    }
}
