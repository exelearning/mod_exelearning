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
 * mod_exelearning public API.
 *
 * Implementa dos cosas:
 *   1. Almacenamiento + extracción de un paquete ELPX (estilo mod_exeweb):
 *      `mod_exelearning/package/0/<file>.elpx` y `mod_exelearning/content/<revision>/…`
 *      servidos vía `exelearning_pluginfile()`.
 *   2. Patrón multi-itemnumber para gradebook (AN-002 + AN-007):
 *      parsea `content.xml`, enumera iDevices calificables y registra N
 *      grade items con `grade_update(..., itemnumber=$n, ...)`.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Features supported by this module.
 *
 * @param string $feature
 * @return mixed
 */
function exelearning_supports($feature) {
    switch ($feature) {
        case FEATURE_MOD_ARCHETYPE:           return MOD_ARCHETYPE_ASSIGNMENT;
        case FEATURE_GROUPS:                  return true;
        case FEATURE_GROUPINGS:               return true;
        case FEATURE_MOD_INTRO:               return true;
        case FEATURE_COMPLETION_TRACKS_VIEWS: return true;
        case FEATURE_COMPLETION_HAS_RULES:    return false;
        case FEATURE_GRADE_HAS_GRADE:         return true;
        case FEATURE_GRADE_OUTCOMES:          return false;
        case FEATURE_BACKUP_MOODLE2:          return true;
        case FEATURE_SHOW_DESCRIPTION:        return true;
        case FEATURE_MOD_PURPOSE:             return MOD_PURPOSE_ASSESSMENT;
        default:                              return null;
    }
}

/**
 * Add new instance.
 *
 * @param stdClass $data
 * @param mod_exelearning_mod_form|null $mform
 * @return int new instance id
 */
function exelearning_add_instance($data, $mform = null) {
    global $DB;

    $data->timecreated = time();
    $data->timemodified = $data->timecreated;
    $data->revision = 1;
    if (!isset($data->grademax)) {
        $data->grademax = 100;
    }
    if (!isset($data->grademin)) {
        $data->grademin = 0;
    }
    if (!isset($data->gradepass)) {
        $data->gradepass = 0;
    }
    if (!isset($data->grademethod)) {
        $data->grademethod = \mod_exelearning\local\attempts::GRADE_HIGHEST;
    }

    $data->id = $DB->insert_record('exelearning', $data);

    // Persistir el ELPX subido + extraerlo a la filearea de contenido.
    exelearning_save_and_extract_package($data);

    // Detectar iDevices calificables y sincronizar grade items.
    // Pasamos el contextid explícitamente porque la course_module row la crea
    // Moodle DESPUÉS de retornar de _add_instance.
    $contextid = context_module::instance($data->coursemodule)->id;
    exelearning_sync_grade_items($data->id, $contextid);

    return $data->id;
}

/**
 * Update existing instance.
 *
 * @param stdClass $data
 * @param mod_exelearning_mod_form|null $mform
 * @return bool
 */
function exelearning_update_instance($data, $mform = null) {
    global $DB;

    $data->id = $data->instance;
    $data->timemodified = time();
    $data->revision = (int) ($DB->get_field('exelearning', 'revision',
            ['id' => $data->id]) ?: 0) + 1;
    if (!isset($data->grademax)) {
        $data->grademax = 100;
    }
    if (!isset($data->grademin)) {
        $data->grademin = 0;
    }
    if (!isset($data->gradepass)) {
        $data->gradepass = 0;
    }
    if (!isset($data->grademethod)) {
        $data->grademethod = \mod_exelearning\local\attempts::GRADE_HIGHEST;
    }

    $DB->update_record('exelearning', $data);

    exelearning_save_and_extract_package($data);
    $contextid = context_module::instance($data->coursemodule)->id;
    exelearning_sync_grade_items($data->id, $contextid);

    return true;
}

/**
 * Delete existing instance.
 *
 * @param int $id
 * @return bool
 */
