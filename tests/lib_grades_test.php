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
require_once($CFG->libdir . '/gradelib.php');

/**
 * Tests for lib.php grade helpers.
 *
 * @package    mod_exelearning
 * @category   test
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::exelearning_grade_item_name
 * @covers     ::exelearning_remove_all_grade_items
 * @covers     ::exelearning_relax_completion_grade_errors
 * @covers     ::exelearning_apply_grade_category
 * @covers     \mod_exelearning\grades\grade_sync
 * @covers     \mod_exelearning\grades\grade_item_manager
 * @covers     \mod_exelearning\grades\completion_validator
 */
final class lib_grades_test extends advanced_testcase {
    /**
     * exelearning_grade_item_name() composes "name · [page ·] type" and clamps
     * the result to the 255-char column width.
     */
    public function test_grade_item_name_format_and_clamp(): void {
        $instance = (object) ['name' => 'My Activity'];

        // With a page name: "name · page · type".
        $withpage = (object) ['idevicetype' => 'trueorfalse', 'pagename' => 'Page 1'];
        $this->assertSame(
            'My Activity · Page 1 · trueorfalse',
            exelearning_grade_item_name($instance, $withpage)
        );

        // Without a page name: "name · type".
        $nopage = (object) ['idevicetype' => 'guess', 'pagename' => ''];
        $this->assertSame('My Activity · guess', exelearning_grade_item_name($instance, $nopage));

        // An over-long composed name is clamped to 255 characters.
        $long = (object) ['name' => str_repeat('x', 300)];
        $clamped = exelearning_grade_item_name($long, (object) ['idevicetype' => 't', 'pagename' => '']);
        $this->assertSame(255, \core_text::strlen($clamped));
    }

    /**
     * exelearning_remove_all_grade_items() soft-deletes every registered item.
     */
    public function test_remove_all_grade_items(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->get_plugin_generator('mod_exelearning')
            ->create_instance(['course' => $course->id]);

        // The default fixture registers two gradable iDevices.
        $this->assertSame(2, $DB->count_records(
            'exelearning_grade_item',
            ['exelearningid' => $instance->id, 'deleted' => 0]
        ));

        exelearning_remove_all_grade_items($instance);

        $this->assertSame(0, $DB->count_records(
            'exelearning_grade_item',
            ['exelearningid' => $instance->id, 'deleted' => 0]
        ));
    }

    /**
     * exelearning_relax_completion_grade_errors() clears core's completion-grade
     * rejection only when the targeted item is a registered gradebook column.
     */
    public function test_relax_completion_grade_errors(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->get_plugin_generator('mod_exelearning')
            ->create_instance(['course' => $course->id, 'grademodel' => 1]);

        $errors = ['completionpassgrade' => 'core rejected it'];

        // Targeting a registered per-iDevice item (1) in PERITEM clears the error.
        $relaxed = exelearning_relax_completion_grade_errors(
            $errors,
            ['completiongradeitemnumber' => 1, 'grademodel' => 1],
            $instance->id
        );
        $this->assertArrayNotHasKey('completionpassgrade', $relaxed);

        // Targeting an unregistered item keeps the error.
        $kept = exelearning_relax_completion_grade_errors(
            $errors,
            ['completiongradeitemnumber' => 99, 'grademodel' => 1],
            $instance->id
        );
        $this->assertArrayHasKey('completionpassgrade', $kept);
    }

    /**
     * exelearning_apply_grade_category() re-parents every grade item under the
     * configured grade category.
     */
    public function test_apply_grade_category(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->get_plugin_generator('mod_exelearning')
            ->create_instance(['course' => $course->id]);

        $category = new \grade_category(['courseid' => $course->id, 'fullname' => 'Cat'], false);
        $categoryid = (int) $category->insert();
        $instance->gradecat = $categoryid;

        exelearning_apply_grade_category($instance);

        $items = \grade_item::fetch_all([
            'itemtype'     => 'mod',
            'itemmodule'   => 'exelearning',
            'iteminstance' => $instance->id,
            'courseid'     => $course->id,
        ]);
        $this->assertNotEmpty($items);
        foreach ($items as $item) {
            $this->assertSame($categoryid, (int) $item->categoryid);
        }
    }
}
