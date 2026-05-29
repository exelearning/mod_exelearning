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
 * mod_exelearning tracking endpoint (SCORM bridge).
 *
 * Receives CMI pairs from the SCORM 1.2 shim that lives in `view.php` and
 * translates them into `grade_update()` calls in the Moodle gradebook. v1 only
 * manages the canonical grade item (itemnumber=0); per-iDevice routing will
 * arrive with the full xAPI bridge.
 *
 * Endpoint: POST with sesskey + JSON `{ id: <cmid>, cmi: { "cmi.core.score.raw": "85", ... } }`.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/exelearning/lib.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->libdir . '/completionlib.php');

$cmid = required_param('id', PARAM_INT);
$mode = optional_param('mode', 'grading', PARAM_ALPHA);
require_sesskey();

$cm = get_coursemodule_from_id('exelearning', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$exelearning = $DB->get_record('exelearning', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, false, $cm);
$context = context_module::instance($cm->id);

// Preview mode (DEC-0006): only when the caller has management capability.
// A regular student who changes ?mode=preview falls back to grading silently.
$ispreview = ($mode === 'preview')
        && has_capability('moodle/course:manageactivities', $context);
if (!$ispreview) {
    require_capability('mod/exelearning:savetrack', $context);
}

$raw = file_get_contents('php://input');
$payload = $raw ? json_decode($raw, true) : null;
if (!is_array($payload) || !isset($payload['cmi']) || !is_array($payload['cmi'])) {
    throw new \moodle_exception('invalidparameter', 'error');
}

// V1: take the `cmi.core.score.raw` (SCORM 1.2) or `cmi.score.raw` (SCORM 2004)
// and apply it to the canonical grade item (itemnumber=0). Multi-iDevice via
// xAPI will arrive in TAREA-008.
$cmi = $payload['cmi'];
// Page-load session token (DEC-0007): groups the auto-commits from a single
// view.php page load into one attempt.
$sessiontoken = isset($payload['session'])
        ? clean_param((string) $payload['session'], PARAM_ALPHANUMEXT) : '';
$rawscore = $cmi['cmi.core.score.raw'] ?? $cmi['cmi.score.raw'] ?? null;
$maxscore = $cmi['cmi.core.score.max'] ?? $cmi['cmi.score.max'] ?? null;
$status   = $cmi['cmi.core.lesson_status'] ?? $cmi['cmi.completion_status'] ?? null;

if ($rawscore === null || $rawscore === '') {
    // Nothing to persist yet; just acknowledge.
    echo json_encode(['ok' => true, 'noop' => true]);
    die;
}

// Normalise to the grade item scale (instance grademax).
$score = (float) $rawscore;
if ($maxscore !== null && (float) $maxscore > 0) {
    $score = ($score / (float) $maxscore) * (float) ($exelearning->grademax ?? 100);
}

// Preview mode: do NOT update the gradebook; only acknowledge (DEC-0006).
if ($ispreview) {
    echo json_encode(['ok' => true, 'mode' => 'preview', 'rawscore' => $score, 'status' => $status]);
    die;
}

// Per-iDevice routing from cmi.suspend_data.
// eXeLearning v4 serialises `{N}. "{title}"; Score: {S}%; Weight: {W}%`
// separated by ".\t" where N=DOM index+1. This matches our itemnumber
// assigned by the order in content.xml (classes/local/package.php).
$suspend = $cmi['cmi.suspend_data'] ?? '';
$peritem = [];
if (is_string($suspend) && $suspend !== '') {
    foreach (preg_split('~\.\t~', $suspend) as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (
            preg_match(
                '~^(\d+)\.\s"([^"]*)";\s[^:]+:\s([\d.]+)%;\s[^:]+:\s([\d.]+)%\.?$~',
                $line,
                $m
            )
        ) {
            $peritem[(int) $m[1]] = [
                'title'    => $m[2],
                'scorepct' => (float) $m[3], // 0..100
                'weighted' => (float) $m[4],
            ];
        }
    }
}

$grademax = (float) ($exelearning->grademax ?? 100);
$grademethod = (int) ($exelearning->grademethod ?? \mod_exelearning\local\attempts::GRADE_HIGHEST);
$grademodel = (int) ($exelearning->grademodel ?? EXELEARNING_GRADEMODEL_PERITEM);
$itemdetailsbase = [
    'gradetype' => GRADE_TYPE_VALUE,
    'grademax'  => $exelearning->grademax ?? 100,
    'grademin'  => $exelearning->grademin ?? 0,
    'display'   => (int) ($exelearning->gradedisplaytype ?? GRADE_DISPLAY_TYPE_DEFAULT),
];

// Resolve the attempt number (one per page load).
$attempt = \mod_exelearning\local\attempts::resolve_attempt_number(
    $exelearning->id,
    $USER->id,
    $sessiontoken
);

// Attempt limit (DEC-0007 phase 2): if this page load would open a new attempt
// and the student has already exhausted maxattempt, reject without saving.
$maxattempt = (int) ($exelearning->maxattempt ?? 0);
if ($maxattempt > 0) {
    $sessionknown = ($sessiontoken !== '') && $DB->record_exists(
        'exelearning_attempt',
        ['exelearningid' => $exelearning->id, 'userid' => $USER->id, 'sessiontoken' => $sessiontoken]
    );
    $priorcount = \mod_exelearning\local\attempts::count_user_attempts($exelearning->id, $USER->id);
    if (!$sessionknown && $priorcount >= $maxattempt) {
        echo json_encode([
            'ok'      => false,
            'error'   => 'maxattemptsreached',
            'attempts' => $priorcount,
            'maxattempt' => $maxattempt,
        ]);
        die;
    }
}

// 1) Attempts + aggregated grade per iDevice (itemnumber > 0).
$persaved = [];
if ($peritem) {
    $rows = $DB->get_records(
        'exelearning_grade_item',
        ['exelearningid' => $exelearning->id, 'deleted' => 0],
        'itemnumber ASC',
        'itemnumber, name, objectid'
    );
    foreach ($peritem as $itemnumber => $info) {
        if (!isset($rows[$itemnumber])) {
            continue;
        }
        $rawitem = ($info['scorepct'] / 100.0) * $grademax;
        \mod_exelearning\local\attempts::record_item(
            $exelearning->id,
            $USER->id,
            $attempt,
            (int) $itemnumber,
            $rawitem,
            $grademax,
            'completed',
            $sessiontoken
        );
        // Gradebook grade = aggregation of attempts according to grademethod.
        $scaled = \mod_exelearning\local\attempts::aggregate_scaled(
            $exelearning->id,
            $USER->id,
            (int) $itemnumber,
            $grademethod
        );
        $finalitem = ($scaled === null) ? $rawitem : ($scaled * $grademax);
        // In "overall only" mode per-iDevice columns are not published (DEC-0008),
        // but the attempt IS recorded for the report.
        if ($grademodel !== EXELEARNING_GRADEMODEL_OVERALL) {
            grade_update(
                'mod/exelearning',
                $exelearning->course,
                'mod',
                'exelearning',
                $exelearning->id,
                $itemnumber,
                (object) ['userid' => $USER->id, 'rawgrade' => $finalitem],
                $itemdetailsbase + ['itemname' => $rows[$itemnumber]->name]
            );
        }
        $persaved[$itemnumber] = $finalitem;
    }
}

// 2) Attempt + aggregated overall grade (itemnumber=0).
$overallstatus = in_array($status, ['passed', 'failed', 'completed', 'incomplete'], true)
        ? $status : 'completed';
\mod_exelearning\local\attempts::record_item(
    $exelearning->id,
    $USER->id,
    $attempt,
    0,
    $score,
    $grademax,
    $overallstatus,
    $sessiontoken
);
$scaledoverall = \mod_exelearning\local\attempts::aggregate_scaled(
    $exelearning->id,
    $USER->id,
    0,
    $grademethod
);
$finaloverall = ($scaledoverall === null) ? $score : ($scaledoverall * $grademax);

$grade = (object) [
    'userid'    => $USER->id,
    'rawgrade'  => $finaloverall,
    // DEC-0008: do not expose the raw CMI string as feedback; the functional
    // status lives in mdl_exelearning_attempt.status.
    'feedback'  => null,
];

// In "per-iDevice only" mode the overall column does not exist (DEC-0008).
if ($grademodel === EXELEARNING_GRADEMODEL_PERITEM) {
    $result = GRADE_UPDATE_OK;
} else {
    $result = grade_update(
        'mod/exelearning',
        $exelearning->course,
        'mod',
        'exelearning',
        $exelearning->id,
        0,
        $grade,
        $itemdetailsbase + [
                'itemname'  => clean_param($exelearning->name, PARAM_NOTAGS),
        ]
    );
}

// Recalculate completion: with "require passing grade" (completionpassgrade,
// SCORM style) Moodle marks the activity complete when the passing grade is
// reached. Force re-evaluation after saving the grade.
$completion = new completion_info($course);
if ($completion->is_enabled($cm)) {
    $completion->update_state($cm, COMPLETION_UNKNOWN, $USER->id);
}

echo json_encode([
    'ok' => $result === GRADE_UPDATE_OK,
    'attempt' => $attempt,
    'rawscore' => $finaloverall,
    'status' => $status,
    'peritem' => $persaved,
]);