function exelearning_delete_instance($id) {
    global $DB;

    $instance = $DB->get_record('exelearning', ['id' => $id]);
    if (!$instance) {
        return false;
    }

    $maxnum = (int) $DB->get_field_sql(
            "SELECT COALESCE(MAX(itemnumber),0) FROM {exelearning_grade_item}
             WHERE exelearningid = ?", [$id]);
    for ($n = 0; $n <= $maxnum; $n++) {
        grade_update('mod/exelearning', $instance->course, 'mod', 'exelearning',
                $id, $n, null, ['deleted' => true]);
    }

    $DB->delete_records('exelearning_attempt', ['exelearningid' => $id]);
    $DB->delete_records('exelearning_grade_item', ['exelearningid' => $id]);
    $DB->delete_records('exelearning', ['id' => $id]);

    return true;
}

/**
 * Stub para el grade item canónico (itemnumber=0). En multi-item los demás se
 * crean directamente en exelearning_sync_grade_items().
 *
 * @param stdClass $exelearning
 * @param mixed $grades
 * @return int
 */
function exelearning_grade_item_update($exelearning, $grades = null) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    $item = [
        'itemname'  => clean_param($exelearning->name, PARAM_NOTAGS),
        'gradetype' => GRADE_TYPE_VALUE,
        'grademax'  => $exelearning->grademax ?? 100,
        'grademin'  => $exelearning->grademin ?? 0,
        'gradepass' => $exelearning->gradepass ?? 0,
        'display'   => (int) ($exelearning->gradedisplaytype ?? GRADE_DISPLAY_TYPE_DEFAULT),
    ];
    return grade_update('mod/exelearning', $exelearning->course, 'mod',
            'exelearning', $exelearning->id, 0, $grades, $item);
}

/**
 * Necesario para que Moodle 5.x muestre items con itemnumber>0 en
 * "Course overview" (sin esta función Moodle no sabe etiquetar las columnas).
 *
 * @param array $items grade_item objects (mismo iteminstance, distintos itemnumber).
 * @return array<int,string> indexado por grade_item->id.
 */
function exelearning_get_grade_item_names(array $items): array {
    global $DB;

    $names = [];
    foreach ($items as $item) {
        if ((int) $item->itemnumber === 0) {
            $names[$item->id] = get_string('gradeitem_overall', 'mod_exelearning');
            continue;
        }
        $row = $DB->get_record('exelearning_grade_item',
                ['exelearningid' => $item->iteminstance, 'itemnumber' => $item->itemnumber],
                'name');
        $names[$item->id] = $row ? format_string($row->name)
                : ('Item #' . $item->itemnumber);
    }
    return $names;
}

/**
 * pluginfile callback: sirve archivos del paquete extraído.
 *
 * Esquema (mismo que mod_exeweb):
 *   - `package`   itemid=0           ZIP original (sólo profesores).
 *   - `content`   itemid=$revision    Archivos extraídos del ZIP.
 *
 * @param stdClass $course
 * @param stdClass $cm
 * @param context $context
 * @param string $filearea
 * @param array $args
 * @param bool $forcedownload
 * @param array $options
 * @return bool|null
 */
function exelearning_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    global $CFG, $DB;

    if ($context->contextlevel !== CONTEXT_MODULE) {
        return false;
    }

    require_course_login($course, true, $cm);
    require_capability('mod/exelearning:view', $context);

    if ($filearea === 'package') {
        // Sólo profesores pueden bajarse el ELPX completo.
        require_capability('moodle/course:manageactivities', $context);
        $itemid = (int) array_shift($args);
        $fs = get_file_storage();
        $relativepath = implode('/', $args);
        $fullpath = "/{$context->id}/mod_exelearning/package/{$itemid}/{$relativepath}";
        if (!$file = $fs->get_file_by_hash(sha1($fullpath))) {
            return false;
        }
        send_stored_file($file, null, 0, $forcedownload, $options);
        return null;
    }

    if ($filearea !== 'content') {
        return false;
    }

    $revision = (int) array_shift($args);
    $fs = get_file_storage();
    $relativepath = implode('/', $args);
    $fullpath = rtrim("/{$context->id}/mod_exelearning/{$filearea}/{$revision}/{$relativepath}", '/');

    $file = $fs->get_file_by_hash(sha1($fullpath));
    if (!$file) {
        // Fallback a index.html dentro de la carpeta solicitada (como mod_exeweb).
        foreach (['index.html', 'index.htm', 'Default.htm'] as $candidate) {
            $file = $fs->get_file_by_hash(sha1("{$fullpath}/{$candidate}"));
            if ($file) {
                break;
            }
        }
    }
    if (!$file || $file->is_directory()) {
        return false;
    }

    // Servir SVG inline (eXeLearning v4 embebe iconos en content/css/icons/
    // que deben renderizar, no descargarse). Mismo flag que usa mod_scorm.
    $options['dontforcesvgdownload'] = true;

    // Cache-control razonable: bump de revision invalida automáticamente la URL.
    $lifetime = $CFG->filelifetime ?? 86400;
    send_stored_file($file, $lifetime, 0, $forcedownload, $options);
    return null;
}

