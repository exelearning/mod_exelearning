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
 * mod_exelearning activity view.
 *
 * Renderiza el paquete eXeLearning v4 extraído dentro de un iframe que apunta
 * al `index.html` servido vía `pluginfile.php`, preservando la sidebar nativa
 * del paquete (técnica heredada de mod_exeweb, AN-001).
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/exelearning/lib.php');
require_once($CFG->libdir . '/completionlib.php');

$id = required_param('id', PARAM_INT);  // Course module id.
$mode = optional_param('mode', 'grading', PARAM_ALPHA); // grading | preview.

$cm = get_coursemodule_from_id('exelearning', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$exelearning = $DB->get_record('exelearning', ['id' => $cm->instance], '*', MUST_EXIST);

require_course_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/exelearning:view', $context);

// Modo preview/test SÓLO para usuarios con capability de gestión (DEC-0006).
// Un alumno sin permiso que cambia la URL a ?mode=preview cae a grading.
$canpreview = has_capability('moodle/course:manageactivities', $context);
if ($mode === 'preview' && !$canpreview) {
    $mode = 'grading';
}
if (!in_array($mode, ['grading', 'preview'], true)) {
    $mode = 'grading';
}

exelearning_view($exelearning, $course, $cm, $context);

$pageurlparams = ['id' => $cm->id];
if ($mode !== 'grading') {
    $pageurlparams['mode'] = $mode;
}
$PAGE->set_url('/mod/exelearning/view.php', $pageurlparams);
$PAGE->set_title(format_string($exelearning->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

// Inicializar el AMD del editor embebido sólo cuando el botón "Editar" se va a
// mostrar (gestor + editor instalado). El listener escucha clicks en
// [data-action="mod_exelearning/editor-open"].
$showeditorbutton = $canpreview && exelearning_embedded_editor_enabled();
if ($showeditorbutton) {
    $PAGE->requires->js_call_amd('mod_exelearning/editor_modal', 'init', []);
}

$fs = get_file_storage();
$mainfile = $fs->get_file($context->id, 'mod_exelearning', 'content',
        (int) $exelearning->revision, '/', 'index.html');

// Self-heal para subidas programáticas (p.ej. `addModule` del Moodle
// Playground): si el ELPX está en filearea 'package' pero el contenido no se
// extrajo o los grade items no se detectaron (porque esa vía no pasó por
// exelearning_add_instance), recuperarlo aquí. Idempotente: sólo actúa cuando
// falta algo, así que no penaliza la vista normal.
$haspackage = (exelearning_get_stored_package($context->id) !== null);
if ($haspackage) {
    if (!$mainfile) {
        exelearning_extract_stored_package($context->id, (int) $exelearning->revision);
        $mainfile = $fs->get_file($context->id, 'mod_exelearning', 'content',
                (int) $exelearning->revision, '/', 'index.html');
    }
    $hasgradable = $DB->record_exists_select('exelearning_grade_item',
            'exelearningid = ? AND deleted = 0 AND itemnumber > 0', [$exelearning->id]);
    if (!$hasgradable) {
        exelearning_sync_grade_items($exelearning->id, $context->id);
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($exelearning->name));

if (!empty($exelearning->intro)) {
    echo $OUTPUT->box(format_module_intro('exelearning', $exelearning, $cm->id),
            'generalbox', 'intro');
}

// Banner del modo preview + enlaces para alternar (DEC-0006).
if ($canpreview) {
    if ($mode === 'preview') {
        $exiturl = new moodle_url('/mod/exelearning/view.php', ['id' => $cm->id]);
        echo html_writer::start_div('alert alert-warning d-flex justify-content-between align-items-center mb-3');
        echo html_writer::tag('div',
                html_writer::tag('strong', get_string('previewmode', 'mod_exelearning')) . ' ' .
                get_string('previewmode_desc', 'mod_exelearning'));
        echo html_writer::link($exiturl->out(false),
                get_string('previewmode_exit', 'mod_exelearning'),
                ['class' => 'btn btn-sm btn-outline-secondary']);
        echo html_writer::end_div();
    } else {
        $previewurl = new moodle_url('/mod/exelearning/view.php',
                ['id' => $cm->id, 'mode' => 'preview']);
        echo html_writer::div(
                html_writer::link($previewurl->out(false),
                        get_string('previewmode_enter', 'mod_exelearning'),
                        ['class' => 'btn btn-sm btn-outline-secondary']),
                'mb-3');
    }
}

// Botón "Editar con eXeLearning": abre el editor embebido en un overlay/modal
// (gestionado por amd/src/editor_modal.js). Sólo para gestores y cuando hay un
// editor instalado. Los atributos data-* deben coincidir EXACTAMENTE con los
// que lee editor_modal.js::init()/open().
if ($showeditorbutton) {
    $editorurl = new moodle_url('/mod/exelearning/editor/index.php',
            ['id' => $cm->id, 'sesskey' => sesskey()]);
    $editorsaveurl = new moodle_url('/mod/exelearning/editor/save.php');
    $editorpackageurl = exelearning_get_package_url($exelearning, $context);
    echo html_writer::div(
            html_writer::tag('button',
                    '<i class="fa fa-pencil mr-1" aria-hidden="true"></i> '
                    . get_string('editwitheditor', 'mod_exelearning'),
                    [
                        'type' => 'button',
                        'class' => 'btn btn-sm btn-primary',
                        'data-action' => 'mod_exelearning/editor-open',
                        'data-cmid' => $cm->id,
                        'data-editorurl' => $editorurl->out(false),
                        'data-packageurl' => $editorpackageurl ? $editorpackageurl->out(false) : '',
                        'data-saveurl' => $editorsaveurl->out(false),
                        'data-sesskey' => sesskey(),
                        'data-activityname' => format_string($exelearning->name),
                    ]),
            'mb-3');
}

if (!$mainfile) {
    echo $OUTPUT->notification(
            get_string('packagenotfound', 'mod_exelearning'),
            \core\output\notification::NOTIFY_ERROR);
} else {
    $iframeurl = moodle_url::make_pluginfile_url(
            $context->id,
            'mod_exelearning',
            'content',
            (int) $exelearning->revision,
            '/',
            'index.html');
    // Lista de grade items detectados (para feedback rápido al profesor).
    $items = $DB->get_records('exelearning_grade_item',
            ['exelearningid' => $exelearning->id, 'deleted' => 0],
            'itemnumber ASC');
    if (has_capability('mod/exelearning:viewreport', $context) && !empty($items)) {
        echo html_writer::start_div('alert alert-info mb-3');
        echo html_writer::tag('strong', get_string('detecteditems', 'mod_exelearning')) . ' ';
        $labels = [];
        foreach ($items as $it) {
            $labels[] = '#' . $it->itemnumber . ' ' . s($it->idevicetype);
        }
        echo s(implode(' · ', $labels));
        echo html_writer::end_div();
    }
    // Resumen de participación + enlace al informe (DEC-0011 opción B, estilo
    // Tarea): un vistazo de "cuántos han contestado" para el profesor, sin
    // entrar al informe. Respeta grupos separados.
    if (has_capability('mod/exelearning:viewreport', $context)) {
        // Usuarios visibles para este profesor (respeta grupos separados).
        $currentgroup = groups_get_activity_group($cm, true);
        $enrolled = get_enrolled_users($context, 'mod/exelearning:savetrack',
                (int) $currentgroup, 'u.id');
        $userids = array_keys($enrolled);
        $summary = \mod_exelearning\local\attempts::participation_summary(
                $exelearning->id, $userids);

        $reporturl = new moodle_url('/mod/exelearning/report.php', ['id' => $cm->id]);
        echo html_writer::start_div('alert alert-info d-flex justify-content-between align-items-center mb-3');
        if ($summary['meanpercent'] === null) {
            $text = get_string('participation_summary', 'mod_exelearning',
                    (object) ['attempted' => $summary['attempted'], 'total' => $summary['total']]);
        } else {
            $text = get_string('participation_summary_mean', 'mod_exelearning',
                    (object) [
                        'attempted' => $summary['attempted'],
                        'total'     => $summary['total'],
                        'mean'      => format_float($summary['meanpercent'], 1),
                    ]);
        }
        echo html_writer::tag('span', $text);
        echo html_writer::link($reporturl,
                get_string('viewattemptsreport', 'mod_exelearning'),
                ['class' => 'btn btn-sm btn-outline-primary']);
        echo html_writer::end_div();
    }
    // Resumen de intentos para el alumno (DEC-0007 fase 2).
    if (!$canpreview) {
        $myattempts = $DB->get_records('exelearning_attempt', [
            'exelearningid' => $exelearning->id,
            'userid'        => $USER->id,
            'itemnumber'    => 0,
        ], 'attempt ASC');
        $used = count($myattempts);
        $maxattempt = (int) ($exelearning->maxattempt ?? 0);
        if ($used > 0 || $maxattempt > 0) {
            $label = ($maxattempt > 0)
                    ? get_string('attemptsofmax', 'mod_exelearning',
                            (object) ['used' => $used, 'max' => $maxattempt])
                    : get_string('attemptsused', 'mod_exelearning', $used);

            // Enriquecer con método de calificación + nota informada (DEC-0011
            // opción C pulida: lo útil de SCORM sin reproducir su tabla).
            $extras = [];
            if ($used > 0) {
                $grademethod = (int) ($exelearning->grademethod
                        ?? \mod_exelearning\local\attempts::GRADE_HIGHEST);
                $methodlabel = get_string(
                        \mod_exelearning\local\attempts::grademethod_stringkey($grademethod),
                        'mod_exelearning');
                $extras[] = get_string('grademethod', 'mod_exelearning') . ': ' . $methodlabel;

                $grademax = (float) ($exelearning->grademax ?? 100);
                $scaled = \mod_exelearning\local\attempts::aggregate_scaled(
                        $exelearning->id, $USER->id, 0, $grademethod);
                if ($scaled !== null) {
                    $extras[] = get_string('reportedgrade', 'mod_exelearning') . ': '
                            . format_float($scaled * $grademax, 2) . ' / ' . format_float($grademax, 2);
                }
            }
            if ($extras) {
                $label .= ' · ' . implode(' · ', $extras);
            }

            $class = ($maxattempt > 0 && $used >= $maxattempt)
                    ? 'alert alert-warning mb-3' : 'alert alert-secondary mb-3';
            echo html_writer::div($label, $class);
        }
        // Revisión de intentos previos, según reviewmode.
        $reviewmode = (int) ($exelearning->reviewmode
                ?? \mod_exelearning\local\attempts::REVIEW_ALWAYS);
        $iscomplete = false;
        $cinfo = new completion_info($course);
        if ($cinfo->is_enabled($cm)) {
            $cdata = $cinfo->get_data($cm, false, $USER->id);
            $iscomplete = in_array((int) $cdata->completionstate,
                    [COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS], true);
        }
        $canreview = ($reviewmode === \mod_exelearning\local\attempts::REVIEW_ALWAYS)
                || ($reviewmode === \mod_exelearning\local\attempts::REVIEW_AFTERCOMPLETION
                        && $iscomplete);
        if ($canreview && $used > 0) {
            $list = [];
            foreach ($myattempts as $ma) {
                $list[] = get_string('report_attempt', 'mod_exelearning') . ' ' . $ma->attempt
                        . ': ' . format_float((float) $ma->rawscore, 2)
                        . ' / ' . format_float((float) $ma->maxscore, 2);
            }
            echo html_writer::tag('details',
                    html_writer::tag('summary', get_string('attempts', 'mod_exelearning'))
                    . html_writer::alist($list),
                    ['class' => 'mb-3']);
        }
    }
    // SCORM 1.2 shim: inyecta window.API en la ventana padre del iframe.
    // pipwerks SCORM (que usan los iDevices de eXeLearning v4) hace
    // `findAPI()` recorriendo `window.parent` buscando un objeto `API` con
    // `LMSInitialize`. Si no lo encuentra, el iDevice muestra "Esta página
    // no forma parte de un paquete SCORM". Implementación mínima viable:
    // bufferiza pares CMI y los manda a track.php en LMSCommit/LMSFinish.
    $trackurl = (new moodle_url('/mod/exelearning/track.php',
            ['id' => $cm->id, 'sesskey' => sesskey(), 'mode' => $mode]))->out(false);
    $shimjs = <<<JS
(function () {
    var errCode = '0', cmi = {}, dirty = false, autoTimer = null;
    // Token único por carga de página: agrupa los auto-commits en un intento.
    var session = '%SESSION%';
    function send(sync) {
        if (!dirty) { return true; }
        try {
            var xhr = new XMLHttpRequest();
            // Síncrono en LMSFinish (alumno cierra la pestaña); async normalmente.
            xhr.open('POST', '%TRACKURL%', sync !== true);
            xhr.setRequestHeader('Content-Type', 'application/json');
            xhr.send(JSON.stringify({ id: %CMID%, session: session, cmi: cmi }));
            dirty = false;
            // En modo async no podemos chequear el status; asumimos OK.
            return sync === true ? (xhr.status >= 200 && xhr.status < 300) : true;
        } catch (e) { errCode = '101'; return false; }
    }
    // Autocommit: tras 500 ms sin nuevas SetValue, persistir.
    function schedule() {
        if (autoTimer) clearTimeout(autoTimer);
        autoTimer = setTimeout(function () { send(false); }, 500);
    }
    window.API = {
        LMSInitialize:   function () { return 'true'; },
        LMSFinish:       function () { send(true); return 'true'; },
        LMSCommit:       function () { return send(true) ? 'true' : 'false'; },
        LMSGetValue:     function (k) { return cmi[k] || ''; },
        LMSSetValue:     function (k, v) {
            cmi[k] = String(v); dirty = true;
            // Autocommit en valores críticos para que la nota llegue al
            // gradebook incluso si eXeLearning no llama a Commit explícito.
            if (k === 'cmi.core.score.raw' || k === 'cmi.core.lesson_status'
                    || k === 'cmi.score.raw' || k === 'cmi.completion_status'
                    || k === 'cmi.success_status') {
                schedule();
            }
            return 'true';
        },
        LMSGetLastError: function () { return errCode; },
        LMSGetErrorString:  function () { return ''; },
        LMSGetDiagnostic:   function () { return ''; },
    };
    // Persistir al cerrar la pestaña (síncrono).
    window.addEventListener('beforeunload', function () {
        if (autoTimer) clearTimeout(autoTimer);
        send(true);
    });
})();
JS;
    $attemptsession = random_string(20);
    $shimjs = str_replace(['%TRACKURL%', '%CMID%', '%SESSION%'],
            [addslashes($trackurl), (int) $cm->id, $attemptsession], $shimjs);
    echo html_writer::tag('script', $shimjs);

    // Iframe del paquete. Política de sandbox documentada en AN-008:
    //   - allow-scripts        eXeLearning v4 usa jQuery + JS de iDevices.
    //   - allow-same-origin    rutas relativas a pluginfile.php/.../content/<rev>/
    //                          + futuro postMessage al endpoint xAPI.
    //   - allow-popups         interactive-video, hidden-image, …
    //   - allow-forms          quick-questions, form, scrambled-list, …
    //   - allow-popups-to-escape-sandbox  los popups cargan sin restricciones.
    // SE BLOQUEAN explícitamente (no incluidas):
    //   - allow-top-navigation (un paquete malicioso no debe cambiar la URL padre).
    //   - allow-modals (no alert/confirm/prompt, son interrupciones de UX).
    echo html_writer::tag('iframe', '', [
        'src'    => $iframeurl->out(false),
        'name'   => 'exelearningobject',
        'id'     => 'exelearningobject',
        'title'  => format_string($exelearning->name),
        'width'  => '100%',
        'height' => '650',
        'allow'  => 'fullscreen',
        'sandbox' => 'allow-scripts allow-same-origin allow-popups allow-forms allow-popups-to-escape-sandbox',
        'style'  => 'border: 1px solid var(--bs-border-color, #dee2e6); border-radius: .5rem;',
    ]);
}

echo $OUTPUT->footer();
