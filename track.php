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

// V1: take the `cmi.core.score.raw` (SCORM 1.2) or `cmi.score.raw` (SCORM 2004)
// and apply it to the canonical grade item (itemnumber=0). Multi-iDevice via
// xAPI will arrive in TAREA-008.
$cmi = $payload['cmi'];
// Token de sesión de página (DEC-0007): agrupa los auto-commits de una misma
// carga de view.php en un único intento.
$sessiontoken = isset($payload['session'])
        ? clean_param((string) $payload['session'], PARAM_ALPHANUMEXT) : '';
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

// Per-iDevice routing desde cmi.suspend_data.
// eXeLearning v4 serializa `{N}. "{title}"; Puntuación: {S}%; Peso: {W}%`
// separados por ".\t" donde N=index DOM+1. Eso coincide con nuestro itemnumber
// asignado por el orden en content.xml (classes/local/package.php).
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

// Resolver el número de intento (uno por carga de página).
$attempt = \mod_exelearning\local\attempts::resolve_attempt_number(
    $exelearning->id,
    $USER->id,
    $sessiontoken
);

// Límite de intentos (DEC-0007 fase 2): si esta carga de página inaugura un
// intento nuevo y el alumno ya agotó maxattempt, rechazar sin grabar.
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

// 1) Intentos + nota agregada por iDevice (itemnumber > 0).
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
        // Nota del libro = agregación de los intentos según grademethod.
        $scaled = \mod_exelearning\local\attempts::aggregate_scaled(
            $exelearning->id,
            $USER->id,
            (int) $itemnumber,
            $grademethod
        );
        $finalitem = ($scaled === null) ? $rawitem : ($scaled * $grademax);
        // En modo "sólo overall" no se publican columnas por iDevice (DEC-0008),
        // pero el intento SÍ se registra para el report.
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

// 2) Intento + nota agregada del overall (itemnumber=0).
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
    // DEC-0008: no exponer la cadena CMI cruda como retroalimentación; el
    // estado funcional vive en mdl_exelearning_attempt.status.
    'feedback'  => null,
];

// En modo "sólo por iDevice" no existe columna overall (DEC-0008).
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

// Recalcular finalización: con "exigir nota para aprobar" (completionpassgrade,
// estilo SCORM) Moodle marca completada la actividad al alcanzar la nota de
// aprobado. Forzamos la reevaluación tras grabar la nota.
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