/**
 * Indica que esta actividad tiene contenido descargable bajo `content` y `package`.
 *
 * @param stdClass $course
 * @param stdClass $cm
 * @param context $context
 * @return array
 */
function exelearning_get_file_areas($course, $cm, $context) {
    return [
        'content' => get_string('areacontent', 'mod_exelearning'),
        'package' => get_string('areapackage', 'mod_exelearning'),
    ];
}

/**
 * Trigger course_module_viewed + completion update.
 *
 * @param stdClass $exelearning
 * @param stdClass $course
 * @param stdClass $cm
 * @param context $context
 */
function exelearning_view(stdClass $exelearning, stdClass $course,
        stdClass $cm, context $context): void {
    global $CFG;
    require_once($CFG->libdir . '/completionlib.php');

    $params = [
        'context' => $context,
        'objectid' => $exelearning->id,
    ];
    $event = \mod_exelearning\event\course_module_viewed::create($params);
    $event->add_record_snapshot('course_modules', $cm);
    $event->add_record_snapshot('course', $course);
    $event->add_record_snapshot('exelearning', $exelearning);
    $event->trigger();

    $completion = new completion_info($course);
    $completion->set_module_viewed($cm);
}

/**
 * Guarda el ELPX subido en filearea 'package' y lo extrae a 'content/{revision}/'.
 *
 * @param stdClass $data Datos del formulario (con `coursemodule`, `package` draftid, `revision`).
 */
function exelearning_save_and_extract_package(stdClass $data): void {
    if (empty($data->package)) {
        return;
    }
    $context = context_module::instance($data->coursemodule);
    $fs = get_file_storage();

    // 1) Persistir el ZIP en 'package/0/'.
    $fs->delete_area_files($context->id, 'mod_exelearning', 'package');
    file_save_draft_area_files($data->package, $context->id,
            'mod_exelearning', 'package', 0,
            ['subdirs' => 0, 'maxfiles' => 1]);

    // 2) Extraer el ZIP recién guardado al filearea de contenido.
    exelearning_extract_stored_package($context->id, (int) $data->revision);
}

/**
 * Extrae a `content/{revision}/` el ELPX ya almacenado en `package/0/`.
 *
 * Separado de exelearning_save_and_extract_package() para poder re-ejecutarse
 * SIN un draft itemid (p.ej. el self-heal de view.php cuando una subida
 * programática como el `addModule` del Playground dejó el paquete en
 * filearea 'package' pero no extrajo/sincronizó). Idempotente: limpia el
 * contenido previo y vuelve a extraer.
 *
 * @param int $contextid
 * @param int $revision
 */
