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
 * mod_exelearning · seed de demo
 *
 * Crea un curso, dos estudiantes y una actividad mod_exelearning con el
 * fixture `actividad-evaluable.elpx`. Idempotente: vuelve a ejecutarse sin
 * duplicar nada.
 *
 * Pensado para invocarse desde `POST_CONFIGURE_COMMANDS` del docker-compose,
 * o a mano: `php mod/exelearning/scripts/setup_demo.php`.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/enrollib.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->libdir . '/filelib.php');
require_once($CFG->libdir . '/moodlelib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/mod/exelearning/lib.php');

global $CFG, $DB, $USER;

cli_writeln('=== mod_exelearning · setup_demo ===');

// --- Config ---------------------------------------------------------------

$config = [
    'category_name'     => 'Demo eXeLearning',
    'course_shortname'  => 'EXEDEMO',
    'course_fullname'   => 'Demo eXeLearning · ejemplo de uso',
    'teacher_username'  => 'teacher_demo',
    'teacher_pass'      => 'Demo!2026',
    'students' => [
        ['username' => 'alumno1', 'firstname' => 'Alumno', 'lastname' => 'Uno',  'email' => 'alumno1@example.test'],
        ['username' => 'alumno2', 'firstname' => 'Alumno', 'lastname' => 'Dos',  'email' => 'alumno2@example.test'],
    ],
    'student_pass'      => 'Demo!2026',
    'activity_name'     => 'Actividad evaluable (demo)',
    'fixture_path'      => $CFG->dirroot . '/mod/exelearning/research/fixtures/elpx/actividad-evaluable.elpx',
];

// --- Sesión como admin ----------------------------------------------------

\core\session\manager::set_user(get_admin());

// Workaround para erseco/alpine-moodle:v5.0.7: el observer de mod_forum
// (mod_forum_observer::course_created) lee $CFG->forum_announcementsubscription
// y $CFG->forum_announcementmaxattachments y, si no están seteados, inserta
// NULL en `forcesubscribe`/`maxattachments` de mdl_forum → exception.
// Fijamos defaults razonables ANTES de create_course().
if (!isset($CFG->forum_announcementsubscription)) {
    set_config('forum_announcementsubscription', '1'); // FORUM_FORCESUBSCRIBE.
    $CFG->forum_announcementsubscription = '1';
}
if (!isset($CFG->forum_announcementmaxattachments)) {
    set_config('forum_announcementmaxattachments', '9');
    $CFG->forum_announcementmaxattachments = '9';
}

// --- 1) Categoría ---------------------------------------------------------

$category = $DB->get_record('course_categories', ['name' => $config['category_name']]);
if (!$category) {
    $cat = \core_course_category::create((object) [
        'name'        => $config['category_name'],
        'idnumber'    => 'demo-exelearning',
        'description' => 'Categoría para el curso demo de mod_exelearning.',
    ]);
    $category = $DB->get_record('course_categories', ['id' => $cat->id], '*', MUST_EXIST);
    cli_writeln('  · Categoría creada: ' . $category->name . ' (id=' . $category->id . ')');
} else {
    cli_writeln('  · Categoría existente: ' . $category->name . ' (id=' . $category->id . ')');
}

// --- 2) Curso -------------------------------------------------------------

$course = $DB->get_record('course', ['shortname' => $config['course_shortname']]);
if (!$course) {
    $course = create_course((object) [
        'category'        => $category->id,
        'shortname'       => $config['course_shortname'],
        'fullname'        => $config['course_fullname'],
        'summary'         => 'Curso demo creado por mod_exelearning/scripts/setup_demo.php.',
        'summaryformat'   => FORMAT_HTML,
        'format'          => 'topics',
        'numsections'     => 1,
        'startdate'       => time(),
        'visible'         => 1,
    ]);
    cli_writeln('  · Curso creado: ' . $course->shortname . ' (id=' . $course->id . ')');
} else {
    cli_writeln('  · Curso existente: ' . $course->shortname . ' (id=' . $course->id . ')');
}
$coursecontext = context_course::instance($course->id);

// --- 3) Usuarios + matriculación ----------------------------------------

$teacherroleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
$studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);

/**
 * Garantiza un usuario y lo matricula con el rol indicado.
 */
