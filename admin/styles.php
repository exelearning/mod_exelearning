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
 * Admin page to manage uploaded eXeLearning styles.
 *
 * @package    mod_exelearning
 * @copyright  2025 eXeLearning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use mod_exelearning\local\styles_service;

admin_externalpage_setup('mod_exelearning_styles');

$context = \context_system::instance();
require_capability('moodle/site:config', $context);
require_capability('mod/exelearning:manageembeddededitor', $context);

$action = optional_param('action', '', PARAM_ALPHA);

$PAGE->set_url(new moodle_url('/mod/exelearning/admin/styles.php'));
$PAGE->set_title(get_string('stylesmanager', 'mod_exelearning'));
$PAGE->set_heading(get_string('stylesmanager', 'mod_exelearning'));

$returnurl = new moodle_url('/mod/exelearning/admin/styles.php');

if ($action !== '' && confirm_sesskey()) {
    if ($action === 'upload') {
        $file = $_FILES['stylezip'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            \core\notification::error(get_string('stylesupload_failed', 'mod_exelearning'));
            redirect($returnurl);
        }
        try {
            $entry = styles_service::install_from_zip($file['tmp_name'], $file['name'] ?? '');
            \core\notification::success(get_string('stylesupload_success', 'mod_exelearning', $entry['id'] ?? ''));
        } catch (\moodle_exception $e) {
            \core\notification::error($e->getMessage());
        }
        redirect($returnurl);
    } else if ($action === 'enable' || $action === 'disable') {
        $slug = required_param('slug', PARAM_RAW);
        styles_service::set_uploaded_enabled($slug, $action === 'enable');
        \core\notification::success(get_string('changessaved'));
        redirect($returnurl);
    } else if ($action === 'delete') {
        $slug = required_param('slug', PARAM_RAW);
        styles_service::delete_uploaded($slug);
        \core\notification::success(get_string('stylesdelete_success', 'mod_exelearning'));
        redirect($returnurl);
    } else if ($action === 'enablebuiltin' || $action === 'disablebuiltin') {
        $id = required_param('id', PARAM_RAW);
        styles_service::set_builtin_enabled($id, $action === 'enablebuiltin');
        \core\notification::success(get_string('changessaved'));
        redirect($returnurl);
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('stylesmanager', 'mod_exelearning'));

echo \html_writer::tag('p', get_string('stylesmanager_intro', 'mod_exelearning'));

// Upload form.
$upload = new \mod_exelearning\admin\admin_setting_stylesupload();
echo $upload->output_html('');

// Uploaded styles list.
$uploaded = new \mod_exelearning\admin\admin_setting_stylesuploaded();
echo $uploaded->output_html('');

// Built-in themes list.
$builtins = new \mod_exelearning\admin\admin_setting_stylesbuiltins();
echo $builtins->output_html('');

echo $OUTPUT->footer();
