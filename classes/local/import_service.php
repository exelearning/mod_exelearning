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

namespace mod_exelearning\local;

/**
 * Site-wide migration of activities from mod_exeweb / mod_exescorm (issue #13 #3, DEC-0026).
 *
 * Driven from the plugin admin settings (see admin/migrate.php): the administrator
 * bulk-copies every sibling activity across all courses into a new eXeLearning
 * activity. It is NON-destructive — the original activities are kept untouched, so
 * the admin can verify before removing the old plugin.
 *
 * Both siblings store their uploaded package the same way this plugin does — the
 * `package` filearea at itemid 0 of their module context — so the core of a
 * migration is "fetch an .elpx and run the normal extract + grade-item sync":
 *  - mod_exeweb stores a native `.elpx` → copied verbatim (per-iDevice grademodel).
 *  - mod_exescorm stores a SCORM `.zip`; an `.elpx` is only recoverable when the
 *    package was exported "with editable source". We extract that embedded `.elpx`,
 *    create the activity with the OVERALL grademodel, and copy each user's final
 *    grade to the overall grade item. Without an embedded source the activity is
 *    skipped (no lossy SCORM→eXe reverse conversion). Only the modern `.elpx` v4 is
 *    accepted (legacy `.elp` is out of scope, see AGENTS.md).
 *
 * {@see self::import_package()} works purely on the file storage so it is
 * unit-testable without the sibling plugins installed.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class import_service {
    /** @var string[] Sibling module names (without the 'mod_' frankenstyle prefix) we can migrate from. */
    public const SUPPORTED_MODULES = ['exeweb', 'exescorm'];

    /**
     * Lists every activity of a sibling module across the whole site.
     *
     * Returns an empty array when the sibling plugin is not installed (its table is
     * absent), so the migration UI hides itself.
     *
     * @param string $sibling 'exeweb' or 'exescorm'.
     * @return \stdClass[] Rows with: cmid, course, sectionnum, instanceid, name, coursename.
     */
    public static function list_all_sources(string $sibling): array {
        global $DB;

        if (!in_array($sibling, self::SUPPORTED_MODULES, true)) {
            return [];
        }
        if (!$DB->get_manager()->table_exists($sibling)) {
            return [];
        }
        // The $sibling value is whitelisted above, so embedding it as a table name is safe.
        $sql = "SELECT cm.id AS cmid, a.course, cs.section AS sectionnum, a.id AS instanceid,
                       a.name, c.fullname AS coursename
                  FROM {" . $sibling . "} a
                  JOIN {modules} m ON m.name = :mname
                  JOIN {course_modules} cm ON cm.module = m.id AND cm.instance = a.id
                  JOIN {course_sections} cs ON cs.id = cm.section
                  JOIN {course} c ON c.id = a.course
              ORDER BY a.course, cm.id";
        return array_values($DB->get_records_sql($sql, ['mname' => $sibling]));
    }

    /**
     * Counts the migratable activities of a sibling module across the site.
     *
     * @param string $sibling 'exeweb' or 'exescorm'.
     * @return int Number of source activities.
     */
    public static function count_sources(string $sibling): int {
        return count(self::list_all_sources($sibling));
    }

    /**
     * Migrates every activity of a sibling module across the site.
     *
     * @param string $sibling 'exeweb' or 'exescorm'.
     * @param \progress_bar|null $progress Optional progress bar updated per activity.
     * @return \stdClass[] One result per source (see {@see self::migresult()}).
     */
    public static function migrate_all(string $sibling, ?\progress_bar $progress = null): array {
        $sources = self::list_all_sources($sibling);
        $total = count($sources);
        $results = [];
        $done = 0;
        foreach ($sources as $source) {
            $done++;
            if ($progress !== null) {
                $progress->update(
                    $done,
                    $total,
                    get_string('migrateprogress', 'mod_exelearning', (object) [
                        'done'  => $done,
                        'total' => $total,
                        'name'  => $source->name,
                    ])
                );
            }
            $results[] = self::migrate_one($sibling, $source);
        }
        return $results;
    }

    /**
     * Migrates a single sibling activity into a new eXeLearning activity.
     *
     * Idempotent: a source already recorded in {exelearning_migration} is skipped.
     * exescorm without an embedded .elpx source is skipped (its original is kept).
     *
     * @param string $sibling 'exeweb' or 'exescorm'.
     * @param \stdClass $source A row from {@see self::list_all_sources()}.
     * @return \stdClass Result object (see {@see self::migresult()}).
     */
    public static function migrate_one(string $sibling, \stdClass $source): \stdClass {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/mod/exelearning/lib.php');

        $component = 'mod_' . $sibling;

        if (
            $DB->record_exists('exelearning_migration', [
            'sourcecomponent' => $component,
            'sourcecmid'      => (int) $source->cmid,
            ])
        ) {
            return self::migresult($source, 'alreadymigrated');
        }

        // Resolve the source content first so we never create an empty shell when
        // there is nothing to import (e.g. a SCORM without editable source).
        $sourcecontextid = (int) \context_module::instance($source->cmid)->id;
        $elpxpath = self::resolve_source_elpx($sourcecontextid, $component);
        if ($elpxpath === null) {
            return self::migresult($source, 'nosource');
        }

        // The exescorm sibling migrates as a single overall grade (SCORM-style); exeweb
        // keeps the default per-iDevice model and detects gradable iDevices from the .elpx.
        $grademodel = ($sibling === 'exescorm')
            ? EXELEARNING_GRADEMODEL_OVERALL
            : EXELEARNING_GRADEMODEL_PERITEM;

        try {
            $target = self::create_target_module(
                (int) $source->course,
                (int) $source->sectionnum,
                (string) $source->name,
                $grademodel
            );
            self::install_elpx_into_target($elpxpath, $target->instance, $target->contextid);

            if ($sibling === 'exescorm') {
                self::migrate_grades_overall(
                    (int) $source->course,
                    $component,
                    (int) $source->instanceid,
                    $target->instance
                );
            }

            $DB->insert_record('exelearning_migration', (object) [
                'sourcecomponent' => $component,
                'sourcecmid'      => (int) $source->cmid,
                'targetcmid'      => (int) $target->cm->id,
                'timecreated'     => time(),
            ]);
        } catch (\Throwable $e) {
            return self::migresult($source, 'error', $e->getMessage());
        }

        return self::migresult($source, 'migrated', '', (int) $target->cm->id);
    }

    /**
     * Creates an empty eXeLearning activity in the given course/section.
     *
     * Mirrors the add_moduleinfo() pattern used by scripts/setup_demo.php. The
     * package is left empty here; the caller imports the content afterwards.
     *
     * @param int $courseid Target course id.
     * @param int $sectionnum Section number to place the activity in.
     * @param string $name Activity name.
     * @param int $grademodel EXELEARNING_GRADEMODEL_OVERALL or _PERITEM.
     * @return \stdClass {cm: cm_info-like row, instance: exelearning row, contextid: int}.
     */
    public static function create_target_module(
        int $courseid,
        int $sectionnum,
        string $name,
        int $grademodel
    ): \stdClass {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->dirroot . '/mod/exelearning/lib.php');

        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        $moduleid = $DB->get_field('modules', 'id', ['name' => 'exelearning'], MUST_EXIST);

        $moduleinfo = (object) [
            'modulename'          => 'exelearning',
            'module'              => $moduleid,
            'course'              => $courseid,
            'section'             => $sectionnum,
            'visible'             => 1,
            'visibleoncoursepage' => 1,
            'name'                => $name,
            'intro'               => '',
            'introformat'         => FORMAT_HTML,
            'grademodel'          => $grademodel,
            'grademax'            => 100,
            'grademin'            => 0,
            'gradepass'           => 0,
            'grademethod'         => attempts::GRADE_HIGHEST,
            'gradedisplaytype'    => 0,
            'cmidnumber'          => '',
            'groupmode'           => NOGROUPS,
            'groupingid'          => 0,
        ];

        $created = add_moduleinfo($moduleinfo, $course);

        $cm = get_coursemodule_from_id('exelearning', $created->coursemodule, 0, false, MUST_EXIST);
        $instance = $DB->get_record('exelearning', ['id' => $created->instance], '*', MUST_EXIST);
        return (object) [
            'cm'        => $cm,
            'instance'  => $instance,
            'contextid' => (int) \context_module::instance($cm->id)->id,
        ];
    }

    /**
     * Copies each user's final grade from a source activity to the target's overall
     * grade item (itemnumber 0).
     *
     * Reads the gradebook final grade for the source cm and republishes it through
     * the plugin's own overall grade item. No exelearning_attempt rows are created —
     * the gradebook grade is sufficient and authoritative for migrated data.
     *
     * @param int $courseid Course id (shared by source and target).
     * @param string $sourcecomponent Frankenstyle of the source module (e.g. mod_exescorm).
     * @param int $sourceinstanceid Source activity instance id.
     * @param \stdClass $target Target exelearning instance row.
     * @return int Number of user grades migrated.
     */
    public static function migrate_grades_overall(
        int $courseid,
        string $sourcecomponent,
        int $sourceinstanceid,
        \stdClass $target
    ): int {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->dirroot . '/mod/exelearning/lib.php');

        $itemmodule = substr($sourcecomponent, strlen('mod_'));
        // Read the source activity's overall grade item and every stored grade
        // directly (covers all users with a grade, not just currently enrolled ones).
        $sourceitem = \grade_item::fetch([
            'courseid'     => $courseid,
            'itemtype'     => 'mod',
            'itemmodule'   => $itemmodule,
            'iteminstance' => $sourceinstanceid,
            'itemnumber'   => 0,
        ]);
        if (!$sourceitem) {
            return 0;
        }
        // Collect the users with a stored grade, then read their computed final
        // grades. grade_get_grades() triggers a regrade if the final grade is
        // pending and requires explicit user ids to return the values.
        $sourcerows = \grade_grade::fetch_all(['itemid' => $sourceitem->id]);
        if (!$sourcerows) {
            return 0;
        }
        $userids = array_values(array_unique(array_map(
            static fn($row) => (int) $row->userid,
            $sourcerows
        )));
        $sourcegrades = grade_get_grades($courseid, 'mod', $itemmodule, $sourceinstanceid, $userids);
        $sourceitemgrades = $sourcegrades->items[0]->grades ?? [];

        $targetmax = (float) ($target->grademax ?? 100);
        $sourcemax = (float) ($sourcegrades->items[0]->grademax ?? ($sourceitem->grademax ?: 100));

        $grades = [];
        foreach ($sourceitemgrades as $userid => $gradeinfo) {
            if (!isset($gradeinfo->grade) || $gradeinfo->grade === null || $gradeinfo->grade === '') {
                continue;
            }
            $value = (float) $gradeinfo->grade;
            // Rescale only when the source and target maxima differ, then clamp.
            if ($sourcemax > 0 && abs($sourcemax - $targetmax) > 0.00001) {
                $value = ($value / $sourcemax) * $targetmax;
            }
            $value = max(0.0, min($targetmax, $value));
            $grades[(int) $userid] = (object) ['userid' => (int) $userid, 'rawgrade' => $value];
        }

        if (empty($grades)) {
            return 0;
        }
        exelearning_grade_item_update($target, $grades);
        return count($grades);
    }

    /**
     * Copies/derives an .elpx from a sibling's 'package' filearea into the target
     * exelearning instance, then extracts it and synchronises grade items.
     *
     * Kept as the storage-level engine (unit-testable without the siblings).
     *
     * @param int $sourcecontextid Context id of the source module.
     * @param string $sourcecomponent 'mod_exeweb' or 'mod_exescorm'.
     * @param \stdClass $target Target exelearning instance row.
     * @param int $targetcontextid Target module context id.
     * @return \stdClass {success: bool, status: string, message: string}.
     */
    public static function import_package(
        int $sourcecontextid,
        string $sourcecomponent,
        \stdClass $target,
        int $targetcontextid
    ): \stdClass {
        $elpxpath = self::resolve_source_elpx($sourcecontextid, $sourcecomponent);
        if ($elpxpath === null) {
            return self::result(false, 'nosource', get_string('import_nosource', 'mod_exelearning'));
        }
        self::install_elpx_into_target($elpxpath, $target, $targetcontextid);
        return self::result(true, 'imported', get_string('import_done', 'mod_exelearning'));
    }

    /**
     * Stores a resolved .elpx into the target 'package' filearea (itemid 0) and runs
     * the production extract + grade-item sync.
     *
     * @param string $elpxpath Absolute path to the .elpx to install.
     * @param \stdClass $target Target exelearning instance row (needs id, revision).
     * @param int $targetcontextid Target module context id.
     * @return void
     */
    private static function install_elpx_into_target(string $elpxpath, \stdClass $target, int $targetcontextid): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/exelearning/lib.php');

        $fs = get_file_storage();
        $fs->delete_area_files($targetcontextid, 'mod_exelearning', 'package');
        $fs->create_file_from_pathname(
            [
                'contextid' => $targetcontextid,
                'component' => 'mod_exelearning',
                'filearea'  => 'package',
                'itemid'    => 0,
                'filepath'  => '/',
                'filename'  => 'imported.elpx',
            ],
            $elpxpath
        );
        exelearning_extract_stored_package($targetcontextid, (int) $target->revision);
        exelearning_sync_grade_items($target->id, $targetcontextid);
    }

    /**
     * Resolves a readable .elpx temp path from a source module's stored package.
     *
     * @param int $sourcecontextid Source module context id.
     * @param string $component 'mod_exeweb' or 'mod_exescorm'.
     * @return string|null Absolute path to a temporary .elpx, or null when none.
     */
    private static function resolve_source_elpx(int $sourcecontextid, string $component): ?string {
        $fs = get_file_storage();
        $files = $fs->get_area_files(
            $sourcecontextid,
            $component,
            'package',
            0,
            'sortorder DESC, id ASC',
            false
        );
        $pkg = reset($files);
        if (!$pkg) {
            return null;
        }
        if ($component === 'mod_exeweb') {
            // The mod_exeweb package is a native .elpx; copy it out verbatim.
            $tmp = make_request_directory() . '/source.elpx';
            $pkg->copy_content_to($tmp);
            return $tmp;
        }
        if ($component === 'mod_exescorm') {
            return self::extract_embedded_elpx($pkg);
        }
        return null;
    }

    /**
     * Extracts an embedded .elpx from a SCORM zip exported with editable source.
     *
     * @param \stored_file $scorm The stored SCORM .zip.
     * @return string|null Absolute path to the extracted .elpx, or null when absent.
     */
    private static function extract_embedded_elpx(\stored_file $scorm): ?string {
        $packer = get_file_packer('application/zip');
        $tmpdir = make_request_directory();
        $extracted = $scorm->extract_to_pathname($packer, $tmpdir);
        if (!is_array($extracted)) {
            return null;
        }
        // Only the modern .elpx v4 is accepted (legacy .elp is out of scope).
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($tmpdir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'elpx') {
                return $file->getPathname();
            }
        }
        return null;
    }

    /**
     * Builds a uniform import_package() result object.
     *
     * @param bool $success Whether the import succeeded.
     * @param string $status Machine status: imported|nosource.
     * @param string $message Human-readable message.
     * @return \stdClass
     */
    private static function result(bool $success, string $status, string $message): \stdClass {
        return (object) ['success' => $success, 'status' => $status, 'message' => $message];
    }

    /**
     * Builds a uniform per-activity migration result object.
     *
     * @param \stdClass $source The source row being migrated.
     * @param string $status migrated|alreadymigrated|nosource|error.
     * @param string $error Error detail when status is 'error'.
     * @param int $targetcmid Created course module id when migrated.
     * @return \stdClass
     */
    private static function migresult(
        \stdClass $source,
        string $status,
        string $error = '',
        int $targetcmid = 0
    ): \stdClass {
        return (object) [
            'cmid'       => (int) $source->cmid,
            'course'     => (int) $source->course,
            'coursename' => $source->coursename ?? '',
            'name'       => $source->name,
            'status'     => $status,
            'error'      => $error,
            'targetcmid' => $targetcmid,
        ];
    }
}