$ensure_user = function (array $u, string $pass, int $roleid) use ($course, $coursecontext) {
    global $DB, $CFG;
    require_once($CFG->dirroot . '/user/lib.php');

    $user = $DB->get_record('user', ['username' => $u['username'], 'mnethostid' => $CFG->mnet_localhost_id]);
    if (!$user) {
        $newuser = (object) [
            'username'      => $u['username'],
            'password'      => $pass,
            'firstname'     => $u['firstname'],
            'lastname'      => $u['lastname'],
            'email'         => $u['email'],
            'confirmed'     => 1,
            'mnethostid'    => $CFG->mnet_localhost_id,
            'auth'          => 'manual',
            'lang'          => 'es',
            'timezone'      => 'Europe/Madrid',
        ];
        $newuser->id = user_create_user($newuser, true, false);
        $user = $DB->get_record('user', ['id' => $newuser->id], '*', MUST_EXIST);
        cli_writeln('    · Usuario creado: ' . $user->username . ' (id=' . $user->id . ')');
    } else {
        cli_writeln('    · Usuario existente: ' . $user->username . ' (id=' . $user->id . ')');
    }

    // Matriculación manual.
    $enrol = enrol_get_plugin('manual');
    $instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*');
    if (!$instance) {
        $courseobj = $DB->get_record('course', ['id' => $course->id], '*', MUST_EXIST);
        // En Moodle 5.0.7 + erseco/alpine-moodle, los defaults globales pueden
        // no estar seteados → add_default_instance() inserta NULL en `status`
        // y la fila falla. Pasamos campos explícitos.
        $fields = [
            'status'           => ENROL_INSTANCE_ENABLED,
            'enrolperiod'      => 0,
            'expirynotify'     => 0,
            'notifyall'        => 0,
            'expirythreshold'  => 86400,
            'roleid'           => 0,
            'customint1'       => 0,
            'enrolstartdate'   => 0,
            'enrolenddate'     => 0,
        ];
        $instanceid = $enrol->add_instance($courseobj, $fields);
        $instance = $DB->get_record('enrol', ['id' => $instanceid], '*', MUST_EXIST);
    }
    if (!is_enrolled($coursecontext, $user, '', true)) {
        $enrol->enrol_user($instance, $user->id, $roleid);
        cli_writeln('      ↳ matriculado como roleid=' . $roleid);
    } else {
        cli_writeln('      ↳ ya matriculado');
    }
    return $user;
};

cli_writeln('  · Profesor:');
$ensure_user([
    'username'  => $config['teacher_username'],
    'firstname' => 'Profesor',
    'lastname'  => 'Demo',
    'email'     => $config['teacher_username'] . '@example.test',
], $config['teacher_pass'], $teacherroleid);

cli_writeln('  · Estudiantes:');
foreach ($config['students'] as $s) {
    $ensure_user($s, $config['student_pass'], $studentroleid);
}

// --- 4) Actividad mod_exelearning ---------------------------------------

if (!is_file($config['fixture_path'])) {
    cli_writeln('  ! Fixture no encontrado: ' . $config['fixture_path']);
    cli_writeln('    (saltando creación de actividad)');
    cli_writeln('=== setup_demo terminado ===');
    return;
}

$existing = $DB->get_record_sql('
    SELECT e.* FROM {exelearning} e
    JOIN {course_modules} cm ON cm.instance = e.id
    JOIN {modules} m ON m.id = cm.module AND m.name = ?
    WHERE e.course = ? AND e.name = ?
', ['exelearning', $course->id, $config['activity_name']]);

if ($existing) {
    cli_writeln('  · Actividad existente: ' . $existing->name . ' (instance=' . $existing->id . ')');
    cli_writeln('=== setup_demo terminado ===');
    return;
}

// Subir el ELPX como draft del admin.
$admin = get_admin();
$adminctx = context_user::instance($admin->id);
$fs = get_file_storage();
$draftitemid = file_get_unused_draft_itemid();
$fs->create_file_from_pathname([
    'contextid' => $adminctx->id,
    'component' => 'user',
    'filearea'  => 'draft',
    'itemid'    => $draftitemid,
    'filepath'  => '/',
    'filename'  => basename($config['fixture_path']),
], $config['fixture_path']);

// Crear el course_module + instance.
$section = 0; // General.
$moduleid = $DB->get_field('modules', 'id', ['name' => 'exelearning'], MUST_EXIST);
$data = (object) [
    'modulename'  => 'exelearning',
    'module'      => $moduleid,
    'course'      => $course->id,
    'section'     => $section,
    'visible'     => 1,
    'visibleoncoursepage' => 1,
    'name'        => $config['activity_name'],
    'intro'       => 'Cuestionario demo con dos iDevices (trueorfalse + guess).',
    'introformat' => FORMAT_HTML,
    'package'     => $draftitemid,
    'grademax'    => 100,
    'grademin'    => 0,
    'gradedisplaytype' => 0,
    'cmidnumber'  => '',
    'groupmode'   => NOGROUPS,
    'groupingid'  => 0,
];

$cm = add_moduleinfo($data, $course);
cli_writeln('  · Actividad creada: ' . $config['activity_name']
        . ' (cmid=' . $cm->coursemodule . ', instance=' . $cm->instance . ')');

cli_writeln('=== setup_demo terminado ===');
cli_writeln('  Curso:       http://localhost/course/view.php?id=' . $course->id);
cli_writeln('  Actividad:   http://localhost/mod/exelearning/view.php?id=' . $cm->coursemodule);
cli_writeln('  Profesor:    ' . $config['teacher_username'] . ' / ' . $config['teacher_pass']);
cli_writeln('  Estudiantes: alumno1, alumno2 / ' . $config['student_pass']);
