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
 * Implements two concerns:
 *   1. Storage and extraction of an ELPX package (mod_exeweb style):
 *      `mod_exelearning/package/0/<file>.elpx` and `mod_exelearning/content/<revision>/…`
 *      served via `exelearning_pluginfile()`.
 *   2. Multi-itemnumber gradebook pattern (AN-002 + AN-007):
 *      parses `content.xml`, enumerates gradable iDevices and registers N
 *      grade items with `grade_update(..., itemnumber=$n, ...)`.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// DEC-0008 (rev. 2026-05-29): gradebook columns model. Two mutually-exclusive
// presentations; the "both" mode was removed. Default is per-iDevice.
define('EXELEARNING_GRADEMODEL_OVERALL', 0); // Overall grade only (itemnumber=0).
define('EXELEARNING_GRADEMODEL_PERITEM', 1); // One column per gradable iDevice (default).

/**
 * Features supported by this module.
 *
 * @param string $feature
 * @return mixed
 */
function exelearning_supports($feature) {
    switch ($feature) {
        case FEATURE_MOD_ARCHETYPE:
            return MOD_ARCHETYPE_ASSIGNMENT;
        case FEATURE_GROUPS:
            return true;
        case FEATURE_GROUPINGS:
            return true;
        case FEATURE_MOD_INTRO:
            return true;
        case FEATURE_COMPLETION_TRACKS_VIEWS:
            return true;
        case FEATURE_COMPLETION_HAS_RULES:
            return false;
        case FEATURE_GRADE_HAS_GRADE:
            return true;
        case FEATURE_GRADE_OUTCOMES:
            return false;
        case FEATURE_BACKUP_MOODLE2:
            return true;
        case FEATURE_SHOW_DESCRIPTION:
            return true;
        case FEATURE_MOD_PURPOSE:
            return MOD_PURPOSE_ASSESSMENT;
        default:
            return null;
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
    if (!isset($data->grademodel)) {
        $data->grademodel = EXELEARNING_GRADEMODEL_PERITEM;
    }
    if (!isset($data->maxattempt)) {
        $data->maxattempt = 0;
    }
    if (!isset($data->reviewmode)) {
        $data->reviewmode = \mod_exelearning\local\attempts::REVIEW_ALWAYS;
    }
    if (!isset($data->teachermodevisible)) {
        $data->teachermodevisible = 0;
    }

    $data->id = $DB->insert_record('exelearning', $data);

    // Persist the uploaded ELPX and extract it to the content filearea.
    exelearning_save_and_extract_package($data);

    // Detect gradable iDevices and synchronise grade items.
    // We pass the contextid explicitly because the course_module row is created
    // by Moodle AFTER returning from _add_instance.
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
    $data->revision = (int) ($DB->get_field(
        'exelearning',
        'revision',
        ['id' => $data->id]
    ) ?: 0) + 1;
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
    if (!isset($data->grademodel)) {
        $data->grademodel = EXELEARNING_GRADEMODEL_PERITEM;
    }
    if (!isset($data->maxattempt)) {
        $data->maxattempt = 0;
    }
    if (!isset($data->reviewmode)) {
        $data->reviewmode = \mod_exelearning\local\attempts::REVIEW_ALWAYS;
    }
    if (!isset($data->teachermodevisible)) {
        $data->teachermodevisible = 0;
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
             WHERE exelearningid = ?",
        [$id]
    );
    for ($n = 0; $n <= $maxnum; $n++) {
        grade_update(
            'mod/exelearning',
            $instance->course,
            'mod',
            'exelearning',
            $id,
            $n,
            null,
            ['deleted' => true]
        );
    }

    $DB->delete_records('exelearning_attempt', ['exelearningid' => $id]);
    $DB->delete_records('exelearning_grade_item', ['exelearningid' => $id]);
    $DB->delete_records('exelearning', ['id' => $id]);

    return true;
}

/**
 * Adds the course-reset form elements for mod_exelearning.
 *
 * Without these callbacks "Reset course" leaves every previous student's
 * attempt rows and gradebook grades intact (unlike mod_scorm/mod_h5pactivity),
 * so a recycled course inherits stale per-user data.
 *
 * @param MoodleQuickForm $mform The course-reset form being built.
 */
function exelearning_reset_course_form_definition($mform) {
    $mform->addElement('header', 'exelearningheader', get_string('modulenameplural', 'mod_exelearning'));
    $mform->addElement('advcheckbox', 'reset_exelearning', get_string('resetattempts', 'mod_exelearning'));
}

/**
 * Default values for the course-reset form (option checked by default).
 *
 * @param stdClass $course The course being reset.
 * @return array<string,int>
 */
function exelearning_reset_course_form_defaults($course) {
    return ['reset_exelearning' => 1];
}

/**
 * Removes all user attempt data and clears the gradebook for every
 * mod_exelearning instance in a course when the teacher resets it.
 *
 * @param stdClass $data The reset form data (courseid + reset_exelearning flag).
 * @return array<int,array<string,mixed>> Status entries for the reset report.
 */
function exelearning_reset_userdata($data) {
    global $DB;

    $status = [];
    if (empty($data->reset_exelearning)) {
        return $status;
    }

    $instanceids = $DB->get_fieldset_select(
        'exelearning',
        'id',
        'course = ?',
        [$data->courseid]
    );
    foreach ($instanceids as $instanceid) {
        $DB->delete_records('exelearning_attempt', ['exelearningid' => $instanceid]);
    }

    // Clear the gradebook so attempt deletion and grades stay consistent.
    exelearning_reset_gradebook($data->courseid);

    $status[] = [
        'component' => get_string('modulenameplural', 'mod_exelearning'),
        'item'      => get_string('resetattempts', 'mod_exelearning'),
        'error'     => false,
    ];

    return $status;
}

/**
 * Resets the gradebook grades of every mod_exelearning instance in a course.
 *
 * Called from exelearning_reset_userdata() and by the core gradebook-reset
 * workflow. Each registered grade item (overall + per iDevice) is reset via
 * grade_update(..., ['reset' => true]).
 *
 * @param int $courseid The course being reset.
 * @param string $type Optional grade reset type (unused; core API parity).
 */
function exelearning_reset_gradebook($courseid, $type = '') {
    global $CFG, $DB;
    require_once($CFG->libdir . '/gradelib.php');

    $instances = $DB->get_records('exelearning', ['course' => $courseid], '', 'id');
    foreach ($instances as $instance) {
        $maxnum = (int) $DB->get_field_sql(
            "SELECT COALESCE(MAX(itemnumber),0) FROM {exelearning_grade_item}
                 WHERE exelearningid = ?",
            [$instance->id]
        );
        for ($n = 0; $n <= $maxnum; $n++) {
            grade_update(
                'mod/exelearning',
                $courseid,
                'mod',
                'exelearning',
                $instance->id,
                $n,
                null,
                ['reset' => true]
            );
        }
    }
}

/**
 * Stub for the canonical grade item (itemnumber=0). In multi-item mode the rest
 * are created directly in exelearning_sync_grade_items().
 *
 * @param stdClass $exelearning
 * @param mixed $grades
 * @param array $itemdetails Extra grade item fields passed to grade_update().
 * @return int
 */
function exelearning_grade_item_update($exelearning, $grades = null, array $itemdetails = []) {
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
    $item += $itemdetails;

    return grade_update(
        'mod/exelearning',
        $exelearning->course,
        'mod',
        'exelearning',
        $exelearning->id,
        0,
        $grades,
        $item
    );
}

/**
 * Required so that Moodle 5.x can display items with itemnumber>0 in
 * "Course overview" (without this function Moodle cannot label the columns).
 *
 * @param array $items grade_item objects (same iteminstance, different itemnumber).
 * @return array<int,string> indexed by grade_item->id.
 */
function exelearning_get_grade_item_names(array $items): array {
    global $DB;

    $names = [];
    foreach ($items as $item) {
        if ((int) $item->itemnumber === 0) {
            $names[$item->id] = get_string('gradeitem_overall', 'mod_exelearning');
            continue;
        }
        $row = $DB->get_record(
            'exelearning_grade_item',
            ['exelearningid' => $item->iteminstance, 'itemnumber' => $item->itemnumber],
            'name'
        );
        $names[$item->id] = $row ? format_string($row->name)
                : ('Item #' . $item->itemnumber);
    }
    return $names;
}

/**
 * pluginfile callback: serves files from the extracted package.
 *
 * Scheme (same as mod_exeweb):
 *   - `package`   itemid=0           Original ZIP (teachers only).
 *   - `content`   itemid=$revision    Files extracted from the ZIP.
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
        // Only teachers can download the full ELPX package.
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
        // Fallback to index.html inside the requested folder (as mod_exeweb does).
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

    // Serve SVG inline (eXeLearning v4 embeds icons in content/css/icons/ that
    // must render, not be downloaded). Same flag used by mod_scorm.
    $options['dontforcesvgdownload'] = true;

    // Reasonable cache-control: a revision bump automatically invalidates the URL.
    $lifetime = $CFG->filelifetime ?? 86400;
    send_stored_file($file, $lifetime, 0, $forcedownload, $options);
    return null;
}

/**
 * Indicates that this activity has downloadable content under `content` and `package`.
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
function exelearning_view(
    stdClass $exelearning,
    stdClass $course,
    stdClass $cm,
    context $context
): void {
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
 * Adds the "Reports" tab to the activity navigation, like mod_scorm.
 *
 * It appears in the module's secondary navigation for anyone with the capability
 * mod/exelearning:viewreport, linking to the attempts report (report.php).
 *
 * @param settings_navigation $settings
 * @param navigation_node $node
 */
function exelearning_extend_settings_navigation(
    settings_navigation $settings,
    navigation_node $node
): void {
    global $PAGE;

    $context = $PAGE->cm->context ?? null;
    if (!$context || !has_capability('mod/exelearning:viewreport', $context)) {
        return;
    }

    $url = new moodle_url('/mod/exelearning/report.php', ['id' => $PAGE->cm->id]);
    $reportnode = navigation_node::create(
        get_string('reports', 'mod_exelearning'),
        $url,
        navigation_node::TYPE_SETTING,
        null,
        'exelearningreport',
        new pix_icon('i/report', '')
    );

    // Insert before the roles/permissions node if present, as SCORM does.
    if ($beforekey = exelearning_navigation_before_key($node)) {
        $node->add_node($reportnode, $beforekey);
    } else {
        $node->add_node($reportnode);
    }
}

/**
 * Returns the key of the first "administrative" node in the module navigation
 * (roles/permissions/filters) so that "Reports" is inserted just before it,
 * replicating mod_scorm's ordering.
 *
 * @param navigation_node $node
 * @return string|null
 */
function exelearning_navigation_before_key(navigation_node $node): ?string {
    foreach (['roleassign', 'roles', 'permissions', 'filtermanagement'] as $key) {
        if ($node->get($key)) {
            return $key;
        }
    }
    return null;
}

/**
 * Saves the uploaded ELPX in the 'package' filearea and extracts it to 'content/{revision}/'.
 *
 * @param stdClass $data Form data (with `coursemodule`, `package` draftid, `revision`).
 */
function exelearning_save_and_extract_package(stdClass $data): void {
    if (empty($data->package)) {
        return;
    }
    $context = context_module::instance($data->coursemodule);
    $fs = get_file_storage();

    // 1) Persist the ZIP in 'package/0/'.
    $fs->delete_area_files($context->id, 'mod_exelearning', 'package');
    file_save_draft_area_files(
        $data->package,
        $context->id,
        'mod_exelearning',
        'package',
        0,
        ['subdirs' => 0, 'maxfiles' => 1]
    );

    // 2) Extract the newly saved ZIP to the content filearea.
    exelearning_extract_stored_package($context->id, (int) $data->revision);
}

/**
 * Locate the stored ELPX in the 'package' filearea WITHOUT assuming an itemid.
 *
 * The form upload stores it at itemid=0, but programmatic paths leave it at a
 * different itemid (e.g. the Moodle Playground `addModule`, which uploads with
 * `itemid: 1`, or `editor/save.php`, which uses the revision as itemid). We scan
 * ALL itemids and return the most recent file.
 *
 * @param int $contextid
 * @return \stored_file|null
 */
function exelearning_get_stored_package(int $contextid): ?\stored_file {
    $fs = get_file_storage();
    // Itemid=false means every itemid in the filearea.
    $files = $fs->get_area_files(
        $contextid,
        'mod_exelearning',
        'package',
        false,
        'itemid DESC, sortorder, filepath, filename',
        false
    );
    foreach ($files as $file) {
        if (!$file->is_directory()) {
            return $file;
        }
    }
    return null;
}

/**
 * Extracts the ELPX already stored in `package` to `content/{revision}/`.
 *
 * Kept separate from exelearning_save_and_extract_package() so it can be
 * re-run WITHOUT a draft itemid (e.g. the view.php self-heal when a programmatic
 * upload such as the Playground's `addModule` left the package in the 'package'
 * filearea but did not extract/sync it). Idempotent: clears previous content
 * and re-extracts.
 *
 * @param int $contextid
 * @param int $revision
 */
function exelearning_extract_stored_package(int $contextid, int $revision): void {
    $fs = get_file_storage();

    // Locate the stored ZIP (any itemid).
    $package = exelearning_get_stored_package($contextid);
    if (!$package instanceof \stored_file) {
        return;
    }

    $data = (object) ['revision' => $revision];
    $context = (object) ['id' => $contextid];

    // 3) Clear previous content and extract to the current revision.
    $fs->delete_area_files($context->id, 'mod_exelearning', 'content');

    $packer = get_file_packer('application/zip');
    $package->extract_to_storage(
        $packer,
        $context->id,
        'mod_exelearning',
        'content',
        (int) $data->revision,
        '/'
    );

    // 4) Ensure index.html is set as mainfile (for the file browser).
    $entry = $fs->get_file(
        $context->id,
        'mod_exelearning',
        'content',
        (int) $data->revision,
        '/',
        'index.html'
    );
    if ($entry) {
        file_set_sortorder(
            $context->id,
            'mod_exelearning',
            'content',
            (int) $data->revision,
            '/',
            'index.html',
            1
        );
    }

    // 5) If the package (web export) does not include libs/SCORM_API_wrapper.js,
    // inject it from the plugin's assets/ directory. eXeLearning v4 only bundles
    // this wrapper in the SCORM export; without it, gradable iDevices display
    // "this page is not part of a SCORM package".
    foreach (['SCORM_API_wrapper.js', 'SCOFunctions.js'] as $shimname) {
        $present = $fs->get_file(
            $context->id,
            'mod_exelearning',
            'content',
            (int) $data->revision,
            '/libs/',
            $shimname
        );
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

    // 6) Inject <script src="libs/SCORM_API_wrapper.js"></script> into the
    // package HTML files. eXeLearning v4 only loads the wrapper on-demand when
    // the user clicks "Save score", but before that (in libs/common.js:1052) it
    // already checks `typeof pipwerks === 'undefined'` to decide whether to show
    // the "not a SCORM package" message or the save-score bar. By forcing the
    // load at page-load time, that check passes and the iDevice recognises the
    // SCORM environment.
    exelearning_inject_scorm_loader($context->id, (int) $data->revision);
}

/**
 * Hide eXeLearning's teacher-mode toggle (#teacher-mode-toggler-wrapper) inside
 * the package iframe (mod_exeweb parity). Queues parent-page JS that injects a
 * <style> into the iframe's content document once it loads. The iframe is
 * same-origin (served via pluginfile.php), so this DOM access is allowed.
 *
 * @param string $iframeid The id attribute of the package iframe.
 * @return void
 */
function exelearning_require_teacher_mode_hider(string $iframeid): void {
    global $PAGE;

    $iframeidjson = json_encode($iframeid);
    $cssjson = json_encode('#teacher-mode-toggler-wrapper { visibility: hidden !important; }');

    $js = "(function(){"
        . "var iframe=document.getElementById(" . $iframeidjson . ");"
        . "if(!iframe){return;}"
        . "var css=" . $cssjson . ";"
        . "var inject=function(){try{if(!iframe.contentDocument){return;}"
        . "var d=iframe.contentDocument;var st=d.createElement('style');st.textContent=css;"
        . "(d.head||d.documentElement).appendChild(st);}catch(e){}};"
        . "iframe.addEventListener('load', inject);inject();"
        . "})();";

    $PAGE->requires->js_init_code($js);
}

/**
 * Injects SCORM wrapper script tags into the <head> of index.html and all
 * html/<slug>.html pages of the extracted package.
 *
 * @param int $contextid
 * @param int $revision
 */
function exelearning_inject_scorm_loader(int $contextid, int $revision): void {
    $fs = get_file_storage();
    $marker = '<!-- mod_exelearning:scorm-loader -->';
    // After loading the wrapper, force `pipwerks.SCORM.init()` so that
    // connection.isActive=true and subsequent `set()` calls DO reach
    // window.parent.API.LMSSetValue. eXeLearning only invokes init() in the
    // on-click flow; with isScorm==1 (auto-save after each question) it never
    // gets called, so we trigger it here.
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

    // Iterate over all HTML files in the filearea.
    $files = $fs->get_area_files(
        $contextid,
        'mod_exelearning',
        'content',
        $revision,
        'filepath, filename',
        false
    );
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
        // Insert just before </head> (case-insensitive).
        $newhtml = preg_replace('~</head>~i', $payload . '</head>', $html, 1);
        if ($newhtml === null || $newhtml === $html) {
            continue;
        }
        // Replace content in the filearea: delete and recreate.
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
 * Detects gradable iDevices in the stored package and synchronises grade items.
 *
 * @param int $exelearningid
 * @param int|null $contextid
 * @return void
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

    // Locate the ELPX in the 'package' filearea (any itemid: form=0,
    // Playground addModule=1, editor/save.php=revision).
    $elpx = exelearning_get_stored_package($context->id);
    if (!$elpx instanceof \stored_file) {
        return;
    }

    $grademodel = (int) ($instance->grademodel ?? EXELEARNING_GRADEMODEL_PERITEM);

    // Canonical grade item (itemnumber=0) according to the grading model
    // (DEC-0008). PERITEM keeps it hidden so Moodle's core
    // completionpassgrade rule still has a pass/fail grade to evaluate
    // (DEC-0010, validated by TAREA-011) without adding a visible overall
    // column to the gradebook.
    if ($grademodel === EXELEARNING_GRADEMODEL_OVERALL) {
        // Overall only: the gradebook shows a single aggregated column (SCORM-style).
        exelearning_grade_item_update($instance);
    } else {
        // Per iDevice (default): keep the overall hidden for completion only.
        exelearning_grade_item_update($instance, null, ['hidden' => 1]);
    }

    // Detection.
    $detected = (new \mod_exelearning\local\package($elpx))->detect_gradable_idevices();

    // Record that this revision has been scanned for gradable iDevices, even when
    // none were found. Without this marker the view.php self-heal (which is keyed
    // on "has no gradable grade item") re-extracts and re-parses the whole ELPX
    // on EVERY view of a content-only package, since that condition stays
    // permanently true. Stored as max(revision, 1) so a package with revision=0
    // (e.g. a programmatic Playground upload) is still marked as scanned and not
    // re-extracted on every load.
    $DB->set_field(
        'exelearning',
        'gradesyncrev',
        max((int) $instance->revision, 1),
        ['id' => $exelearningid]
    );

    if ($detected === []) {
        return;
    }

    $existing = $DB->get_records(
        'exelearning_grade_item',
        ['exelearningid' => $exelearningid],
        '',
        'objectid, id, itemnumber, deleted'
    );

    $nextnum = (int) $DB->get_field_sql(
        "SELECT COALESCE(MAX(itemnumber),0) FROM {exelearning_grade_item}
             WHERE exelearningid = ?",
        [$exelearningid]
    );

    $seen = [];
    $capwarned = false;
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
            // Moodle 5.x can only label grade items whose itemnumber is declared
            // in the component mapping (gradeitems::MAX_ITEMNUMBER). Registering
            // beyond that creates columns Moodle cannot name, breaking the
            // completion-via-grade dropdown and Course overview labelling, so we
            // stop registering further iDevices once the cap is reached.
            if ($nextnum >= \mod_exelearning\grades\gradeitems::MAX_ITEMNUMBER) {
                if (!$capwarned) {
                    debugging(
                        'mod_exelearning: package has more than '
                            . \mod_exelearning\grades\gradeitems::MAX_ITEMNUMBER
                            . ' gradable iDevices; the extra items are not registered '
                            . 'as gradebook columns.',
                        DEBUG_DEVELOPER
                    );
                    $capwarned = true;
                }
                continue;
            }
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

        if ($grademodel === EXELEARNING_GRADEMODEL_OVERALL) {
            // Overall only: do not expose per-iDevice columns in the gradebook
            // (the row is kept for the attempts report).
            grade_update(
                'mod/exelearning',
                $instance->course,
                'mod',
                'exelearning',
                $instance->id,
                $itemnumber,
                null,
                ['deleted' => true]
            );
        } else {
            grade_update(
                'mod/exelearning',
                $instance->course,
                'mod',
                'exelearning',
                $instance->id,
                $itemnumber,
                null,
                [
                        'itemname'  => $name,
                        'gradetype' => GRADE_TYPE_VALUE,
                        'grademax'  => $instance->grademax ?? 100,
                        'grademin'  => $instance->grademin ?? 0,
                        'display'   => (int) ($instance->gradedisplaytype ?? GRADE_DISPLAY_TYPE_DEFAULT),
                ]
            );
        }
    }

    // Mark previously-known items that are gone as deleted, AND remove their
    // gradebook column. Marking our own row is not enough: the Moodle grade
    // item only disappears from the gradebook when grade_update() is called
    // with ['deleted' => true]. Grade history in grade_grades is preserved.
    foreach ($existing as $objectid => $row) {
        if (!isset($seen[$objectid]) && !$row->deleted) {
            $row->deleted = 1;
            $row->timemodified = time();
            $DB->update_record('exelearning_grade_item', $row);
            grade_update(
                'mod/exelearning',
                $instance->course,
                'mod',
                'exelearning',
                $instance->id,
                (int) $row->itemnumber,
                null,
                ['deleted' => true]
            );
        }
    }
}

/**
 * Recalculates a student's gradebook grades from their attempt history,
 * respecting grademethod and grademodel. Used after deleting an attempt
 * (DEC-0007 phase 2). If an item has no remaining attempts, clears its grade
 * (rawgrade=null).
 *
 * @param stdClass $instance
 * @param int $userid
 */
function exelearning_recalculate_user_grades(stdClass $instance, int $userid): void {
    global $CFG, $DB;
    require_once($CFG->libdir . '/gradelib.php');

    $grademax = (float) ($instance->grademax ?? 100);
    $grademethod = (int) ($instance->grademethod ?? \mod_exelearning\local\attempts::GRADE_HIGHEST);
    $grademodel = (int) ($instance->grademodel ?? EXELEARNING_GRADEMODEL_PERITEM);
    $base = [
        'gradetype' => GRADE_TYPE_VALUE,
        'grademax'  => $grademax,
        'grademin'  => $instance->grademin ?? 0,
        'display'   => (int) ($instance->gradedisplaytype ?? GRADE_DISPLAY_TYPE_DEFAULT),
    ];

    $items = [0 => clean_param($instance->name, PARAM_NOTAGS)];
    $rows = $DB->get_records(
        'exelearning_grade_item',
        ['exelearningid' => $instance->id, 'deleted' => 0],
        'itemnumber',
        'itemnumber, name'
    );
    foreach ($rows as $r) {
        $items[(int) $r->itemnumber] = $r->name;
    }

    foreach ($items as $itemnumber => $name) {
        if ($itemnumber === 0 && $grademodel !== EXELEARNING_GRADEMODEL_OVERALL) {
            $base['hidden'] = 1;
        } else {
            unset($base['hidden']);
        }
        if ($itemnumber > 0 && $grademodel === EXELEARNING_GRADEMODEL_OVERALL) {
            continue;
        }
        $scaled = \mod_exelearning\local\attempts::aggregate_scaled(
            $instance->id,
            $userid,
            $itemnumber,
            $grademethod
        );
        $grade = (object) [
            'userid'   => $userid,
            'rawgrade' => ($scaled === null) ? null : ($scaled * $grademax),
        ];
        grade_update(
            'mod/exelearning',
            $instance->course,
            'mod',
            'exelearning',
            $instance->id,
            $itemnumber,
            $grade,
            $base + ['itemname' => $name]
        );
    }
}

/**
 * Human-readable label for the gradebook column of an iDevice.
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

// -----------------------------------------------------------------------------
// Embedded eXeLearning editor support (ported from mod_exeweb).
//
// These functions are the hooks consumed by the embedded editor
// (editor/index.php, editor/save.php and the "Edit" button in view.php).

/**
 * Returns the URL of the ELPX stored in the 'package' filearea of an instance.
 *
 * Ported from mod_exeweb::exeweb_get_package_url() and adapted to this plugin:
 * filearea 'package', component 'mod_exelearning'. The itemid is taken from the
 * stored file (editor uploads use itemid = revision; form uploads use itemid = 0)
 * to build a URL servable via exelearning_pluginfile().
 *
 * @param stdClass $exelearning Instance record.
 * @param context $context Module context.
 * @return moodle_url|null URL to the package file, or null if it does not exist.
 */
function exelearning_get_package_url($exelearning, $context) {
    $fs = get_file_storage();
    $files = $fs->get_area_files(
        $context->id,
        'mod_exelearning',
        'package',
        false,
        'itemid DESC, sortorder DESC, id ASC',
        false
    );
    $package = reset($files);
    if (!$package) {
        return null;
    }
    return moodle_url::make_pluginfile_url(
        $context->id,
        'mod_exelearning',
        'package',
        $package->get_itemid(),
        $package->get_filepath(),
        $package->get_filename()
    );
}

/**
 * Returns the absolute path to the index.html of the installed embedded editor.
 *
 * Wrapper for embedded_editor_source_resolver::get_index_source() (moodledata →
 * bundled → null). Ported from mod_exeweb::exeweb_get_embedded_editor_index_source().
 *
 * @return string|null Path to index.html, or null when no editor is available.
 */
function exelearning_get_embedded_editor_index_source(): ?string {
    return \mod_exelearning\local\embedded_editor_source_resolver::get_index_source();
}

/**
 * Returns whether an embedded editor is available (moodledata or bundled).
 *
 * Used by view.php to decide whether to show the "Edit with eXeLearning" button.
 *
 * @return bool True when a valid local editor source exists.
 */
function exelearning_embedded_editor_enabled(): bool {
    return \mod_exelearning\local\embedded_editor_source_resolver::has_local_source();
}

/**
 * Whether a local editor asset bundle is available to be served by static.php.
 *
 * @return bool True when an admin-installed or bundled editor directory exists.
 */
function exelearning_embedded_editor_uses_local_assets(): bool {
    return \mod_exelearning\local\embedded_editor_source_resolver::has_local_source();
}

/**
 * Absolute path to the active editor static directory (moodledata → bundled),
 * used by editor/static.php to serve the editor's assets.
 *
 * @return string|null Directory path, or null when no editor is installed.
 */
function exelearning_get_embedded_editor_local_static_dir(): ?string {
    return \mod_exelearning\local\embedded_editor_source_resolver::get_active_dir();
}
