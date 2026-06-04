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
 * Admin page: bulk-migrate mod_exeweb / mod_exescorm activities into eXeLearning.
 *
 * Site-wide, non-destructive migration tool (issue #13 #3, DEC-0026). The originals
 * are kept; admins verify the result before removing the old plugin. exescorm grades
 * are copied to the new activity's overall grade.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/mod/exelearning/lib.php');

use mod_exelearning\local\import_service;

admin_externalpage_setup('mod_exelearning_migrate');

$context = \context_system::instance();
require_capability('mod/exelearning:migrate', $context);

$sibling = optional_param('sibling', '', PARAM_ALPHA);
$confirm = optional_param('confirm', 0, PARAM_BOOL);

$baseurl = new moodle_url('/mod/exelearning/admin/migrate.php');
$PAGE->set_url($baseurl);
$PAGE->set_title(get_string('migratetitle', 'mod_exelearning'));
$PAGE->set_heading(get_string('migratetitle', 'mod_exelearning'));

// Which siblings are installed (and how many activities each has).
$installedmods = \core_component::get_plugin_list('mod');
$available = [];
foreach (import_service::SUPPORTED_MODULES as $mod) {
    if (isset($installedmods[$mod])) {
        $available[$mod] = import_service::count_sources($mod);
    }
}

// Run the migration for one sibling (confirmed POST).
if ($sibling !== '' && isset($available[$sibling]) && $confirm && confirm_sesskey()) {
    // Site-wide bulk operation: release the session lock and lift PHP limits so a
    // large run does not block other requests or time out.
    \core\session\manager::write_close();
    \core_php_time_limit::raise(0);
    raise_memory_limit(MEMORY_EXTRA);

    $sibname = get_string('pluginname', 'mod_' . $sibling);

    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('migrateheadingrun', 'mod_exelearning', $sibname));

    $progress = new progress_bar();
    $progress->create();
    $results = import_service::migrate_all($sibling, $progress);

    // Tally and render the per-activity outcome.
    $counts = ['migrated' => 0, 'alreadymigrated' => 0, 'nosource' => 0, 'error' => 0];
    $table = new html_table();
    $table->head = [
        get_string('course'),
        get_string('name'),
        get_string('status'),
    ];
    foreach ($results as $result) {
        $counts[$result->status] = ($counts[$result->status] ?? 0) + 1;
        $statuslabel = get_string('migratestatus_' . $result->status, 'mod_exelearning');
        if ($result->status === 'error' && $result->error !== '') {
            $statuslabel .= ': ' . s($result->error);
        }
        $table->data[] = [s($result->coursename), s($result->name), $statuslabel];
    }

    echo $OUTPUT->notification(
        get_string('migratesummary', 'mod_exelearning', (object) $counts),
        \core\output\notification::NOTIFY_SUCCESS
    );
    if (!empty($results)) {
        echo html_writer::table($table);
    }
    echo $OUTPUT->single_button($baseurl, get_string('back'), 'get');
    echo $OUTPUT->footer();
    exit;
}

// Overview: warning + per-sibling counts and migrate buttons.
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('migratetitle', 'mod_exelearning'));

if (empty($available)) {
    echo $OUTPUT->notification(
        get_string('migratenosiblings', 'mod_exelearning'),
        \core\output\notification::NOTIFY_INFO
    );
} else {
    echo $OUTPUT->notification(
        get_string('migratewarning', 'mod_exelearning'),
        \core\output\notification::NOTIFY_WARNING
    );

    foreach ($available as $mod => $count) {
        $sibname = get_string('pluginname', 'mod_' . $mod);
        echo html_writer::tag('h3', $sibname);
        echo html_writer::tag('p', get_string(
            'migratecount',
            'mod_exelearning',
            (object) ['count' => $count, 'sibling' => $sibname]
        ));

        if ($mod === 'exescorm') {
            echo html_writer::div(
                get_string('migratescormnote', 'mod_exelearning'),
                'alert alert-info'
            );
        }

        if ($count > 0) {
            $runurl = new moodle_url($baseurl, [
                'sibling' => $mod,
                'confirm' => 1,
                'sesskey' => sesskey(),
            ]);
            echo $OUTPUT->single_button(
                $runurl,
                get_string('migratebutton', 'mod_exelearning', $sibname),
                'post'
            );
        }
    }
}

echo $OUTPUT->footer();
