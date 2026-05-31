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

// Honour separate groups: a teacher without moodle/site:accessallgroups must
// only see and manage attempts of students in their own visible group (as
// view.php's participation summary already does). $restrictusers === null means
// no restriction (NOGROUPS / VISIBLEGROUPS / accessallgroups); an array (which
// may be empty) limits the report to those user ids.
$groupmode = groups_get_activity_groupmode($cm, $course);
$currentgroup = 0;
$restrictusers = null;
if ($groupmode != NOGROUPS) {
    $currentgroup = groups_get_activity_group($cm, true);
    if ($groupmode == SEPARATEGROUPS && !has_capability('moodle/site:accessallgroups', $context)) {
        $restrictusers = empty($currentgroup)
            ? []
            : array_map('intval', array_keys(get_enrolled_users($context, '', (int) $currentgroup, 'u.id')));
    }
}

$PAGE->set_url('/mod/exelearning/report.php', ['id' => $cm->id]);
$PAGE->set_title(format_string($exelearning->name) . ': ' . get_string('attemptsreport', 'mod_exelearning'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

// Delete attempt (DEC-0007 phase 2): removes every row of a (userid, attempt)
// pair and recalculates the student's grade from the remaining history.
$deleteuser = optional_param('deleteuser', 0, PARAM_INT);
$deleteattempt = optional_param('deleteattempt', 0, PARAM_INT);
if (
    $deleteuser && $deleteattempt && confirm_sesskey()
        && has_capability('mod/exelearning:deleteattempt', $context)
) {
    // Separate groups: refuse to delete attempts of out-of-group students.
    if ($restrictusers !== null && !in_array((int) $deleteuser, $restrictusers, true)) {
        throw new \moodle_exception('attemptnotingroup', 'mod_exelearning');
    }
    // Delete and recalculate atomically: if recalculation failed after the
    // delete committed, the gradebook would keep the deleted attempt's grade.
    $transaction = $DB->start_delegated_transaction();
    $DB->delete_records('exelearning_attempt', [
        'exelearningid' => $exelearning->id,
        'userid'        => $deleteuser,
        'attempt'       => $deleteattempt,
    ]);
    exelearning_recalculate_user_grades($exelearning, $deleteuser);
    $transaction->allow_commit();
    redirect(
        new moodle_url('/mod/exelearning/report.php', ['id' => $cm->id]),
        get_string('attemptdeleted', 'mod_exelearning'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$candelete = has_capability('mod/exelearning:deleteattempt', $context);

// The report reflects the grademodel (DEC-0008): in "per iDevice only" the
// Overall row (itemnumber=0) is hidden; in "overall only" the per-iDevice rows
// are hidden. The internal history (exelearning_attempt) keeps recording both,
// so this only affects presentation, not the data nor the grade recalculation.
$grademodel = (int) ($exelearning->grademodel ?? EXELEARNING_GRADEMODEL_PERITEM);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('attemptsreport', 'mod_exelearning'));

// Group selector: lets a teacher switch between the groups they may see. In
// separate-groups mode the options are limited to the teacher's own groups.
if ($groupmode != NOGROUPS) {
    groups_print_activity_menu($cm, $PAGE->url);
}

// Map itemnumber -> human-readable name (overall + iDevices).
$itemnames = [0 => get_string('report_overall', 'mod_exelearning')];
$gradeitems = $DB->get_records(
    'exelearning_grade_item',
    ['exelearningid' => $exelearning->id],
    'itemnumber ASC',
    'itemnumber, name, idevicetype'
);
foreach ($gradeitems as $gi) {
    $itemnames[(int) $gi->itemnumber] = format_string($gi->name);
}

if ($restrictusers === null) {
    $attempts = $DB->get_records(
        'exelearning_attempt',
        ['exelearningid' => $exelearning->id],
        'userid ASC, attempt ASC, itemnumber ASC'
    );
} else if ($restrictusers === []) {
    // The teacher belongs to no visible group: they see no attempts.
    $attempts = [];
} else {
    [$insql, $inparams] = $DB->get_in_or_equal($restrictusers, SQL_PARAMS_NAMED, 'ru');
    $attempts = $DB->get_records_select(
        'exelearning_attempt',
        "exelearningid = :exeid AND userid $insql",
        ['exeid' => $exelearning->id] + $inparams,
        'userid ASC, attempt ASC, itemnumber ASC'
    );
}

if (empty($attempts)) {
    echo $OUTPUT->notification(
        get_string('noattempts', 'mod_exelearning'),
        \core\output\notification::NOTIFY_INFO
    );
    echo $OUTPUT->footer();
    die;
}

// Load the users involved.
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

// Whether a row matches the active model (DEC-0008: peritem shows
// itemnumber>0; overall shows itemnumber=0).
$matchesmode = function (int $itemnumber) use ($grademodel): bool {
    return ($grademodel === EXELEARNING_GRADEMODEL_OVERALL)
            ? ($itemnumber === 0)
            : ($itemnumber > 0);
};

// Pre-pass: flag which (user, attempt) pairs have at least one row matching the
// model. If an attempt has none (e.g. in peritem, an attempt that only recorded
// the overall, with no iDevices), it is shown anyway as a fallback so it does
// not disappear from the report nor become impossible to delete.
$grouphasmatch = [];
foreach ($attempts as $a) {
    $key = $a->userid . '-' . $a->attempt;
    $grouphasmatch[$key] = ($grouphasmatch[$key] ?? false) || $matchesmode((int) $a->itemnumber);
}

// Track the first VISIBLE row of each (user, attempt) pair to anchor the
// "Delete attempt" button there: deleting removes the whole attempt, so it is
// shown once per attempt and works in any model (it does not rely on overall).
$deleteanchored = [];
foreach ($attempts as $a) {
    $itemnumber = (int) $a->itemnumber;
    $groupkey = $a->userid . '-' . $a->attempt;

    // Reflect the grademodel: hide rows that do not apply to the model, except
    // for attempts with no matching row at all (deletability fallback).
    if ($grouphasmatch[$groupkey] && !$matchesmode($itemnumber)) {
        continue;
    }

    $username = isset($users[$a->userid])
            ? fullname($users[$a->userid]) : ('#' . $a->userid);
    $itemlabel = $itemnames[$itemnumber]
            ?? ('#' . $itemnumber);
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
        if (!isset($deleteanchored[$groupkey])) {
            $deleteanchored[$groupkey] = true;
            $delurl = new moodle_url('/mod/exelearning/report.php', [
                'id'            => $cm->id,
                'deleteuser'    => $a->userid,
                'deleteattempt' => $a->attempt,
                'sesskey'       => sesskey(),
            ]);
            $row[] = html_writer::link(
                $delurl,
                get_string('deleteattempt', 'mod_exelearning'),
                ['class' => 'btn btn-sm btn-outline-danger']
            );
        } else {
            $row[] = '';
        }
    }
    $table->data[] = $row;
}

echo html_writer::table($table);
echo $OUTPUT->footer();
