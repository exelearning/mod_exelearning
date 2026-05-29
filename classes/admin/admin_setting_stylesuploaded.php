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
 * Admin setting: list the uploaded styles with enable/disable/delete actions.
 *
 * @package    mod_exelearning
 * @copyright  2025 eXeLearning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_exelearning\admin;

use admin_setting;

/**
 * Renders the list of uploaded styles as a table with actions.
 */
class admin_setting_stylesuploaded extends admin_setting {
    /**
     * Constructor.
     */
    public function __construct() {
        $this->nosave = true;
        parent::__construct(
            'mod_exelearning/stylesuploaded',
            get_string('stylesuploaded', 'mod_exelearning'),
            get_string('stylesuploaded_hint', 'mod_exelearning'),
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
     * Render the uploaded styles table.
     *
     * @param mixed $data
     * @param string $query
     * @return string
     */
    public function output_html($data, $query = '') {
        $styles = \mod_exelearning\local\styles_service::list_uploaded_styles();
        $baseurl = new \moodle_url('/mod/exelearning/admin/styles.php');

        $html = '';
        if (empty($styles)) {
            $html .= \html_writer::tag('p', get_string('stylesuploaded_empty', 'mod_exelearning'), [
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
            get_string('stylestable_installed', 'mod_exelearning'),
            get_string('stylestable_enabled', 'mod_exelearning'),
            get_string('stylestable_actions', 'mod_exelearning'),
        ];
        $table->attributes['class'] = 'generaltable';

        foreach ($styles as $style) {
            $slug = $style['id'];
            $enabled = !empty($style['enabled']);

            $togglelabel = $enabled
                ? get_string('stylesdisable', 'mod_exelearning')
                : get_string('stylesenable', 'mod_exelearning');
            $toggleaction = $enabled ? 'disable' : 'enable';

            $toggleform = $this->action_form(
                $baseurl,
                $toggleaction,
                $slug,
                $togglelabel,
                $enabled ? 'btn-secondary' : 'btn-success'
            );
            $deleteform = $this->action_form(
                $baseurl,
                'delete',
                $slug,
                get_string('stylesdelete', 'mod_exelearning'),
                'btn-danger',
                get_string('stylesdelete_confirm', 'mod_exelearning')
            );

            $statusbadge = $enabled
                ? \html_writer::tag('span', get_string('yes'), ['class' => 'badge badge-success'])
                : \html_writer::tag('span', get_string('no'), ['class' => 'badge badge-secondary']);

            $table->data[] = [
                s($style['title'] ?? $slug),
                \html_writer::tag('code', s($slug)),
                s($style['version'] ?? ''),
                s($style['installed_at'] ?? ''),
                $statusbadge,
                $toggleform . ' ' . $deleteform,
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
     * @param string $slug
     * @param string $label
     * @param string $btnclass
     * @param string $confirm Optional confirm message.
     * @return string
     */
    private function action_form(
        \moodle_url $baseurl,
        string $action,
        string $slug,
        string $label,
        string $btnclass,
        string $confirm = ''
    ): string {
        $attrs = [
            'method' => 'post',
            'action' => $baseurl->out(false),
            'class' => 'd-inline',
        ];
        if ($confirm !== '') {
            $attrs['data-confirm'] = $confirm;
            $attrs['onsubmit'] = 'return confirm(this.getAttribute("data-confirm"));';
        }
        $form = \html_writer::start_tag('form', $attrs);
        $form .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        $form .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => $action]);
        $form .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'slug', 'value' => $slug]);
        $form .= \html_writer::tag('button', $label, ['type' => 'submit', 'class' => 'btn btn-sm ' . $btnclass]);
        $form .= \html_writer::end_tag('form');
        return $form;
    }
}
