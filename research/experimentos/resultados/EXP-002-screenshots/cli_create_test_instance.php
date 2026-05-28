<?php
// Script CLI auxiliar para EXP-002. Crea una instancia de mod_exelearning a
// partir del ELPX `actividad-evaluable.elpx` y verifica los grade items.
//
// Se ejecuta dentro del contenedor:
//   docker compose exec moodle php /var/www/html/mod/exelearning/research/experimentos/resultados/EXP-002-screenshots/cli_create_test_instance.php

define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->libdir . '/filelib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/mod/exelearning/lib.php');

cli_writeln('=== EXP-002: alta de instancia con actividad-evaluable.elpx ===');

// Curso de pruebas (id=2 "test").
$course = $DB->get_record('course', ['shortname' => 'test'], '*', MUST_EXIST);
$user = $DB->get_record('user', ['username' => 'user'], '*', MUST_EXIST);
\core\session\manager::set_user($user);

// Fixture ELPX dentro del repo montado.
$fixture = $CFG->dirroot . '/mod/exelearning/research/fixtures/elpx/actividad-evaluable.elpx';
if (!is_file($fixture)) {
    cli_error('No encontrado: ' . $fixture);
}

// Subir como draft file.
$fs = get_file_storage();
$usercontext = context_user::instance($user->id);
$draftitemid = file_get_unused_draft_itemid();
$filerecord = [
    'contextid' => $usercontext->id,
    'component' => 'user',
    'filearea'  => 'draft',
    'itemid'    => $draftitemid,
    'filepath'  => '/',
    'filename'  => basename($fixture),
];
$fs->create_file_from_pathname($filerecord, $fixture);
cli_writeln(sprintf('  Draft itemid=%d con %s creado.', $draftitemid, $filerecord['filename']));

// Datos para add_module().
$modinfo = get_fast_modinfo($course);
$section = 0;
$moduledata = (object) [
    'modulename'  => 'exelearning',
    'course'      => $course->id,
    'section'     => $section,
    'visible'     => 1,
    'name'        => 'Actividad evaluable (EXP-002)',
    'intro'       => 'Test multi-grade-items vía CLI.',
    'introformat' => FORMAT_HTML,
    'package'     => $draftitemid,
    'grademax'    => 10,
    'grademin'    => 0,
    'cmidnumber'  => '',
];

// Vía add_moduleinfo: maneja course_modules + filemanagers + grade_items todo.
[$cm, $modinfo2] = [null, null];
$result = add_moduleinfo($moduledata, $course);

cli_writeln(sprintf('  add_moduleinfo OK · cmid=%d · instance=%d', $result->coursemodule, $result->instance));

// Verificar.
$rows = $DB->get_records('exelearning_grade_item',
        ['exelearningid' => $result->instance], 'itemnumber ASC');
cli_writeln(sprintf('  Filas en mdl_exelearning_grade_item: %d', count($rows)));
foreach ($rows as $r) {
    cli_writeln(sprintf('    · itemnumber=%d  type=%-15s  objectid=%s  name=%s',
            $r->itemnumber, $r->idevicetype, $r->objectid, $r->name));
}

$gradeitems = $DB->get_records('grade_items',
        ['iteminstance' => $result->instance, 'itemmodule' => 'exelearning'],
        'itemnumber ASC');
cli_writeln(sprintf('  Filas en mdl_grade_items: %d', count($gradeitems)));
foreach ($gradeitems as $g) {
    cli_writeln(sprintf('    · itemnumber=%d  itemname=%-50s  grademax=%s',
            $g->itemnumber, $g->itemname, $g->grademax));
}

cli_writeln('=== fin ===');