function exelearning_extract_stored_package(int $contextid, int $revision): void {
    $fs = get_file_storage();

    // Localizar el ZIP almacenado.
    $files = $fs->get_area_files($contextid, 'mod_exelearning', 'package', 0,
            'sortorder, filepath, filename', false);
    $package = reset($files);
    if (!$package instanceof \stored_file) {
        return;
    }

    $data = (object) ['revision' => $revision];
    $context = (object) ['id' => $contextid];

    // 3) Limpiar contenido previo y extraer al revision actual.
    $fs->delete_area_files($context->id, 'mod_exelearning', 'content');

    $packer = get_file_packer('application/zip');
    $package->extract_to_storage($packer, $context->id, 'mod_exelearning',
            'content', (int) $data->revision, '/');

    // 4) Asegurar entrada index.html como mainfile (para el navegador del archivo).
    $entry = $fs->get_file($context->id, 'mod_exelearning', 'content',
            (int) $data->revision, '/', 'index.html');
    if ($entry) {
        file_set_sortorder($context->id, 'mod_exelearning', 'content',
                (int) $data->revision, '/', 'index.html', 1);
    }

    // 5) Si el paquete (export web) no trae libs/SCORM_API_wrapper.js,
    //    inyectarlo desde assets/ del plugin. eXeLearning v4 sólo incluye
    //    este wrapper en el export SCORM; sin él, los iDevices calificables
    //    muestran "esta página no forma parte de un paquete SCORM".
    foreach (['SCORM_API_wrapper.js', 'SCOFunctions.js'] as $shimname) {
        $present = $fs->get_file($context->id, 'mod_exelearning', 'content',
                (int) $data->revision, '/libs/', $shimname);
        if ($present) {
            continue;
        }
        $assetpath = __DIR__ . '/assets/scorm/' . $shimname;
        if (!is_file($assetpath)) {
            continue;
        }
        $fs->create_file_from_pathname([
            'contextid' => $context->id,
            'component' => 'mod_exelearning',
            'filearea'  => 'content',
            'itemid'    => (int) $data->revision,
            'filepath'  => '/libs/',
            'filename'  => $shimname,
        ], $assetpath);
    }

    // 6) Inyectar <script src="libs/SCORM_API_wrapper.js"></script> en los
    //    HTMLs del paquete. eXeLearning v4 sólo carga el wrapper on-demand
    //    cuando el usuario pulsa "Guardar puntuación", pero antes (en
    //    libs/common.js:1052) ya hace un check `typeof pipwerks === 'undefined'`
    //    que decide si mostrar el mensaje "no es paquete SCORM" o la barra
    //    de guardar nota. Forzando la carga al cargar la página, ese check
    //    pasa y el iDevice reconoce el entorno SCORM.
    exelearning_inject_scorm_loader($context->id, (int) $data->revision);
}

/**
 * Inyecta script-tags del wrapper SCORM en el <head> de index.html y de
 * todas las páginas html/<slug>.html del paquete extraído.
 *
 * @param int $contextid
 * @param int $revision
 */
function exelearning_inject_scorm_loader(int $contextid, int $revision): void {
    $fs = get_file_storage();
    $marker = '<!-- mod_exelearning:scorm-loader -->';
    // Tras cargar el wrapper, forzamos `pipwerks.SCORM.init()` para que
    // connection.isActive=true y los `set()` posteriores SÍ lleguen a
    // window.parent.API.LMSSetValue. eXeLearning sólo invoca init() en el
    // flujo on-click; en isScorm==1 (auto-save tras cada pregunta) no llega
    // a iniciarse, así que lo hacemos aquí.
    $initscript = "\n    <script>\n" .
            "      (function(){\n" .
            "        var t = setInterval(function(){\n" .
            "          if (window.pipwerks && window.pipwerks.SCORM) {\n" .
            "            clearInterval(t);\n" .
            "            try { window.pipwerks.SCORM.init(); } catch(e){}\n" .
            "          }\n" .
            "        }, 50);\n" .
            "      })();\n" .
            "    </script>\n";
    $tags = $marker .
            "\n    <script src=\"libs/SCORM_API_wrapper.js\"></script>" .
            "\n    <script src=\"libs/SCOFunctions.js\"></script>" .
            $initscript;
    $tagshtml = $marker .
            "\n    <script src=\"../libs/SCORM_API_wrapper.js\"></script>" .
            "\n    <script src=\"../libs/SCOFunctions.js\"></script>" .
            $initscript;

    // Recorrer todos los HTML del filearea.
    $files = $fs->get_area_files($contextid, 'mod_exelearning', 'content',
            $revision, 'filepath, filename', false);
    foreach ($files as $file) {
        if ($file->is_directory()) {
            continue;
        }
        $name = $file->get_filename();
        if (!preg_match('~\.html?$~i', $name)) {
            continue;
        }
        $html = $file->get_content();
        if ($html === '' || strpos($html, $marker) !== false) {
            continue;
        }
        $path = $file->get_filepath();
        $payload = ($path === '/') ? $tags : $tagshtml;
        // Insertar justo antes de </head> (case-insensitive).
        $newhtml = preg_replace('~</head>~i', $payload . '</head>', $html, 1);
        if ($newhtml === null || $newhtml === $html) {
            continue;
        }
        // Reemplazar contenido en filearea: borrar y recrear.
        $record = [
            'contextid' => $contextid,
            'component' => 'mod_exelearning',
            'filearea'  => 'content',
            'itemid'    => $revision,
            'filepath'  => $path,
            'filename'  => $name,
        ];
        $file->delete();
        $fs->create_file_from_string($record, $newhtml);
    }
}

