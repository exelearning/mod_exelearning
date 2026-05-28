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
 * Attempts report for mod_exelearning (DEC-0007).
 *
 * Teacher-facing table of users × attempts × items, modelled on the
 * mod_h5pactivity report.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/exelearning/lib.php');

$id = required_param('id', PARAM_INT); // Course module id.

$cm = get_coursemodule_from_id('exelearning', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$exelearning = $DB->get_record('exelearning', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/exelearning:viewreport', $context);

$PAGE->set_url('/mod/exelearning/report.php', ['id' => $cm->id]);
$PAGE->set_title(format_string($exelearning->name) . ': ' . get_string('attemptsreport', 'mod_exelearning'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

// Borrar intento (DEC-0007 fase 2): elimina todas las filas de un (userid,
// attempt) y recalcula la nota del alumno desde el histórico restante.
$deleteuser = optional_param('deleteuser', 0, PARAM_INT);
$deleteattempt = optional_param('deleteattempt', 0, PARAM_INT);
if ($deleteuser && $deleteattempt && confirm_sesskey()
        && has_capability('mod/exelearning:deleteattempt', $context)) {
    $DB->delete_records('exelearning_attempt', [
        'exelearningid' => $exelearning->id,
        'userid'        => $deleteuser,
        'attempt'       => $deleteattempt,
    ]);
    exelearning_recalculate_user_grades($exelearning, $deleteuser);
    redirect(new moodle_url('/mod/exelearning/report.php', ['id' => $cm->id]),
            get_string('attemptdeleted', 'mod_exelearning'), null,
            \core\output\notification::NOTIFY_SUCCESS);
}

$candelete = has_capability('mod/exelearning:deleteattempt', $context);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('attemptsreport', 'mod_exelearning'));

// Mapa itemnumber → nombre legible (overall + iDevices).
$itemnames = [0 => get_string('report_overall', 'mod_exelearning')];
$gradeitems = $DB->get_records('exelearning_grade_item',
        ['exelearningid' => $exelearning->id], 'itemnumber ASC', 'itemnumber, name, idevicetype');
foreach ($gradeitems as $gi) {
    $itemnames[(int) $gi->itemnumber] = format_string($gi->name);
}

$attempts = $DB->get_records('exelearning_attempt',
        ['exelearningid' => $exelearning->id], 'userid ASC, attempt ASC, itemnumber ASC');

if (empty($attempts)) {
    echo $OUTPUT->notification(get_string('noattempts', 'mod_exelearning'),
            \core\output\notification::NOTIFY_INFO);
    echo $OUTPUT->footer();
    die;
}

// Cargar usuarios implicados.
$userids = [];
foreach ($attempts as $a) {
    $userids[$a->userid] = true;
}
$users = $DB->get_records_list('user', 'id', array_keys($userids));

$table = new html_table();
$table->head = [
    get_string('report_user', 'mod_exelearning'),
    get_string('report_attempt', 'mod_exelearning'),
    get_string('report_item', 'mod_exelearning'),
    get_string('report_score', 'mod_exelearning'),
    get_string('report_status', 'mod_exelearning'),
    get_string('report_date', 'mod_exelearning'),
];
if ($candelete) {
    $table->head[] = get_string('report_actions', 'mod_exelearning');
}
$table->attributes['class'] = 'generaltable';

foreach ($attempts as $a) {
    $username = isset($users[$a->userid])
            ? fullname($users[$a->userid]) : ('#' . $a->userid);
    $itemlabel = $itemnames[(int) $a->itemnumber]
            ?? ('#' . $a->itemnumber);
    $score = format_float((float) $a->rawscore, 2) . ' / ' . format_float((float) $a->maxscore, 2);
    $row = [
        s($username),
        $a->attempt,
        $itemlabel,
        $score,
        s($a->status),
        userdate($a->timemodified),
    ];
    if ($candelete) {
        // El enlace de borrado sólo en la fila overall (itemnumber=0) para no
        // repetirlo por cada iDevice; borra el intento completo del alumno.
        if ((int) $a->itemnumber === 0) {
            $delurl = new moodle_url('/mod/exelearning/report.php', [
                'id'            => $cm->id,
                'deleteuser'    => $a->userid,
                'deleteattempt' => $a->attempt,
                'sesskey'       => sesskey(),
            ]);
            $row[] = html_writer::link($delurl,
                    get_string('deleteattempt', 'mod_exelearning'),
                    ['class' => 'btn btn-sm btn-outline-danger']);
        } else {
            $row[] = '';
        }
    }
    $table->data[] = $row;
}

echo html_writer::table($table);
echo $OUTPUT->footer();
