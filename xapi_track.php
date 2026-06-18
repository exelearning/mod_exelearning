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
 * mod_exelearning xAPI tracking endpoint (DEC-0064).
 *
 * The xAPI counterpart of `track.php`. The `js/xapi_listener.js` listener in `view.php`
 * receives `exe-xapi-statement` postMessages from the package iframe, validates the
 * origin, and POSTs each statement here. This script does the web-specific part —
 * sesskey + capability authentication and the JSON response — and hands the statement
 * to the shared, unit-tested {@see \mod_exelearning\local\xapi\ingestor::ingest()},
 * which ignores the statement actor (grading is attributed to $USER), validates the
 * statement (DEC-0063) and routes it through the existing grade pipeline (DEC-0032).
 *
 * Endpoint: POST with sesskey + JSON `{ id: <cmid>, statement: {...}, registration: "<token>" }`.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/exelearning/lib.php');

$cmid = required_param('id', PARAM_INT);
$mode = optional_param('mode', 'grading', PARAM_ALPHA);
require_sesskey();

$cm = get_coursemodule_from_id('exelearning', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$exelearning = $DB->get_record('exelearning', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, false, $cm);
$context = context_module::instance($cm->id);

// Preview mode (DEC-0006): only when the caller has management capability. A regular
// student who changes ?mode=preview falls back to grading silently, like track.php.
$ispreview = ($mode === 'preview')
        && has_capability('moodle/course:manageactivities', $context);
if (!$ispreview) {
    require_capability('mod/exelearning:savetrack', $context);
}

$raw = file_get_contents('php://input');
$payload = $raw ? json_decode($raw, true) : null;
if (!is_array($payload) || !isset($payload['statement'])) {
    throw new \moodle_exception('invalidparameter', 'error');
}
// The listener posts the statement as a nested object; tolerate a JSON-encoded string too.
$statement = is_array($payload['statement'])
        ? $payload['statement']
        : json_decode((string) $payload['statement'], true);
if (!is_array($statement)) {
    throw new \moodle_exception('invalidparameter', 'error');
}
$registration = isset($payload['registration'])
        ? clean_param((string) $payload['registration'], PARAM_ALPHANUMEXT) : '';

// All validation (DEC-0063), normalisation, objectid routing, idempotency, attempt
// recording, grading and completion live in the shared, unit-tested ingestor.
$result = \mod_exelearning\local\xapi\ingestor::ingest(
    $exelearning,
    $course,
    $cm,
    (int) $USER->id,
    $statement,
    $registration,
    $ispreview
);

// The attempt cap is a conflict, not a server error: signal it with HTTP 409 as the
// listener expects (the JSON body carries the details either way).
if (!empty($result['error']) && $result['error'] === 'maxattemptsreached') {
    http_response_code(409);
}

echo json_encode($result);
