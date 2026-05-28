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
 * Admin setting: upload an eXeLearning style ZIP package.
 *
 * @package    mod_exelearning
 * @copyright  2025 eXeLearning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_exelearning\admin;

defined('MOODLE_INTERNAL') || die();

use admin_setting;

/**
 * Renders a styles upload control on the styles admin page.
 *
 * This setting performs the upload immediately (on its own POST) and stores
 * nothing in config itself.
 */
class admin_setting_stylesupload extends admin_setting {

    /**
     * Constructor.
     */
    public function __construct() {
        $this->nosave = true;
        parent::__construct(
            'mod_exelearning/stylesupload',
            get_string('stylesupload_label', 'mod_exelearning'),
            get_string('stylesupload_hint', 'mod_exelearning'),
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
     * Never writes anything via the normal settings pipeline.
     *
     * @param mixed $data
     * @return string
     */
    public function write_setting($data) {
        return '';
    }

    /**
     * Render the upload form.
     *
     * @param mixed $data
     * @param string $query
     * @return string
     */
    public function output_html($data, $query = '') {
        $uploadurl = new \moodle_url('/mod/exelearning/admin/styles.php');
        $maxbytes = \mod_exelearning\local\styles_service::get_max_zip_size();

        $html = '';
        $html .= \html_writer::start_tag('form', [
            'method' => 'post',
            'action' => $uploadurl->out(false),
            'enctype' => 'multipart/form-data',
            'class' => 'mod-exelearning-styles-upload-form',
        ]);
        $html .= \html_writer::empty_tag('input', [
            'type' => 'hidden',
            'name' => 'sesskey',
            'value' => sesskey(),
        ]);
        $html .= \html_writer::empty_tag('input', [
            'type' => 'hidden',
            'name' => 'action',
            'value' => 'upload',
        ]);
        $html .= \html_writer::empty_tag('input', [
            'type' => 'hidden',
            'name' => 'MAX_FILE_SIZE',
            'value' => (string) $maxbytes,
        ]);
        $html .= \html_writer::empty_tag('input', [
            'type' => 'file',
            'name' => 'stylezip',
            'accept' => '.zip',
            'class' => 'form-control-file',
            'required' => 'required',
        ]);
        $html .= ' ';
        $html .= \html_writer::tag('button', get_string('stylesupload_label', 'mod_exelearning'), [
            'type' => 'submit',
            'class' => 'btn btn-primary',
        ]);
        $html .= \html_writer::end_tag('form');

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
