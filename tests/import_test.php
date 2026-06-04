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

namespace mod_exelearning;

use advanced_testcase;
use mod_exelearning\local\import_service;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/exelearning/lib.php');
require_once($CFG->libdir . '/gradelib.php');

/**
 * Unit tests for the sibling-activity migration service (issue #13 #3, DEC-0026).
 *
 * The sibling plugins (mod_exeweb / mod_exescorm) are not installed in CI, so the
 * tests exercise everything at the storage / gradebook level by simulating a source
 * plugin's `package` filearea and a source gradebook item (both addressed by string,
 * so neither sibling needs to be installed). Site-wide enumeration and the admin page
 * are out of unit-test scope for that same reason.
 *
 * @package    mod_exelearning
 * @category   test
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_exelearning\local\import_service
 */
final class import_test extends advanced_testcase {
    /**
     * Creates an empty (no-package) target exelearning instance.
     *
     * @return array{0:\stdClass,1:int} The instance row and its module context id.
     */
    protected function create_empty_target(): array {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        /** @var \mod_exelearning_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_exelearning');
        $instance = $generator->create_instance(['course' => $course->id, 'packagefilepath' => false]);
        $cm = get_coursemodule_from_instance('exelearning', $instance->id);
        return [$instance, (int) \context_module::instance($cm->id)->id];
    }

    /**
     * Stores a file in a context as if it were a sibling plugin's package.
     *
     * @param int $contextid Context to host the stored file.
     * @param string $component Source frankenstyle component (e.g. mod_exeweb).
     * @param string $srcpath Path to the file to store.
     * @param string $filename Stored file name.
     * @return void
     */
    protected function store_sibling_package(int $contextid, string $component, string $srcpath, string $filename): void {
        $fs = get_file_storage();
        $fs->delete_area_files($contextid, $component, 'package');
        $fs->create_file_from_pathname(
            [
                'contextid' => $contextid,
                'component' => $component,
                'filearea'  => 'package',
                'itemid'    => 0,
                'filepath'  => '/',
                'filename'  => $filename,
            ],
            $srcpath
        );
    }

    /**
     * The engine copies a mod_exeweb .elpx into a target and re-runs extract + sync.
     */
    public function test_import_package_from_exeweb(): void {
        global $CFG, $DB;

        [$instance, $targetctx] = $this->create_empty_target();
        $this->assertSame(0, $DB->count_records(
            'exelearning_grade_item',
            ['exelearningid' => $instance->id, 'deleted' => 0]
        ));

        $fixture = $CFG->dirroot . '/mod/exelearning/research/fixtures/elpx/actividad-evaluable.elpx';
        $srcctx = (int) \context_system::instance()->id;
        $this->store_sibling_package($srcctx, 'mod_exeweb', $fixture, 'web.elpx');

        $result = import_service::import_package($srcctx, 'mod_exeweb', $instance, $targetctx);
        $this->assertTrue($result->success);
        $this->assertSame('imported', $result->status);

        $fs = get_file_storage();
        $this->assertTrue($fs->file_exists($targetctx, 'mod_exelearning', 'package', 0, '/', 'imported.elpx'));
        $this->assertNotEmpty($fs->get_file(
            $targetctx,
            'mod_exelearning',
            'content',
            (int) $instance->revision,
            '/',
            'index.html'
        ));
        $this->assertSame(2, $DB->count_records(
            'exelearning_grade_item',
            ['exelearningid' => $instance->id, 'deleted' => 0]
        ));
    }

    /**
     * The engine imports a mod_exescorm SCORM that embeds an editable .elpx.
     */
    public function test_import_package_from_exescorm_with_embedded_source(): void {
        global $CFG, $DB;

        [$instance, $targetctx] = $this->create_empty_target();

        $fixture = $CFG->dirroot . '/mod/exelearning/research/fixtures/elpx/actividad-evaluable.elpx';
        $stage = make_request_directory();
        file_put_contents($stage . '/imsmanifest.xml', '<manifest></manifest>');
        copy($fixture, $stage . '/elp.elpx');
        $packer = get_file_packer('application/zip');
        $zip = make_request_directory() . '/scorm.zip';
        $packer->archive_to_pathname(
            ['imsmanifest.xml' => $stage . '/imsmanifest.xml', 'content/elp.elpx' => $stage . '/elp.elpx'],
            $zip
        );
        $srcctx = (int) \context_system::instance()->id;
        $this->store_sibling_package($srcctx, 'mod_exescorm', $zip, 'scorm.zip');

        $result = import_service::import_package($srcctx, 'mod_exescorm', $instance, $targetctx);
        $this->assertTrue($result->success);
        $this->assertSame('imported', $result->status);
        $this->assertSame(2, $DB->count_records(
            'exelearning_grade_item',
            ['exelearningid' => $instance->id, 'deleted' => 0]
        ));
    }

    /**
     * A SCORM without an embedded .elpx source is not importable.
     */
    public function test_import_package_from_exescorm_without_source(): void {
        [$instance, $targetctx] = $this->create_empty_target();

        $stage = make_request_directory();
        file_put_contents($stage . '/imsmanifest.xml', '<manifest></manifest>');
        file_put_contents($stage . '/index.html', '<html></html>');
        $packer = get_file_packer('application/zip');
        $zip = make_request_directory() . '/scorm.zip';
        $packer->archive_to_pathname(
            ['imsmanifest.xml' => $stage . '/imsmanifest.xml', 'index.html' => $stage . '/index.html'],
            $zip
        );
        $srcctx = (int) \context_system::instance()->id;
        $this->store_sibling_package($srcctx, 'mod_exescorm', $zip, 'scorm.zip');

        $result = import_service::import_package($srcctx, 'mod_exescorm', $instance, $targetctx);
        $this->assertFalse($result->success);
        $this->assertSame('nosource', $result->status);
    }

    /**
     * An empty source (no stored package) is reported as nosource.
     */
    public function test_import_package_with_empty_source(): void {
        [$instance, $targetctx] = $this->create_empty_target();
        $srcctx = (int) \context_system::instance()->id;

        $result = import_service::import_package($srcctx, 'mod_exeweb', $instance, $targetctx);
        $this->assertFalse($result->success);
        $this->assertSame('nosource', $result->status);
    }

    /**
     * create_target_module() creates a real eXeLearning activity with the given model.
     */
    public function test_create_target_module(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();

        $target = import_service::create_target_module(
            (int) $course->id,
            0,
            'Created by migration',
            EXELEARNING_GRADEMODEL_OVERALL
        );

        $this->assertNotEmpty($target->cm->id);
        $this->assertSame('Created by migration', $target->instance->name);
        $this->assertSame((int) EXELEARNING_GRADEMODEL_OVERALL, (int) $target->instance->grademodel);
        $this->assertTrue($DB->record_exists('course_modules', ['id' => $target->cm->id]));
        $this->assertSame((int) $course->id, (int) $target->instance->course);
    }

    /**
     * migrate_grades_overall() copies each user's source final grade to the target's
     * overall grade item. The source grade item is seeded by string, so mod_exescorm
     * does not need to be installed.
     */
    public function test_migrate_grades_overall(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $u1 = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $u2 = $this->getDataGenerator()->create_and_enrol($course, 'student');

        // Seed the source grades on a real installed module (mod_assign stands in
        // for mod_exescorm here, which is not installed in CI). migrate_grades_overall
        // is component-agnostic, so this exercises the same read + write path. Grades
        // are set as final-grade overrides so they survive any regrade.
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id, 'grade' => 100]);
        $assignitem = \grade_item::fetch([
            'courseid' => $course->id, 'itemtype' => 'mod', 'itemmodule' => 'assign',
            'iteminstance' => $assign->id, 'itemnumber' => 0,
        ]);
        $assignitem->update_final_grade($u1->id, 80.0);
        $assignitem->update_final_grade($u2->id, 55.0);

