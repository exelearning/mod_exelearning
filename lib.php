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
    if (!isset($data->gradecat)) {
        $data->gradecat = 0;
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
    // Snapshot the pre-update grading model/method so we can tell a pure grading
    // change (re-aggregate valid attempts) from a package re-upload (DEC-0021
    // snapshot-and-warn) after the record is written (B2, DEC-0044).
    $oldrow = $DB->get_record(
        'exelearning',
        ['id' => $data->id],
        'revision, grademodel, grademethod',
        MUST_EXIST
    );
    $data->revision = (int) ($oldrow->revision ?: 0) + 1;
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
    if (!isset($data->gradecat)) {
        $data->gradecat = 0;
    }

    $DB->update_record('exelearning', $data);

    exelearning_save_and_extract_package($data);
    $contextid = context_module::instance($data->coursemodule)->id;
    $delta = exelearning_sync_grade_items($data->id, $contextid);

    // A pure grading model/method change leaves the stored attempts valid, but
    // exelearning_sync_grade_items() deletes and recreates the gradebook columns
    // empty (PERITEM<->OVERALL) or keeps them aggregated with the old method — so
    // the published grades would vanish or go stale until students resubmit.
    // Re-publish them from the attempt history (B2, DEC-0044). A package re-upload
    // (content change) is deliberately NOT recomputed here: it keeps DEC-0021
    // snapshot-and-warn semantics via exelearning_warn_if_grades_stale() below.
    if (
        (int) $data->grademodel !== (int) $oldrow->grademodel
        || (int) $data->grademethod !== (int) $oldrow->grademethod
    ) {
        exelearning_update_grades($data, 0);
    }

    // Re-uploading a package over an activity that already has attempts may add,
    // remove or re-score gradable iDevices; warn that old grades are not
    // recomputed (DEC-0021). The notice renders on the post-form redirect.
    exelearning_warn_if_grades_stale($data->id, $delta, (int) $data->coursemodule);

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
        // Reset only the grade items that actually exist in the gradebook. Looping
        // 0..MAX(itemnumber) and calling grade_update(['reset']) blindly used to
        // INSERT a bare, unnamed 100-point grade item for every itemnumber without
        // a live column — core grade_update() short-circuits a missing item only
        // for 'deleted', not 'reset'. In PERITEM the overall (0) never exists, in
        // OVERALL no per-iDevice item exists, and soft-deleted iDevices still raise
        // MAX(itemnumber), so a course reset spawned phantom columns that inflated
        // the course total (B3, DEC-0044).
        $items = grade_item::fetch_all([
            'itemtype'     => 'mod',
            'itemmodule'   => 'exelearning',
            'iteminstance' => $instance->id,
            'courseid'     => $courseid,
        ]);
        if (!$items) {
            continue;
        }
        foreach ($items as $item) {
            grade_update(
                'mod/exelearning',
                $courseid,
                'mod',
                'exelearning',
                $instance->id,
                (int) $item->itemnumber,
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

    // The overall (itemnumber=0) gradebook column only exists in OVERALL mode for a
    // graded activity (DEC-0008, DEC-0038). Core's grade_update_mod_grades() calls
    // this function UNCONDITIONALLY (before exelearning_update_grades()) on every
    // regrade — cron needsupdate, course reset "remove all grades", grade-item
    // unlock, user-undelete history recovery — so without this guard a PERITEM or
    // ungraded activity would get a phantom overall column that inflates the course
    // total (B2b follow-up, DEC-0044). When the overall must not exist, delete any
    // stray one instead of creating it.
    $grademodel = (int) ($exelearning->grademodel ?? EXELEARNING_GRADEMODEL_PERITEM);
    if (empty($exelearning->gradeenabled) || $grademodel !== EXELEARNING_GRADEMODEL_OVERALL) {
        return grade_update(
            'mod/exelearning',
            $exelearning->course,
            'mod',
            'exelearning',
            $exelearning->id,
            0,
            null,
            ['deleted' => true]
        );
    }

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
 * Re-publishes the activity's gradebook grades from the stored attempt history.
 *
 * This is the second half of the gradebook module contract: core's
 * grade_update_mod_grades() only re-syncs a module when BOTH
 * exelearning_grade_item_update() and exelearning_update_grades() exist. With
 * only the former declared, core did nothing (and logged "you have declared one
 * of ... but not both"), so course-reset "remove all grades", grade-item unlock
 * and user-undelete history recovery silently dropped every exelearning grade
 * while exelearning_attempt still held the data (B2b, DEC-0044).
 *
 * Each user's grade is re-aggregated from exelearning_attempt with the current
 * grademethod/grademodel via exelearning_recalculate_user_grades(), so it is also
 * the correct primitive to call after a pure grademodel/grademethod change, which
 * deletes and recreates the gradebook columns empty (B2).
 *
 * @param stdClass $exelearning The activity instance row.
 * @param int $userid Recalculate a single user (0 = every user with attempts).
 * @return void
 */
function exelearning_update_grades(stdClass $exelearning, int $userid = 0): void {
    global $CFG, $DB;
    require_once($CFG->libdir . '/gradelib.php');

    // Not graded (DEC-0029): no grade items exist, so there is nothing to publish.
    if (empty($exelearning->gradeenabled)) {
        return;
    }

    if ($userid > 0) {
        exelearning_recalculate_user_grades($exelearning, $userid);
        return;
    }

    $userids = $DB->get_fieldset_sql(
        'SELECT DISTINCT userid FROM {exelearning_attempt} WHERE exelearningid = ?',
        [$exelearning->id]
    );
    if (empty($userids)) {
        return;
    }
    exelearning_recalculate_grades_for_users($exelearning, array_map('intval', $userids));
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
    global $PAGE, $DB;

    $context = $PAGE->cm->context ?? null;
    if (!$context || !has_capability('mod/exelearning:viewreport', $context)) {
        return;
    }
    // No reports node when the activity is not graded (DEC-0029).
    if (!$DB->get_field('exelearning', 'gradeenabled', ['id' => $PAGE->cm->instance])) {
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
    global $USER;

    if (empty($data->package)) {
        return;
    }
    $context = context_module::instance($data->coursemodule);
    $fs = get_file_storage();

    // Safety net against destroying the stored package (B1, DEC-0044). The
    // submitted value is a draft itemid that is non-empty even when it carries no
    // file; saving such an empty draft used to delete every stored package itemid
    // (the form reads itemid 0 but the embedded editor stores at itemid=revision),
    // leaving the activity with no content and the source .elpx unrecoverable.
    // When the incoming draft has no file but a package is already stored, keep
    // the existing one and just (re-)extract it to the current revision instead of
    // wiping it. data_preprocessing() seeds the draft from the stored package, so
    // a genuine settings save (or a real upload/replacement) still round-trips a
    // non-empty draft and falls through to the normal path below.
    $usercontext = context_user::instance($USER->id);
    $draftfiles = $fs->get_area_files(
        $usercontext->id,
        'user',
        'draft',
        (int) $data->package,
        'id',
        false
    );
    if (empty($draftfiles) && exelearning_get_stored_package($context->id) !== null) {
        exelearning_extract_stored_package($context->id, (int) $data->revision);
        return;
    }

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
 * Whether a stored package archive is a real eXeLearning v4 package.
 *
 * Both `.elpx` and `.zip` are accepted on upload (DEC-0027); the genuine marker is
 * a `content.xml` (ODE 2.0) entry at the archive root, which every eXeLearning v4
 * export contains. Used by mod_form to reject an arbitrary .zip at submit time.
 *
 * @param \stored_file $file The uploaded package (.elpx or .zip).
 * @return bool True when the archive contains content.xml at its root.
 */
function exelearning_package_has_content_xml(\stored_file $file): bool {
    $packer = get_file_packer('application/zip');
    $entries = $file->list_files($packer);
    if (!is_array($entries)) {
        return false;
    }
    foreach ($entries as $entry) {
        if ($entry->pathname === 'content.xml') {
            return true;
        }
    }
    return false;
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

    // 7) Make the two iDevices that gate their score-save on the `exe-scorm` body
    // class also save in this web-export embedding (issue #13). All other gradable
    // iDevices save on `isScorm > 0` alone; only `form` and `scrambled-list` add a
    // `body.hasClass('exe-scorm')` condition, which is absent here (we serve a web
    // export). We drop that condition from their save guard at serve time.
    exelearning_patch_idevice_save_guards($context->id, (int) $data->revision);
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
 * Drop the `body.exe-scorm` condition from the score-save guard of the `form` and
 * `scrambled-list` iDevices in the extracted package (issue #13).
 *
 * eXeLearning's `exe-scorm` body class is its "running as a SCORM export" switch.
 * The web/elpx export we serve does not carry it, and 49 of 51 iDevices either do
 * not touch it or only use it to load the SCORM wrapper (which we already inject).
 * Only `form` and `scrambled-list` put `body.hasClass('exe-scorm')` in front of
 * their `sendScore()` call, so they never persist their score here — their
 * cmi.suspend_data entry stays at the seeded 0 (the gradebook shows 0).
 *
 * Rather than add `exe-scorm` to the body (which would also switch on the SCO page
 * lifecycle and the SCORM presentation CSS), we apply the same one-line change
 * upstream describes (exelearning/exelearning#1925) at serve time, only to these
 * two save guards, so they behave like every other gradable iDevice (save on
 * `isScorm > 0`). The patch targets the unique `data.isScorm` variant of the guard
 * (the init-time guards use `ldata.isScorm`), is idempotent (the matched string is
 * removed), and degrades safely: if a future producer reformats the guard the
 * replace is a no-op and behaviour reverts to today's. See research ADR DEC-0042.
 *
 * @param int $contextid
 * @param int $revision
 */
function exelearning_patch_idevice_save_guards(int $contextid, int $revision): void {
    $fs = get_file_storage();
    // Map of iDevice JS filename => [ exact save-guard string => replacement ].
    $patches = [
        'form.js' => [
            '$(\'body\').hasClass(\'exe-scorm\') && data.isScorm > 0' => 'data.isScorm > 0',
        ],
        'scrambled-list.js' => [
            'document.body.classList.contains(\'exe-scorm\') && data.isScorm > 0' => 'data.isScorm > 0',
        ],
    ];
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
        if (!isset($patches[$name])) {
            continue;
        }
        $content = $file->get_content();
        $newcontent = $content;
        foreach ($patches[$name] as $search => $replace) {
            if (strpos($newcontent, $search) !== false) {
                $newcontent = str_replace($search, $replace, $newcontent);
            }
        }
        if ($newcontent === $content) {
            continue;
        }
        $file->delete();
        $fs->create_file_from_string([
            'contextid' => $contextid,
            'component' => 'mod_exelearning',
            'filearea'  => 'content',
            'itemid'    => $revision,
            'filepath'  => $file->get_filepath(),
            'filename'  => $name,
        ], $newcontent);
    }
}

/**
 * Removes all gradebook items of an activity (master grading switch off, DEC-0029).
 *
 * Soft-deletes the plugin's grade-item mapping rows and deletes the matching Moodle
 * grade items, including the overall item (itemnumber 0), so nothing shows in the
 * gradebook. The attempt history (exelearning_attempt) is preserved, so re-enabling
 * grading re-detects and recomputes from it.
 *
 * @param stdClass $instance The exelearning instance row.
 * @return void
 */
function exelearning_remove_all_grade_items(stdClass $instance): void {
    global $CFG, $DB;
    require_once($CFG->libdir . '/gradelib.php');

    $rows = $DB->get_records('exelearning_grade_item', ['exelearningid' => $instance->id, 'deleted' => 0]);
    foreach ($rows as $row) {
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
    // Remove the overall item (itemnumber 0) too.
    grade_update(
        'mod/exelearning',
        $instance->course,
        'mod',
        'exelearning',
        $instance->id,
        0,
        null,
        ['deleted' => true]
    );
}

/**
 * Detects gradable iDevices in the stored package and synchronises grade items.
 *
 * Returns the change delta against the previously synced state so callers can
 * warn the teacher when editing a graded package alters the gradable set
 * (DEC-0021). "changed" means the same objectid whose content block hash
 * differs, i.e. an in-place options/scoring edit.
 *
 * @param int $exelearningid
 * @param int|null $contextid
 * @return array{added:int,removed:int,changed:int}
 */
function exelearning_sync_grade_items(int $exelearningid, ?int $contextid = null): array {
    global $CFG, $DB;
    require_once($CFG->libdir . '/gradelib.php');

    $delta = ['added' => 0, 'removed' => 0, 'changed' => 0];

    $instance = $DB->get_record('exelearning', ['id' => $exelearningid], '*', MUST_EXIST);

    if ($contextid === null) {
        $cm = get_coursemodule_from_instance('exelearning', $exelearningid);
        if (!$cm) {
            return $delta;
        }
        $contextid = context_module::instance($cm->id)->id;
    }
    $context = context::instance_by_id($contextid);

    // Master grading switch (DEC-0029): when the activity is not graded, remove all
    // gradebook items (soft-delete our rows + delete the Moodle grade items, overall
    // included) and detect nothing. Attempt history (exelearning_attempt) is kept.
    if (empty($instance->gradeenabled)) {
        exelearning_remove_all_grade_items($instance);
        return $delta;
    }

    // Locate the ELPX in the 'package' filearea (any itemid: form=0,
    // Playground addModule=1, editor/save.php=revision).
    $elpx = exelearning_get_stored_package($context->id);
    if (!$elpx instanceof \stored_file) {
        return $delta;
    }

    $grademodel = (int) ($instance->grademodel ?? EXELEARNING_GRADEMODEL_PERITEM);

    // Canonical grade item (itemnumber=0) according to the grading model
    // (DEC-0008, revised by DEC-0038). The two models are now symmetric: OVERALL
    // shows only the aggregated column, PERITEM shows only the per-iDevice
    // columns. There is no longer a hidden overall stub in PERITEM — a hidden
    // item still shows (greyed) to teachers with moodle/grade:viewhidden and was
    // reported as a confusing "extra grade" (DEC-0038). Completion-by-grade keeps
    // working the Moodle-native way: the teacher points completiongradeitemnumber
    // at a per-iDevice item (workshop model), or uses OVERALL mode to complete on
    // passing the activity as a whole (DEC-0010).
    if ($grademodel === EXELEARNING_GRADEMODEL_OVERALL) {
        // Overall only: the gradebook shows a single aggregated column (SCORM-style).
        // Pass hidden=0 explicitly so switching PERITEM -> OVERALL un-hides the
        // overall item; grade_update() leaves the flag untouched otherwise and the
        // column would stay hidden from when it was the completion-only stub.
        exelearning_grade_item_update($instance, null, ['hidden' => 0]);
    } else {
        // Per iDevice (default): no overall column at all. Delete any overall left
        // over from a previous sync or from the legacy hidden-stub model (DEC-0038).
        grade_update(
            'mod/exelearning',
            $instance->course,
            'mod',
            'exelearning',
            $instance->id,
            0,
            null,
            ['deleted' => true]
        );
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
        // No gradable iDevices: PERITEM has no grade items at all, OVERALL keeps
        // its single aggregated column. Place whatever exists under the configured
        // grade category (DEC-0034); a no-op when there are no items.
        exelearning_apply_grade_category($instance);
        return $delta;
    }

    $existing = $DB->get_records(
        'exelearning_grade_item',
        ['exelearningid' => $exelearningid],
        '',
        'objectid, id, itemnumber, deleted, contenthash'
    );

    $nextnum = (int) $DB->get_field_sql(
        "SELECT COALESCE(MAX(itemnumber),0) FROM {exelearning_grade_item}
             WHERE exelearningid = ?",
        [$exelearningid]
    );

    $seen = [];
    $capwarned = false;
    foreach ($detected as $d) {
        // Clamp the package-controlled identifiers to their column widths before
        // they are used as the $existing lookup key or written to the DB, so an
        // adversarial/overlong content.xml cannot throw a dml_write_exception
        // mid-sync (a student-facing fatal through the view.php self-heal) (B5,
        // DEC-0044). objectid/pageid are char(191), idevicetype char(64).
        $d->idevicetype = core_text::substr((string) $d->idevicetype, 0, 64);
        $d->objectid    = core_text::substr((string) $d->objectid, 0, 191);
        $d->pageid      = ($d->pageid === null)
            ? null
            : core_text::substr((string) $d->pageid, 0, 191);

        $name = exelearning_grade_item_name($instance, $d);
        $now = time();

        $newhash = $d->contenthash ?? null;

        if (isset($existing[$d->objectid])) {
            $row = $existing[$d->objectid];
            // An in-place options/scoring edit keeps the objectid but changes
            // the content block hash; a re-appearing (un-deleted) iDevice also
            // counts as a change worth flagging. Rows synced before this column
            // existed have a NULL hash: backfill silently, never flag, so the
            // first sync after upgrade does not warn on every iDevice.
            $oldhash = $row->contenthash ?? null;
            if (($oldhash !== null && $oldhash !== $newhash) || (int) $row->deleted === 1) {
                $delta['changed']++;
            }
            $row->name         = $name;
            $row->idevicetype  = $d->idevicetype;
            $row->pageid       = $d->pageid;
            $row->deleted      = 0;
            $row->contenthash  = $newhash;
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
            $delta['added']++;
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
                'contenthash'   => $newhash,
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
            $delta['removed']++;
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

    // Place the overall and every per-iDevice column under the configured grade
    // category (DEC-0034); grade_update() above cannot do this itself.
    exelearning_apply_grade_category($instance);

    return $delta;
}

/**
 * Queues a teacher-facing warning when editing a graded package changed its
 * gradable set while student attempts already exist (DEC-0021).
 *
 * mod_exelearning keeps the snapshot semantics of mod_scorm / mod_h5pactivity:
 * existing attempts and the grades derived from them are NOT recomputed when the
 * content changes — the scoring runs client-side, so the server cannot re-derive
 * a past attempt's score against the new content. Mirroring mod_scorm's
 * "confirmloosetracks" notice, we tell the teacher so they can reset attempts
 * from the report if the edited tasks make the old grades misleading.
 *
 * @param int $exelearningid
 * @param array $delta From exelearning_sync_grade_items(): keys added, removed, changed.
 * @param int|null $cmid Course module id, to link the attempts report.
 * @return void
 */
function exelearning_warn_if_grades_stale(int $exelearningid, array $delta, ?int $cmid = null): void {
    $changes = (int) ($delta['added'] ?? 0)
        + (int) ($delta['removed'] ?? 0)
        + (int) ($delta['changed'] ?? 0);
    if ($changes === 0) {
        return;
    }
    if (!\mod_exelearning\local\attempts::activity_has_attempts($exelearningid)) {
        return;
    }
    $message = get_string('gradesetchangedwarning', 'mod_exelearning');
    if ($cmid !== null) {
        $url = new moodle_url('/mod/exelearning/report.php', ['id' => $cmid]);
        $message .= ' ' . html_writer::link($url, get_string('attemptsreport', 'mod_exelearning'));
    }
    \core\notification::warning($message);
}

/**
 * Recalculates a student's gradebook grades from their attempt history,
 * respecting grademethod and grademodel. Used after deleting an attempt
 * (DEC-0007 phase 2). If an item has no remaining attempts, clears its grade
 * (rawgrade=null).
 *
 * Single-user façade kept for its existing callers (report.php attempt deletion,
 * privacy provider erasure). Delegates to exelearning_recalculate_grades_for_users()
 * so the aggregation/publish logic lives in one place.
 *
 * @param stdClass $instance
 * @param int $userid
 */
function exelearning_recalculate_user_grades(stdClass $instance, int $userid): void {
    exelearning_recalculate_grades_for_users($instance, [$userid]);
}

/**
 * Recalculates the gradebook grades of several users in a single batch.
 *
 * Bulk entry point for exelearning_update_grades($exelearning, 0): one SELECT for
 * every user's attempts (attempts::fetch_scaled_by_user_item()), an in-memory
 * group-by, and one grade_update() per itemnumber with the grades keyed by userid.
 * This replaces the former users × items N+1 (one SELECT and one grade_update()
 * per user per item).
 *
 * Aggregation respects grademethod (DEC-0007, via attempts::aggregate_values()) and
 * the grademodel column rules: PERITEM has no overall column (DEC-0038), OVERALL has
 * no per-iDevice columns (DEC-0008). A user with no attempts for an item gets a null
 * rawgrade, clearing any stale grade.
 *
 * @param stdClass $instance
 * @param int[] $userids Users to recalculate; empty array is a no-op.
 */
function exelearning_recalculate_grades_for_users(stdClass $instance, array $userids): void {
    global $CFG, $DB;
    require_once($CFG->libdir . '/gradelib.php');

    if (empty($userids)) {
        return;
    }

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

    // One query for every attempt of every user, grouped by user and item.
    $byuser = \mod_exelearning\local\attempts::fetch_scaled_by_user_item($instance->id, $userids);

    foreach ($items as $itemnumber => $name) {
        unset($base['hidden']);
        // PERITEM has no overall column (DEC-0038): never (re)publish item 0 there,
        // which would recreate it. OVERALL has no per-iDevice columns.
        if ($itemnumber === 0 && $grademodel !== EXELEARNING_GRADEMODEL_OVERALL) {
            continue;
        }
        if ($itemnumber > 0 && $grademodel === EXELEARNING_GRADEMODEL_OVERALL) {
            continue;
        }
        // One grade_update() per item with the grades keyed by userid; core's
        // grade_update() accepts an array of grade objects.
        $grades = [];
        foreach ($userids as $uid) {
            $scaled = \mod_exelearning\local\attempts::aggregate_values(
                $byuser[$uid][$itemnumber] ?? [],
                $grademethod
            );
            $grades[$uid] = (object) [
                'userid'   => $uid,
                'rawgrade' => ($scaled === null) ? null : ($scaled * $grademax),
            ];
        }
        grade_update(
            'mod/exelearning',
            $instance->course,
            'mod',
            'exelearning',
            $instance->id,
            $itemnumber,
            $grades,
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
    $name = ($page !== '') ? ($base . ' · ' . $page . ' · ' . $type) : ($base . ' · ' . $type);
    // Clamp to the exelearning_grade_item.name column width (char 255). The page
    // title comes from author-controlled content.xml and is unbounded; combined
    // with an up-to-255-char activity name it can exceed 255 and throw a
    // dml_write_exception on sync — which, via the view.php self-heal, is a
    // student-facing fatal (B5, DEC-0044). core_text::substr is multibyte-safe and
    // deterministic, so re-sync does not thrash the stored name.
    return core_text::substr($name, 0, 255);
}

/**
 * Relaxes core's "completion grade item has no grade field" validation error for a
 * registered gradable item (B7, DEC-0044).
 *
 * Core's moodleform_mod::validation() rejects every completiongradeitemnumber with a
 * badcompletiongradeitemnumber error (key 'completionpassgrade') because
 * mod_exelearning maps 101 itemnumbers (gradeitems::MAX_ITEMNUMBER) but stores each
 * grade in its own table instead of exposing per-itemnumber grade_ideviceN form
 * fields — so core's "this item has no grade field" check always fails, making the
 * DEC-0038 completion-by-grade feature impossible to save from the form. This
 * stopgap clears that specific error when "require passing grade" is OFF and the
 * chosen item is a real gradebook column (a per-iDevice item in PERITEM, or the
 * overall in OVERALL): it does carry a grade, just not via a core form field.
 * Because it only fires when completionpassgrade is unchecked, it never masks the
 * legitimate "grade to pass not set" validation. "Require passing grade" needs a
 * core_grades fieldname_mapping to validate the threshold and is left to that proper
 * fix (deferred). Kept as a pure function so it is unit-testable without building the
 * whole moodleform_mod (which couples to core availability/tags/completion fields).
 *
 * @param array $errors The errors array from moodleform_mod::validation().
 * @param array $data The submitted form data.
 * @param int $exelearningid The instance id (0 on a brand-new activity).
 * @return array The (possibly relaxed) errors array.
 */
function exelearning_relax_completion_grade_errors(array $errors, array $data, int $exelearningid): array {
    global $DB;

    $selected = $data['completiongradeitemnumber'] ?? null;
    if (
        $selected === null || $selected === ''
        || !empty($data['completionpassgrade'])
        || !isset($errors['completionpassgrade'])
    ) {
        return $errors;
    }

    $itemnumber = (int) $selected;
    $grademodel = (int) ($data['grademodel'] ?? EXELEARNING_GRADEMODEL_PERITEM);
    // A real gradebook column exists for the overall (0) only in OVERALL mode, and
    // for a per-iDevice item only in PERITEM mode — OVERALL deletes the per-iDevice
    // Moodle columns (DEC-0038), so completion must not target one there even though
    // its exelearning_grade_item row is kept for the report.
    $registered = ($itemnumber === 0)
        ? ($grademodel === EXELEARNING_GRADEMODEL_OVERALL)
        : ($grademodel === EXELEARNING_GRADEMODEL_PERITEM
            && $DB->record_exists('exelearning_grade_item', [
                'exelearningid' => $exelearningid,
                'itemnumber'    => $itemnumber,
                'deleted'       => 0,
            ]));
    if ($registered) {
        unset($errors['completionpassgrade']);
    }
    return $errors;
}

/**
 * Places every grade item of the activity under the configured grade category.
 *
 * The grade category selector (DEC-0034) is stored on exelearning.gradecat, but
 * grade_update() silently ignores the categoryid key (it is not in its allowed
 * field list), so the parent category must be set with grade_item::set_parent() —
 * the same API course/modlib.php uses for core's "Grade category" dropdown.
 * Applied to the overall and every per-iDevice item so a re-upload that adds
 * columns inherits the category too. gradecat=0 leaves the items where Moodle put
 * them (the course top category). A target category that no longer exists (e.g. a
 * cross-course restore) makes set_parent() a no-op, so items stay valid.
 *
 * @param stdClass $instance The exelearning instance (must carry id, course, gradecat).
 * @return void
 */
function exelearning_apply_grade_category(stdClass $instance): void {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    $categoryid = (int) ($instance->gradecat ?? 0);
    if ($categoryid <= 0) {
        return;
    }
    $items = grade_item::fetch_all([
        'itemtype'     => 'mod',
        'itemmodule'   => 'exelearning',
        'iteminstance' => $instance->id,
        'courseid'     => $instance->course,
    ]);
    if (!$items) {
        return;
    }
    foreach ($items as $item) {
        if ((int) $item->categoryid !== $categoryid) {
            $item->set_parent($categoryid);
        }
    }
}

// Note: exelearning_exclude_overall_grade() (DEC-0035) was removed in DEC-0038.
// It excluded the hidden overall (itemnumber=0) from aggregation so it would not
// blank the student's total. PERITEM no longer creates an overall item at all, so
// there is nothing to exclude and the root cause (a hidden item that aggregates)
// is gone.

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
 * Builds the activity view URL a gradebook grade item should resolve to.
 *
 * The Moodle gradebook links each activity grade item to /mod/exelearning/grade.php
 * passing its itemnumber (same pattern as core mod_h5pactivity). This maps that
 * itemnumber to the owning iDevice's stable objectid so the view can deep-link
 * straight to that iDevice instead of the resource front page (issue #13 #4,
 * DEC-0023). itemnumber 0 (the overall grade) links to the front page.
 *
 * @param stdClass $exelearning Instance record.
 * @param int $cmid Course module id.
 * @param int $itemnumber Grade item number (0 = overall, > 0 = per-iDevice).
 * @return moodle_url View URL, with an `idevice` parameter when one is known.
 */
function exelearning_grade_item_view_url(stdClass $exelearning, int $cmid, int $itemnumber): moodle_url {
    global $DB;

    $params = ['id' => $cmid];
    if ($itemnumber > 0) {
        $objectid = $DB->get_field('exelearning_grade_item', 'objectid', [
            'exelearningid' => $exelearning->id,
            'itemnumber'    => $itemnumber,
            'deleted'       => 0,
        ]);
        if (!empty($objectid)) {
            $params['idevice'] = $objectid;
        }
    }
    return new moodle_url('/mod/exelearning/view.php', $params);
}

/**
 * Builds the destination of a gradebook "grade analysis" click, by role.
 *
 * The gradebook column header is fixed by Moodle core to view.php and cannot be
 * deep-linked by a plugin; the per-grade "grade analysis" link (which appears because
 * this module ships grade.php) is the only place we can target. Teachers/graders go to
 * the attempts report (the actual attempt behind the grade); students are deep-linked
 * to the specific iDevice in the content (issue #13 #4, DEC-0028).
 *
 * @param stdClass $exelearning Instance record.
 * @param int $cmid Course module id.
 * @param int $itemnumber Grade item number (0 = overall).
 * @param context $context Module context (for the capability check).
 * @param int $userid Graded user, forwarded to the report so the teacher lands on
 *                    that student's attempts (0 = no user filter).
 * @return moodle_url
 */
function exelearning_grade_analysis_url(
    stdClass $exelearning,
    int $cmid,
    int $itemnumber,
    context $context,
    int $userid = 0
): moodle_url {
    if (has_capability('mod/exelearning:viewreport', $context)) {
        $params = ['id' => $cmid];
        if ($userid > 0) {
            $params['userid'] = $userid;
        }
        return new moodle_url('/mod/exelearning/report.php', $params);
    }
    return exelearning_grade_item_view_url($exelearning, $cmid, $itemnumber);
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
