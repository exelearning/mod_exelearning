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
 * mod_exelearning per-course index.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);   // Course id.

$course = $DB->get_record('course', ['id' => $id], '*', MUST_EXIST);
require_login($course);

$context = context_course::instance($course->id);

$event = \mod_exelearning\event\course_module_instance_list_viewed::create(['context' => $context]);
$event->add_record_snapshot('course', $course);
$event->trigger();

$PAGE->set_url('/mod/exelearning/index.php', ['id' => $id]);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(format_string($course->shortname) . ': ' . get_string('modulenameplural', 'mod_exelearning'));
$PAGE->set_heading(format_string($course->fullname));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('modulenameplural', 'mod_exelearning'));

$instances = get_all_instances_in_course('exelearning', $course);
if (empty($instances)) {
    notice(
        get_string('noexelearningactivities', 'mod_exelearning'),
        new moodle_url('/course/view.php', ['id' => $course->id])
    );
}

$table = new html_table();
$table->head = [get_string('name'), get_string('intro', 'mod_exelearning')];
foreach ($instances as $instance) {
    $url = new moodle_url('/mod/exelearning/view.php', ['id' => $instance->coursemodule]);
    $table->data[] = [
        html_writer::link($url, format_string($instance->name)),
        format_module_intro('exelearning', $instance, $instance->coursemodule),
    ];
}
echo html_writer::table($table);

echo $OUTPUT->footer();
