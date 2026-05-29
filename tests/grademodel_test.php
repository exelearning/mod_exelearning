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
use mod_exelearning\local\attempts;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/exelearning/lib.php');
require_once($CFG->libdir . '/gradelib.php');

/**
 * Unit tests for the gradebook columns model (DEC-0008) and grade recalculation.
 *
 * @package    mod_exelearning
 * @category   test
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::exelearning_exclude_overall_from_total
 * @covers     ::exelearning_recalculate_user_grades
 * @covers     ::exelearning_update_instance
 */
final class grademodel_test extends advanced_testcase {
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
     * BOTH model: the overall item is excluded from the course total
     * (weightoverride=1, aggregationcoef2=0).
     */
    public function test_both_excludes_overall_from_total(): void {
        $instance = $this->create_activity(['grademodel' => EXELEARNING_GRADEMODEL_BOTH]);

        $overall = $this->fetch_item($instance, 0);
        $this->assertInstanceOf(grade_item::class, $overall);
        $this->assertSame(1, (int) $overall->weightoverride);
        $this->assertEqualsWithDelta(0.0, (float) $overall->aggregationcoef2, 0.0001);

        // Per-iDevice columns still exist and contribute to the total.
        $this->assertInstanceOf(grade_item::class, $this->fetch_item($instance, 1));
        $this->assertInstanceOf(grade_item::class, $this->fetch_item($instance, 2));
    }

    /**
     * Switching BOTH → OVERALL on update removes the per-iDevice columns.
     */
    public function test_switch_both_to_overall_removes_peritem_columns(): void {
        $instance = $this->create_activity(['grademodel' => EXELEARNING_GRADEMODEL_BOTH]);

        // Both per-iDevice columns are present under BOTH.
        $this->assertInstanceOf(grade_item::class, $this->fetch_item($instance, 1));
        $this->assertInstanceOf(grade_item::class, $this->fetch_item($instance, 2));

        $data = $this->update_payload($instance, ['grademodel' => EXELEARNING_GRADEMODEL_OVERALL]);
        $this->assertTrue(exelearning_update_instance($data));

        // The overall stays; the per-iDevice gradebook columns are gone.
        $this->assertInstanceOf(grade_item::class, $this->fetch_item($instance, 0));
        $this->assertFalse($this->fetch_item($instance, 1));
        $this->assertFalse($this->fetch_item($instance, 2));
    }

    /**
     * Switching BOTH → PERITEM on update removes the overall column.
     */
    public function test_switch_both_to_peritem_removes_overall_column(): void {
        $instance = $this->create_activity(['grademodel' => EXELEARNING_GRADEMODEL_BOTH]);

        $this->assertInstanceOf(grade_item::class, $this->fetch_item($instance, 0));

        $data = $this->update_payload($instance, ['grademodel' => EXELEARNING_GRADEMODEL_PERITEM]);
        $this->assertTrue(exelearning_update_instance($data));

        // The overall (itemnumber=0) column is removed; per-iDevice ones remain.
        $this->assertFalse($this->fetch_item($instance, 0));
        $this->assertInstanceOf(grade_item::class, $this->fetch_item($instance, 1));
        $this->assertInstanceOf(grade_item::class, $this->fetch_item($instance, 2));
    }

    /**
     * gradepass is propagated to the overall grade_item.
     */
    public function test_gradepass_propagates_to_overall(): void {
        $instance = $this->create_activity([
            'grademodel' => EXELEARNING_GRADEMODEL_BOTH,
            'gradepass'  => 50,
        ]);

        $overall = $this->fetch_item($instance, 0);
        $this->assertInstanceOf(grade_item::class, $overall);
        $this->assertEqualsWithDelta(50.0, (float) $overall->gradepass, 0.0001);
    }

    /**
     * recalculate_user_grades writes the aggregated overall grade per grademethod.
     *
     * With grademax=100 and GRADE_HIGHEST over scaled 0.6/0.9, the overall
     * gradebook grade for itemnumber=0 must equal 90.
     */
    public function test_recalculate_user_grades_overall(): void {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');

        $instance = $this->create_activity([
            'grademodel'  => EXELEARNING_GRADEMODEL_BOTH,
            'grademethod' => attempts::GRADE_HIGHEST,
            'grademax'    => 100,
        ]);

        $course = get_course($instance->course);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        // Two overall attempts: best scaled 0.9.
        attempts::record_item($instance->id, $student->id, 1, 0, 6, 10, 'completed', 's1');
        attempts::record_item($instance->id, $student->id, 2, 0, 9, 10, 'completed', 's2');

        exelearning_recalculate_user_grades($instance, $student->id);

        $grades = grade_get_grades(
            $instance->course,
            'mod',
            'exelearning',
            $instance->id,
            $student->id
        );
        // The overall aggregated grade lives at itemnumber=0.
        $grade = $grades->items[0]->grades[$student->id]->grade;
        $this->assertEqualsWithDelta(90.0, (float) $grade, 0.0001);
    }

    /**
     * recalculate_user_grades with GRADE_AVERAGE averages the attempts.
     */
    public function test_recalculate_user_grades_average(): void {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');

        $instance = $this->create_activity([
            'grademodel'  => EXELEARNING_GRADEMODEL_OVERALL,
            'grademethod' => attempts::GRADE_AVERAGE,
            'grademax'    => 100,
        ]);

        $course = get_course($instance->course);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        // Scaled 0.6 and 0.8 → average 0.7 → 70.
        attempts::record_item($instance->id, $student->id, 1, 0, 6, 10, 'completed', 's1');
        attempts::record_item($instance->id, $student->id, 2, 0, 8, 10, 'completed', 's2');

        exelearning_recalculate_user_grades($instance, $student->id);

        $grades = grade_get_grades(
            $instance->course,
            'mod',
            'exelearning',
            $instance->id,
            $student->id
        );
        $grade = $grades->items[0]->grades[$student->id]->grade;
        $this->assertEqualsWithDelta(70.0, (float) $grade, 0.0001);
    }
}
