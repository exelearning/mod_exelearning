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
use mod_exelearning\local\attempts;

defined('MOODLE_INTERNAL') || die();

/**
 * Unit tests for \mod_exelearning\local\attempts.
 *
 * @package    mod_exelearning
 * @category   test
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_exelearning\local\attempts
 */
final class attempts_test extends advanced_testcase {

    /** @var \stdClass exelearning instance under test. */
    protected $instance;

    /** @var \stdClass enrolled student. */
    protected $student;

    /**
     * Create a course, an exelearning instance and an enrolled student.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        /** @var \mod_exelearning_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_exelearning');
        $this->instance = $generator->create_instance(['course' => $course->id]);

        $this->student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->student->id, $course->id, 'student');
    }

    /**
     * Same session token reuses the attempt number; a fresh token allocates +1.
     */
    public function test_resolve_attempt_number(): void {
        $eid = $this->instance->id;
        $uid = $this->student->id;

        // First commit of session A allocates attempt 1.
        $attempta = attempts::resolve_attempt_number($eid, $uid, 'session-a');
        $this->assertSame(1, $attempta);
        attempts::record_item($eid, $uid, $attempta, 0, 5, 10, 'completed', 'session-a');

        // A second commit of the same session resolves to the same number.
        $this->assertSame(1, attempts::resolve_attempt_number($eid, $uid, 'session-a'));

        // A new session token allocates the next attempt number.
        $attemptb = attempts::resolve_attempt_number($eid, $uid, 'session-b');
        $this->assertSame(2, $attemptb);
        attempts::record_item($eid, $uid, $attemptb, 0, 8, 10, 'completed', 'session-b');
        $this->assertSame(2, attempts::resolve_attempt_number($eid, $uid, 'session-b'));
    }

    /**
     * record_item upserts on (attempt, itemnumber) and computes scaledscore.
     */
    public function test_record_item_upsert(): void {
        global $DB;

        $eid = $this->instance->id;
        $uid = $this->student->id;

        attempts::record_item($eid, $uid, 1, 1, 5, 10, 'completed', 'tok');
        attempts::record_item($eid, $uid, 1, 1, 7, 10, 'completed', 'tok');

        $rows = $DB->get_records('exelearning_attempt', [
            'exelearningid' => $eid,
            'userid'        => $uid,
            'attempt'       => 1,
            'itemnumber'    => 1,
        ]);
        $this->assertCount(1, $rows);

        $row = reset($rows);
        $this->assertEquals(7, (float) $row->rawscore);
        $this->assertEqualsWithDelta(0.7, (float) $row->scaledscore, 0.0001);
    }

    /**
     * aggregate_scaled honours each grademethod across three attempts.
     */
    public function test_aggregate_scaled(): void {
        $eid = $this->instance->id;
        $uid = $this->student->id;

        // Three attempts, scaled 0.6, 0.9, 0.7 (rawscore over maxscore 10).
        attempts::record_item($eid, $uid, 1, 0, 6, 10, 'completed', 's1');
        attempts::record_item($eid, $uid, 2, 0, 9, 10, 'completed', 's2');
        attempts::record_item($eid, $uid, 3, 0, 7, 10, 'completed', 's3');

        $this->assertEqualsWithDelta(0.9,
                attempts::aggregate_scaled($eid, $uid, 0, attempts::GRADE_HIGHEST), 0.0001);
        $this->assertEqualsWithDelta((0.6 + 0.9 + 0.7) / 3,
                attempts::aggregate_scaled($eid, $uid, 0, attempts::GRADE_AVERAGE), 0.0001);
        $this->assertEqualsWithDelta(0.6,
                attempts::aggregate_scaled($eid, $uid, 0, attempts::GRADE_FIRST), 0.0001);
        $this->assertEqualsWithDelta(0.7,
                attempts::aggregate_scaled($eid, $uid, 0, attempts::GRADE_LAST), 0.0001);
        $this->assertEqualsWithDelta(0.6,
                attempts::aggregate_scaled($eid, $uid, 0, attempts::GRADE_LOWEST), 0.0001);

        // No attempts on an unused item returns null.
        $this->assertNull(attempts::aggregate_scaled($eid, $uid, 99, attempts::GRADE_HIGHEST));
    }

    /**
     * count_user_attempts counts distinct attempt numbers, not rows.
     */
    public function test_count_user_attempts(): void {
        $eid = $this->instance->id;
        $uid = $this->student->id;

        $this->assertSame(0, attempts::count_user_attempts($eid, $uid));

        // Attempt 1 with two items still counts as one attempt.
        attempts::record_item($eid, $uid, 1, 0, 5, 10, 'completed', 's1');
        attempts::record_item($eid, $uid, 1, 1, 5, 10, 'completed', 's1');
        $this->assertSame(1, attempts::count_user_attempts($eid, $uid));

        // A second attempt bumps the count to two.
        attempts::record_item($eid, $uid, 2, 0, 8, 10, 'completed', 's2');
        $this->assertSame(2, attempts::count_user_attempts($eid, $uid));
    }
}