/**
 * Detecta iDevices calificables en el paquete almacenado y sincroniza grade items.
 *
 * @param int $exelearningid
 */
function exelearning_sync_grade_items(int $exelearningid, ?int $contextid = null): void {
    global $CFG, $DB;
    require_once($CFG->libdir . '/gradelib.php');

    $instance = $DB->get_record('exelearning', ['id' => $exelearningid], '*', MUST_EXIST);

    if ($contextid === null) {
        $cm = get_coursemodule_from_instance('exelearning', $exelearningid);
        if (!$cm) {
            return;
        }
        $contextid = context_module::instance($cm->id)->id;
    }
    $context = context::instance_by_id($contextid);

    // Localizar el ELPX en filearea 'package'.
    $fs = get_file_storage();
    $files = $fs->get_area_files($context->id, 'mod_exelearning', 'package', 0,
            'sortorder, filepath, filename', false);
    $elpx = reset($files);
    if (!$elpx instanceof \stored_file) {
        return;
    }

    // Grade item canónico (itemnumber=0).
    exelearning_grade_item_update($instance);

    // Detección.
    $detected = (new \mod_exelearning\local\package($elpx))->detect_gradable_idevices();
    if ($detected === []) {
        return;
    }

    $existing = $DB->get_records('exelearning_grade_item',
            ['exelearningid' => $exelearningid], '', 'objectid, id, itemnumber, deleted');

    $nextnum = (int) $DB->get_field_sql(
            "SELECT COALESCE(MAX(itemnumber),0) FROM {exelearning_grade_item}
             WHERE exelearningid = ?", [$exelearningid]);

    $seen = [];
    foreach ($detected as $d) {
        $name = exelearning_grade_item_name($instance, $d);
        $now = time();

        if (isset($existing[$d->objectid])) {
            $row = $existing[$d->objectid];
            $row->name         = $name;
            $row->idevicetype  = $d->idevicetype;
            $row->pageid       = $d->pageid;
            $row->deleted      = 0;
            $row->timemodified = $now;
            $DB->update_record('exelearning_grade_item', $row);
            $itemnumber = (int) $row->itemnumber;
        } else {
            $nextnum++;
            $itemnumber = $nextnum;
            $DB->insert_record('exelearning_grade_item', (object) [
                'exelearningid' => $exelearningid,
                'itemnumber'    => $itemnumber,
                'objectid'      => $d->objectid,
                'pageid'        => $d->pageid,
                'idevicetype'   => $d->idevicetype,
                'name'          => $name,
                'grademax'      => $instance->grademax ?? 100,
                'grademin'      => $instance->grademin ?? 0,
                'deleted'       => 0,
                'timecreated'   => $now,
                'timemodified'  => $now,
            ]);
        }
        $seen[$d->objectid] = true;

        grade_update('mod/exelearning', $instance->course, 'mod', 'exelearning',
                $instance->id, $itemnumber, null, [
                    'itemname'  => $name,
                    'gradetype' => GRADE_TYPE_VALUE,
                    'grademax'  => $instance->grademax ?? 100,
                    'grademin'  => $instance->grademin ?? 0,
                    'display'   => (int) ($instance->gradedisplaytype ?? GRADE_DISPLAY_TYPE_DEFAULT),
                ]);
    }

    // Marcar como deleted los items previos que ya no aparecen.
    foreach ($existing as $objectid => $row) {
        if (!isset($seen[$objectid]) && !$row->deleted) {
            $row->deleted = 1;
            $row->timemodified = time();
            $DB->update_record('exelearning_grade_item', $row);
        }
    }
}

/**
 * Nombre legible de la columna del libro de calificaciones para un iDevice.
 *
 * @param stdClass $instance
 * @param stdClass $detected
 * @return string
 */
function exelearning_grade_item_name(stdClass $instance, stdClass $detected): string {
    $type = clean_param($detected->idevicetype, PARAM_TEXT);
    $page = trim((string) ($detected->pagename ?? ''));
    $base = clean_param($instance->name, PARAM_NOTAGS);
    if ($page !== '') {
        return $base . ' · ' . $page . ' · ' . $type;
    }
    return $base . ' · ' . $type;
}
