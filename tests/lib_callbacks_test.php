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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/exelearning/lib.php');

/**
 * Tests for lib.php Moodle callbacks (view, grade item names, file serving guard).
 *
 * @package    mod_exelearning
 * @category   test
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::exelearning_view
 * @covers     ::exelearning_get_grade_item_names
 * @covers     ::exelearning_require_teacher_mode_hider
 * @covers     ::exelearning_pluginfile
 */
final class lib_callbacks_test extends advanced_testcase {
    /**
     * Course + graded instance backed by the default 2-iDevice fixture.
     *
     * @return array{0: \stdClass, 1: \stdClass, 2: \stdClass} [course, instance, cm]
     */
    private function make(): array {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->get_plugin_generator('mod_exelearning')
            ->create_instance(['course' => $course->id]);
        $cm = get_coursemodule_from_instance('exelearning', $instance->id);
        return [$course, $instance, $cm];
    }

    /**
     * exelearning_view() triggers the viewed event and marks completion.
     */
    public function test_view_triggers_event(): void {
        global $DB;
        [$course, $instance, $cm] = $this->make();
        $context = \context_module::instance($cm->id);

        $sink = $this->redirectEvents();
        exelearning_view($instance, $course, $DB->get_record('course_modules', ['id' => $cm->id]), $context);
        $events = $sink->get_events();

        $this->assertNotEmpty($events);
        $this->assertInstanceOf(\mod_exelearning\event\course_module_viewed::class, end($events));
    }

    /**
     * exelearning_get_grade_item_names() maps each item to its display name.
     */
    public function test_get_grade_item_names(): void {
        [, $instance] = $this->make();

        $names = exelearning_get_grade_item_names([
            (object) ['id' => 10, 'itemnumber' => 0, 'iteminstance' => $instance->id],
            (object) ['id' => 11, 'itemnumber' => 1, 'iteminstance' => $instance->id],
            (object) ['id' => 12, 'itemnumber' => 99, 'iteminstance' => $instance->id],
        ]);

        $this->assertSame(get_string('gradeitem_overall', 'mod_exelearning'), $names[10]);
        $this->assertNotEmpty($names[11]);
        $this->assertSame('Item #99', $names[12]);
    }

    /**
     * exelearning_require_teacher_mode_hider() enqueues page JS without error.
     */
    public function test_require_teacher_mode_hider(): void {
        $this->resetAfterTest();
        $this->assertNull(exelearning_require_teacher_mode_hider('exelearningobject'));
    }

    /**
     * exelearning_pluginfile() gates the package area behind manageactivities, so
     * a plain student cannot download the source package.
     */
    public function test_pluginfile_package_requires_manage(): void {
        [$course, $instance, $cm] = $this->make();
        $context = \context_module::instance($cm->id);

        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->setUser($student);

        $this->expectException(\required_capability_exception::class);
        exelearning_pluginfile(
            $course,
            $cm,
            $context,
            'package',
            [0, 'package.elpx'],
            true
        );
    }

    /**
     * exelearning_extract_stored_package() unpacks the stored ELPX into the
     * content area and injects the SCORM wrapper shim/loader.
     */
    public function test_extract_stored_package_injects_shim(): void {
        [, $instance, $cm] = $this->make();
        $context = \context_module::instance($cm->id);

        // Re-extract to a fresh revision (re-runs the shim injection + loader).
        exelearning_extract_stored_package($context->id, 99);

        $fs = get_file_storage();
        $this->assertNotFalse(
            $fs->get_file($context->id, 'mod_exelearning', 'content', 99, '/', 'index.html')
        );
        $this->assertNotFalse(
            $fs->get_file($context->id, 'mod_exelearning', 'content', 99, '/libs/', 'SCORM_API_wrapper.js')
        );
    }

    /**
     * exelearning_grade_item_update() publishes the overall column in OVERALL mode.
     */
    public function test_grade_item_update_overall(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->get_plugin_generator('mod_exelearning')
            ->create_instance(['course' => $course->id, 'grademodel' => 0, 'gradeenabled' => 1]);

        $this->assertSame(GRADE_UPDATE_OK, exelearning_grade_item_update($instance));

        $items = \grade_item::fetch_all([
            'itemtype'     => 'mod',
            'itemmodule'   => 'exelearning',
            'iteminstance' => $instance->id,
            'itemnumber'   => 0,
        ]);
        $this->assertNotEmpty($items);
    }
}