        $target = import_service::create_target_module(
            (int) $course->id,
            0,
            'Migrated SCORM',
            EXELEARNING_GRADEMODEL_OVERALL
        );

        $migrated = import_service::migrate_grades_overall(
            (int) $course->id,
            'mod_assign',
            (int) $assign->id,
            $target->instance
        );
        $this->assertSame(2, $migrated);

        $grades = grade_get_grades(
            $course->id,
            'mod',
            'exelearning',
            $target->instance->id,
            [$u1->id, $u2->id]
        );
        $this->assertEqualsWithDelta(80.0, (float) $grades->items[0]->grades[$u1->id]->grade, 0.001);
        $this->assertEqualsWithDelta(55.0, (float) $grades->items[0]->grades[$u2->id]->grade, 0.001);

        // Sanity: no exelearning_attempt rows were created for migrated grades.
        $this->assertSame(0, $DB->count_records('exelearning_attempt', ['exelearningid' => $target->instance->id]));
    }

    /**
     * migrate_one() creates the activity, imports the content, records the mapping,
     * and is idempotent (a second run skips the already-migrated source).
     */
    public function test_migrate_one_exeweb_idempotent(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();

        // Stand-in "source": a real cm whose context hosts a fake mod_exeweb package.
        /** @var \mod_exelearning_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_exelearning');
        $standin = $generator->create_instance(['course' => $course->id, 'packagefilepath' => false]);
        $standincm = get_coursemodule_from_instance('exelearning', $standin->id);
        $standinctx = (int) \context_module::instance($standincm->id)->id;
        $fixture = $CFG->dirroot . '/mod/exelearning/research/fixtures/elpx/actividad-evaluable.elpx';
        $this->store_sibling_package($standinctx, 'mod_exeweb', $fixture, 'web.elpx');

        $source = (object) [
            'cmid'       => (int) $standincm->id,
            'course'     => (int) $course->id,
            'sectionnum' => 0,
            'instanceid' => (int) $standin->id,
            'name'       => 'Migrated from exeweb',
            'coursename' => $course->fullname,
        ];

        $first = import_service::migrate_one('exeweb', $source);
        $this->assertSame('migrated', $first->status);
        $this->assertNotEmpty($first->targetcmid);

        // A new eXeLearning activity exists with the imported content + grade items.
        $targetcm = get_coursemodule_from_id('exelearning', $first->targetcmid, 0, false, MUST_EXIST);
        $this->assertSame('Migrated from exeweb', $targetcm->name);
        $targetctx = (int) \context_module::instance($targetcm->id)->id;
        $fs = get_file_storage();
        $this->assertNotEmpty($fs->get_file($targetctx, 'mod_exelearning', 'content', 1, '/', 'index.html'));
        $this->assertSame(2, $DB->count_records(
            'exelearning_grade_item',
            ['exelearningid' => $targetcm->instance, 'deleted' => 0]
        ));
        $this->assertEquals(1, $DB->count_records(
            'exelearning_migration',
            ['sourcecomponent' => 'mod_exeweb', 'sourcecmid' => $standincm->id]
        ));

        // Idempotent: a second run skips and creates no new activity.
        $before = $DB->count_records('exelearning');
        $second = import_service::migrate_one('exeweb', $source);
        $this->assertSame('alreadymigrated', $second->status);
        $this->assertSame($before, $DB->count_records('exelearning'));
        $this->assertEquals(1, $DB->count_records(
            'exelearning_migration',
            ['sourcecomponent' => 'mod_exeweb', 'sourcecmid' => $standincm->id]
        ));
    }
}
