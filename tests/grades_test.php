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
use grade_item;
use grade_grade;
use mod_exelearning\local\attempts;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/exelearning/lib.php');
require_once($CFG->libdir . '/gradelib.php');

/**
 * Unit tests for the grade category selector (DEC-0034) and the per-iDevice
 * gradebook model with no overall column (DEC-0038, supersedes DEC-0035).
 *
 * @package    mod_exelearning
 * @category   test
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::exelearning_apply_grade_category
 * @covers     ::exelearning_recalculate_user_grades
 */
final class grades_test extends advanced_testcase {
    /**
     * Helper: create a course + exelearning instance with the given overrides.
     *
     * @param array $record extra fields for the generator
     * @return \stdClass the exelearning instance row
     */
    protected function create_activity(array $record = []): \stdClass {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        /** @var \mod_exelearning_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_exelearning');

        return $generator->create_instance(array_merge(['course' => $course->id], $record));
    }

    /**
     * Helper: fetch a grade_item for an instance by itemnumber.
     *
     * @param \stdClass $instance the exelearning instance row
     * @param int $itemnumber 0=overall, >0=iDevice
     * @return grade_item|false
     */
    protected function fetch_item(\stdClass $instance, int $itemnumber) {
        return grade_item::fetch([
            'itemtype'     => 'mod',
            'itemmodule'   => 'exelearning',
            'iteminstance' => $instance->id,
            'itemnumber'   => $itemnumber,
            'courseid'     => $instance->course,
        ]);
    }

    /**
     * Helper: build the $data object used to call exelearning_update_instance().
     *
     * @param \stdClass $instance the existing exelearning instance row
     * @param array $overrides fields to override on the update payload
     * @return \stdClass
     */
    protected function update_payload(\stdClass $instance, array $overrides): \stdClass {
        $cm = get_coursemodule_from_instance('exelearning', $instance->id);
        $data = (object) (array) $instance;
        $data->instance = $instance->id;
        $data->coursemodule = $cm->id;
        foreach ($overrides as $field => $value) {
            $data->{$field} = $value;
        }
        return $data;
    }

    /**
     * The configured grade category is applied to every grade item of the
     * activity (overall + per-iDevice), since grade_update() ignores categoryid
     * and the placement is done with grade_item::set_parent() (DEC-0034).
     */
    public function test_gradecat_places_all_items_in_category(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $category = $this->getDataGenerator()->create_grade_category(['courseid' => $course->id]);
        /** @var \mod_exelearning_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_exelearning');
        $instance = $generator->create_instance([
            'course'     => $course->id,
            'grademodel' => EXELEARNING_GRADEMODEL_PERITEM,
            'gradecat'   => $category->id,
        ]);

        // The two gradable iDevices (1, 2) must sit under the category. PERITEM
        // has no overall item (DEC-0038).
        foreach ([1, 2] as $itemnumber) {
            $item = $this->fetch_item($instance, $itemnumber);
            $this->assertInstanceOf(grade_item::class, $item);
            $this->assertEquals(
                (int) $category->id,
                (int) $item->categoryid,
                "Grade item {$itemnumber} should be under the chosen grade category"
            );
        }
    }

    /**
     * Changing the grade category on update moves every grade item to the new
     * category (grade_item::set_parent() runs again on each sync).
     */
    public function test_gradecat_moves_items_on_update(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $cat1 = $this->getDataGenerator()->create_grade_category(['courseid' => $course->id]);
        $cat2 = $this->getDataGenerator()->create_grade_category(['courseid' => $course->id]);
        /** @var \mod_exelearning_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_exelearning');
        $instance = $generator->create_instance(['course' => $course->id, 'gradecat' => $cat1->id]);

        $this->assertEquals((int) $cat1->id, (int) $this->fetch_item($instance, 1)->categoryid);

        $data = $this->update_payload($instance, ['gradecat' => $cat2->id]);
        $this->assertTrue(exelearning_update_instance($data));

        foreach ([1, 2] as $itemnumber) {
            $this->assertEquals(
                (int) $cat2->id,
                (int) $this->fetch_item($instance, $itemnumber)->categoryid,
                "Grade item {$itemnumber} should have moved to the new category"
            );
        }
    }

    /**
     * In PERITEM there is no overall grade item at all (DEC-0038): the root cause
     * of the DEC-0035 problem (a hidden item that aggregates and blanks the
     * student's total) is gone. The per-iDevice grades are published, included in
     * aggregation, and the student's course total is computed from them.
     */
    public function test_peritem_has_no_overall_grade_item(): void {
        $instance = $this->create_activity(['grademodel' => EXELEARNING_GRADEMODEL_PERITEM]);

        $course = get_course($instance->course);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        // Per-iDevice (1, 2) attempts so recalc writes each grade.
        attempts::record_item($instance->id, $student->id, 1, 1, 7, 10, 'completed', 's1');
        attempts::record_item($instance->id, $student->id, 1, 2, 9, 10, 'completed', 's1');

        exelearning_recalculate_user_grades($instance, $student->id);

        // No overall grade item exists in PERITEM.
        $this->assertFalse($this->fetch_item($instance, 0));

        // The per-iDevice grade is published (grade_get_grades forces a regrade).
        $grades = grade_get_grades($instance->course, 'mod', 'exelearning', $instance->id, $student->id);
        $this->assertEqualsWithDelta(70.0, (float) $grades->items[1]->grades[$student->id]->grade, 0.0001);

        // The per-iDevice grade is included in aggregation (not excluded): there is
        // no hidden overall to blank the student's total anymore (DEC-0038).
        $peritemgrade = grade_grade::fetch([
            'itemid' => $this->fetch_item($instance, 1)->id,
            'userid' => $student->id,
        ]);
        $this->assertInstanceOf(grade_grade::class, $peritemgrade);
        $this->assertFalse((bool) $peritemgrade->is_excluded());
    }

    /**
     * In OVERALL mode the overall is the single visible grade and must never be
     * excluded from aggregation.
     */
    public function test_overall_model_overall_grade_not_excluded(): void {
        $instance = $this->create_activity(['grademodel' => EXELEARNING_GRADEMODEL_OVERALL]);

        $course = get_course($instance->course);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        attempts::record_item($instance->id, $student->id, 1, 0, 8, 10, 'completed', 's1');
        exelearning_recalculate_user_grades($instance, $student->id);

        $overallgrade = grade_grade::fetch([
            'itemid' => $this->fetch_item($instance, 0)->id,
            'userid' => $student->id,
        ]);
        $this->assertInstanceOf(grade_grade::class, $overallgrade);
        $this->assertFalse(
            $overallgrade->is_excluded(),
            'The overall grade is the visible grade in OVERALL mode; never excluded'
        );
    }

    /**
     * Switching the grading model (PERITEM<->OVERALL) must re-publish the grades
     * from the stored attempt history. exelearning_sync_grade_items() deletes and
     * recreates the gradebook columns empty on a model switch, so without the
     * republish every student's grade vanished from the gradebook until they
     * resubmitted, even though exelearning_attempt still held the scores (B2,
     * DEC-0044).
     *
     * @covers ::exelearning_update_grades
     */
    public function test_grademodel_switch_republishes_grades(): void {
        $instance = $this->create_activity(['grademodel' => EXELEARNING_GRADEMODEL_PERITEM]);
        $course = get_course($instance->course);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        // A real submission records both the overall (item 0) and per-iDevice
        // attempts (track::ingest does this regardless of the model).
        attempts::record_item($instance->id, $student->id, 1, 0, 80, 100, 'completed', 's1');
        attempts::record_item($instance->id, $student->id, 1, 1, 7, 10, 'completed', 's1');
        attempts::record_item($instance->id, $student->id, 1, 2, 9, 10, 'completed', 's1');
        exelearning_recalculate_user_grades($instance, $student->id);

        // PERITEM: per-iDevice grades are published, no overall column.
        $before = grade_get_grades($instance->course, 'mod', 'exelearning', $instance->id, $student->id);
        $this->assertEqualsWithDelta(70.0, (float) $before->items[1]->grades[$student->id]->grade, 0.0001);
        $this->assertFalse($this->fetch_item($instance, 0));

        // Switch to OVERALL through the settings-form update path.
        $data = $this->update_payload($instance, ['grademodel' => EXELEARNING_GRADEMODEL_OVERALL]);
        $this->assertTrue(exelearning_update_instance($data));

        // The overall column now exists and its grade is republished from the
        // stored attempts (80/100) instead of vanishing.
        $this->assertInstanceOf(grade_item::class, $this->fetch_item($instance, 0));
        $after = grade_get_grades($instance->course, 'mod', 'exelearning', $instance->id, $student->id);
        $this->assertEqualsWithDelta(80.0, (float) $after->items[0]->grades[$student->id]->grade, 0.0001);
    }

    /**
     * Changing the aggregation method (grademethod) must re-aggregate the already
     * published grades from the attempt history, not leave them computed with the
     * old method (B2, DEC-0044).
     *
     * @covers ::exelearning_update_grades
     */
    public function test_grademethod_change_republishes_overall(): void {
        $instance = $this->create_activity([
            'grademodel'  => EXELEARNING_GRADEMODEL_OVERALL,
            'grademethod' => attempts::GRADE_HIGHEST,
        ]);
        $course = get_course($instance->course);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        // Two overall attempts: 90 then 50.
        attempts::record_item($instance->id, $student->id, 1, 0, 90, 100, 'completed', 's1');
        attempts::record_item($instance->id, $student->id, 2, 0, 50, 100, 'completed', 's2');
        exelearning_recalculate_user_grades($instance, $student->id);

        $before = grade_get_grades($instance->course, 'mod', 'exelearning', $instance->id, $student->id);
        $this->assertEqualsWithDelta(90.0, (float) $before->items[0]->grades[$student->id]->grade, 0.0001);

        // Switch HIGHEST -> AVERAGE: the published grade must re-aggregate to 70.
        $data = $this->update_payload($instance, ['grademethod' => attempts::GRADE_AVERAGE]);
        $this->assertTrue(exelearning_update_instance($data));

        $after = grade_get_grades($instance->course, 'mod', 'exelearning', $instance->id, $student->id);
        $this->assertEqualsWithDelta(70.0, (float) $after->items[0]->grades[$student->id]->grade, 0.0001);
    }

    /**
     * exelearning_update_grades() — the second half of the gradebook contract that
     * core's grade_update_mod_grades() requires — re-publishes every attempting
     * user's grade from the attempt history when called for all users (B2b,
     * DEC-0044). Without it, core grade reset/grab/unlock silently dropped grades.
     *
     * @covers ::exelearning_update_grades
     */
    public function test_update_grades_republishes_after_core_clears_grades(): void {
        $instance = $this->create_activity(['grademodel' => EXELEARNING_GRADEMODEL_OVERALL]);
        $course = get_course($instance->course);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        attempts::record_item($instance->id, $student->id, 1, 0, 60, 100, 'completed', 's1');
        exelearning_recalculate_user_grades($instance, $student->id);
        $published = grade_get_grades($instance->course, 'mod', 'exelearning', $instance->id, $student->id);
        $this->assertEqualsWithDelta(60.0, (float) $published->items[0]->grades[$student->id]->grade, 0.0001);

        // Simulate a core grade refresh that wiped the published grade.
        grade_update(
            'mod/exelearning',
            $instance->course,
            'mod',
            'exelearning',
            $instance->id,
            0,
            (object) ['userid' => $student->id, 'rawgrade' => null]
        );
        $cleared = grade_get_grades($instance->course, 'mod', 'exelearning', $instance->id, $student->id);
        $this->assertNull($cleared->items[0]->grades[$student->id]->grade);

        // The function core now finds republishes from the attempt history.
        exelearning_update_grades($instance, 0);
        $restored = grade_get_grades($instance->course, 'mod', 'exelearning', $instance->id, $student->id);
        $this->assertEqualsWithDelta(60.0, (float) $restored->items[0]->grades[$student->id]->grade, 0.0001);
    }

    /**
     * Resetting the course gradebook must not spawn phantom grade items. Looping
     * 0..MAX(itemnumber) and calling grade_update(['reset']) blindly inserted a
     * bare unnamed column for every itemnumber without a live grade item — in
     * PERITEM the overall (0) never exists, so a reset created a phantom overall
     * column that inflated the course total (B3, DEC-0044).
     *
     * @covers ::exelearning_reset_gradebook
     */
    public function test_reset_gradebook_creates_no_phantom_items(): void {
        $instance = $this->create_activity(['grademodel' => EXELEARNING_GRADEMODEL_PERITEM]);

        // PERITEM: per-iDevice columns exist (1, 2), the overall (0) does not.
        $this->assertInstanceOf(grade_item::class, $this->fetch_item($instance, 1));
        $this->assertFalse($this->fetch_item($instance, 0));

        exelearning_reset_gradebook($instance->course);

        // No phantom overall column was created; the real per-iDevice items remain.
        $this->assertFalse(
            $this->fetch_item($instance, 0),
            'Course reset must not create a phantom overall grade item in PERITEM'
        );
        $this->assertInstanceOf(grade_item::class, $this->fetch_item($instance, 1));
    }
}
