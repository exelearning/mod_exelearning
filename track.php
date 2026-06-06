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
 * translates them into `grade_update()` calls in the Moodle gradebook. It manages
 * the canonical grade item (itemnumber=0) and routes per-iDevice scores to their
 * columns by stable objectid (DEC-0017), falling back to the page-local index N in
 * cmi.suspend_data when no objectid map is supplied by the shim.
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

// Take the `cmi.core.score.raw` (SCORM 1.2) or `cmi.score.raw` (SCORM 2004) and
// apply it to the canonical grade item (itemnumber=0). Per-iDevice routing is
// handled separately below from the objectid map / cmi.suspend_data.
$cmi = $payload['cmi'];
// Page-load session token (DEC-0007): groups the auto-commits from a single
// view.php page load into one attempt.
$sessiontoken = isset($payload['session'])
        ? substr(clean_param((string) $payload['session'], PARAM_ALPHANUMEXT), 0, 255) : '';
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
// Clamp to the configured grade range so an out-of-range CMI value (a score
// above max, or a negative one) cannot be persisted as the attempt rawscore.
$score = max(
    (float) ($exelearning->grademin ?? 0),
    min((float) ($exelearning->grademax ?? 100), $score)
);

// Preview mode: do NOT update the gradebook; only acknowledge (DEC-0006).
if ($ispreview) {
    echo json_encode(['ok' => true, 'mode' => 'preview', 'rawscore' => $score, 'status' => $status]);
    die;
}

// Per-iDevice routing.
//
// Preferred path (DEC-0017): the view.php SCORM shim reads the iframe DOM at each
// scoring event and sends `itemscores`, a map of stable iDevice objectid =>
// {scorepct, weighted, title}. Routing by objectid is collision-free across pages.
//
// Legacy fallback: when no objectid map is present (old cached page, DOM read
// failed) we still parse cmi.suspend_data and route by its page-local index N.
// eXeLearning v4 serialises `{N}. "{title}"; Score: {S}%; Weight: {W}%` separated
// by ".\t" where N is the per-page DOM index — which only matches our itemnumber
// for a single-page package whose iDevices are all gradable (see RIE-007).
$itemscores = (isset($payload['itemscores']) && is_array($payload['itemscores']))
        ? $payload['itemscores'] : [];
// Defensive cap: a well-formed package emits one entry per gradable iDevice, so a
// map far larger than any real package is a malformed/abusive payload. Drop it
// rather than iterate it (the legacy suspend_data path still applies if present).
if (count($itemscores) > 1000) {
    debugging(
        'mod_exelearning: itemscores map exceeded the sane size cap and was ignored.',
        DEBUG_DEVELOPER
    );
    $itemscores = [];
}
$suspend = $cmi['cmi.suspend_data'] ?? '';
$peritem = is_string($suspend)
        ? \mod_exelearning\local\track::parse_suspend_data($suspend) : [];
if (is_string($suspend) && $suspend !== '' && $peritem === [] && $itemscores === []) {
    // Non-empty suspend_data that yields no parsed items and no objectid map
    // usually signals a format the regex does not accept (locale decimal commas,
    // an embedded quote in a title, or a producer change). Surface it for
    // developers instead of silently recording no per-iDevice grades.
    debugging(
        'mod_exelearning: cmi.suspend_data was non-empty but no per-iDevice '
            . 'results could be parsed from it.',
        DEBUG_DEVELOPER
    );
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
        http_response_code(409);
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
if ($itemscores !== []) {
    // Preferred: route by the stable objectid map captured client-side. This is
    // correct for multi-page packages where the page-local N collides (DEC-0017).
    $persaved = \mod_exelearning\local\track::apply_item_scores(
        $exelearning,
        $USER->id,
        $attempt,
        $itemscores,
        $sessiontoken
    );
} else if ($peritem) {
    // Legacy fallback: route by the page-local N from cmi.suspend_data. Only
    // reliable for single-page packages (see RIE-007); kept for old cached pages
    // and when the DOM objectid map could not be captured.
    $persaved = \mod_exelearning\local\track::apply_legacy_peritem(
        $exelearning,
        $USER->id,
        $attempt,
        $peritem,
        $sessiontoken
    );
}

// 2) Attempt + aggregated overall grade (itemnumber=0).
//
// DEC-0018 (RIE-007 residual): when the shim supplied the objectid map, derive the
// overall from the per-iDevice scores instead of trusting cmi.core.score.raw. The
// producer's getFinalScore() is corrupt under a multi-page suspend_data collision,
// but the per-item objectid scores are not. For a single-page package the weighted
// mean equals the producer's overall, so the verified single-page path is unchanged.
// Without an objectid map we keep using the CMI score (legacy / single-page).
if ($itemscores !== []) {
    $overallpct = \mod_exelearning\local\track::recompute_overall_pct($itemscores);
    if ($overallpct !== null) {
        $recomputed = max(
            (float) ($exelearning->grademin ?? 0),
            min($grademax, ($overallpct / 100.0) * $grademax)
        );
        // Surface a producer<->plugin divergence (i.e. a likely collision) for
        // developers without changing what we persist.
        if (abs($recomputed - $score) > 0.01) {
            debugging(
                'mod_exelearning: overall recomputed from itemscores ('
                    . $recomputed . ') diverges from cmi.core.score.raw (' . $score
                    . '); using the recomputed value (DEC-0018).',
                DEBUG_DEVELOPER
            );
        }
        $score = $recomputed;
    }
}
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

// Always publish the aggregated overall grade. In PERITEM mode the grade item is
// hidden and exists only so Moodle's completionpassgrade rule can evaluate the
// pass/fail result without adding an extra visible gradebook column (TAREA-011).
$result = grade_update(
    'mod/exelearning',
    $exelearning->course,
    'mod',
    'exelearning',
    $exelearning->id,
    0,
    $grade,
    $itemdetailsbase + [
            'itemname' => clean_param($exelearning->name, PARAM_NOTAGS),
            'hidden'   => ($grademodel === EXELEARNING_GRADEMODEL_PERITEM) ? 1 : 0,
    ]
);

// In PERITEM the overall is hidden; exclude it from aggregation so it neither
// double-counts nor blanks the student's total (DEC-0035). No-op in OVERALL mode.
exelearning_exclude_overall_grade($exelearning, $USER->id);

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
