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
 * Recibe pares CMI desde el shim SCORM 1.2 que vive en `view.php` y los
 * traduce a `grade_update()` en Moodle gradebook. v1 sólo gestiona el
 * grade item canónico (itemnumber=0); el routing per-iDevice llegará con el
 * bridge xAPI completo.
 *
 * Endpoint: POST con sesskey + JSON `{ id: <cmid>, cmi: { "cmi.core.score.raw": "85", ... } }`.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE Educación
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/exelearning/lib.php');
require_once($CFG->libdir . '/gradelib.php');

$cmid = required_param('id', PARAM_INT);
$mode = optional_param('mode', 'grading', PARAM_ALPHA);
require_sesskey();

$cm = get_coursemodule_from_id('exelearning', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$exelearning = $DB->get_record('exelearning', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, false, $cm);
$context = context_module::instance($cm->id);

// Modo preview (DEC-0006): sólo si quien llama tiene capability de gestión.
// Alumno común que cambie ?mode=preview cae a grading silenciosamente.
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

// v1: tomamos el `cmi.core.score.raw` (SCORM 1.2) o `cmi.score.raw` (SCORM 2004)
// y lo aplicamos al grade item canónico (itemnumber=0). Multi-iDevice via
// xAPI llegará en TAREA-008.
$cmi = $payload['cmi'];
$rawscore = $cmi['cmi.core.score.raw'] ?? $cmi['cmi.score.raw'] ?? null;
$maxscore = $cmi['cmi.core.score.max'] ?? $cmi['cmi.score.max'] ?? null;
$status   = $cmi['cmi.core.lesson_status'] ?? $cmi['cmi.completion_status'] ?? null;

if ($rawscore === null || $rawscore === '') {
    // No hay nada que persistir aún; sólo ack.
    echo json_encode(['ok' => true, 'noop' => true]);
    die;
}

// Normalizar a la escala del grade item (grademax de la instancia).
$score = (float) $rawscore;
if ($maxscore !== null && (float) $maxscore > 0) {
    $score = ($score / (float) $maxscore) * (float) ($exelearning->grademax ?? 100);
}

// Modo preview: NO actualizamos gradebook; sólo ack (DEC-0006).
if ($ispreview) {
    echo json_encode(['ok' => true, 'mode' => 'preview', 'rawscore' => $score, 'status' => $status]);
    die;
}

$grade = (object) [
    'userid'    => $USER->id,
    'rawgrade'  => $score,
    'feedback'  => $status ? 'lesson_status=' . s($status) : null,
];

$result = grade_update('mod/exelearning', $exelearning->course, 'mod',
        'exelearning', $exelearning->id, 0, $grade, [
            'itemname'  => clean_param($exelearning->name, PARAM_NOTAGS),
            'gradetype' => GRADE_TYPE_VALUE,
            'grademax'  => $exelearning->grademax ?? 100,
            'grademin'  => $exelearning->grademin ?? 0,
            'display'   => (int) ($exelearning->gradedisplaytype ?? GRADE_DISPLAY_TYPE_DEFAULT),
        ]);

echo json_encode([
    'ok' => $result === GRADE_UPDATE_OK,
    'rawscore' => $score,
    'status' => $status,
]);
