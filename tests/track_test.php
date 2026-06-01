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
use mod_exelearning\local\track;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/exelearning/lib.php');
require_once($CFG->libdir . '/gradelib.php');

/**
 * Unit tests for the SCORM tracking helper (per-iDevice grade routing).
 *
 * Covers RIE-007 / DEC-0017: routing scores to the right gradebook column by stable
 * objectid instead of by the page-local index N that collides across pages.
 *
 * @package    mod_exelearning
 * @category   test
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_exelearning\local\track
 */
final class track_test extends advanced_testcase {
    /**
     * Helper: course + exelearning instance + enrolled student.
     *
     * @param array $record extra generator fields (e.g. packagefilepath, grademodel)
     * @return array{0: \stdClass, 1: \stdClass} [instance, student]
     */
    protected function create_activity_with_student(array $record = []): array {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        /** @var \mod_exelearning_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_exelearning');
        $instance = $generator->create_instance(array_merge(['course' => $course->id], $record));

        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        return [$instance, $student];
    }

    /**
     * Returns the objectid registered for a given itemnumber of an instance.
     *
     * @param \stdClass $instance
     * @param int $itemnumber
     * @return string
     */
    protected function objectid_for(\stdClass $instance, int $itemnumber): string {
        global $DB;
        return (string) $DB->get_field('exelearning_grade_item', 'objectid', [
            'exelearningid' => $instance->id,
            'itemnumber'    => $itemnumber,
            'deleted'       => 0,
        ], MUST_EXIST);
    }

    /**
     * Returns the published gradebook grade for a user on a given itemnumber.
     *
     * @param \stdClass $instance
     * @param int $userid
     * @param int $itemnumber
     * @return float|null
     */
    protected function published_grade(\stdClass $instance, int $userid, int $itemnumber): ?float {
        $grades = grade_get_grades(
            $instance->course,
            'mod',
            'exelearning',
            $instance->id,
            $userid
        );
        if (!isset($grades->items[$itemnumber]->grades[$userid])) {
            return null;
        }
        $grade = $grades->items[$itemnumber]->grades[$userid]->grade;
        return ($grade === null) ? null : (float) $grade;
    }

    /**
     * parse_suspend_data() decodes the producer's format, is locale-agnostic on the
     * score/weight labels, tolerates a trailing period and clamps out-of-range %.
     */
    public function test_parse_suspend_data_matches_js_format(): void {
        $suspend = '1. "Quiz one"; Puntuación: 80%; Peso: 100%' . ".\t"
                . '2. "Quiz two"; Puntuación: 60.5%; Peso: 50%' . ".\t"
                . '3. "Over"; Score: 150%; Weight: 100%.';

        $parsed = track::parse_suspend_data($suspend);

        $this->assertSame([1, 2, 3], array_keys($parsed));
        $this->assertSame('Quiz one', $parsed[1]['title']);
        $this->assertEqualsWithDelta(80.0, $parsed[1]['scorepct'], 0.0001);
        $this->assertEqualsWithDelta(100.0, $parsed[1]['weighted'], 0.0001);
        $this->assertEqualsWithDelta(60.5, $parsed[2]['scorepct'], 0.0001);
        // Out-of-range percentages are clamped to 100.
        $this->assertEqualsWithDelta(100.0, $parsed[3]['scorepct'], 0.0001);

        // Empty / unparsable input yields an empty map (no warnings, no entries).
        $this->assertSame([], track::parse_suspend_data(''));
        $this->assertSame([], track::parse_suspend_data('not a valid line'));
    }

    /**
     * apply_item_scores() routes each score to the itemnumber that owns its objectid.
     */
    public function test_objectid_routing_routes_to_correct_itemnumber(): void {
        global $DB;
        [$instance, $student] = $this->create_activity_with_student();

        $obj1 = $this->objectid_for($instance, 1);
        $obj2 = $this->objectid_for($instance, 2);

        $attempt = local\attempts::resolve_attempt_number($instance->id, $student->id, 'sess1');
        $saved = track::apply_item_scores($instance, $student->id, $attempt, [
            $obj1 => ['scorepct' => 80.0, 'weighted' => 100.0, 'title' => 'a'],
            $obj2 => ['scorepct' => 40.0, 'weighted' => 100.0, 'title' => 'b'],
        ], 'sess1');

        // Returned map and the stored attempts land on the right itemnumbers.
        $this->assertEqualsWithDelta(80.0, $saved[1], 0.0001);
        $this->assertEqualsWithDelta(40.0, $saved[2], 0.0001);

        $a1 = $DB->get_record('exelearning_attempt', [
            'exelearningid' => $instance->id, 'userid' => $student->id, 'itemnumber' => 1,
        ], '*', MUST_EXIST);
        $a2 = $DB->get_record('exelearning_attempt', [
            'exelearningid' => $instance->id, 'userid' => $student->id, 'itemnumber' => 2,
        ], '*', MUST_EXIST);
        $this->assertEqualsWithDelta(0.8, (float) $a1->scaledscore, 0.0001);
        $this->assertEqualsWithDelta(0.4, (float) $a2->scaledscore, 0.0001);

        // And the published gradebook columns match.
        $this->assertEqualsWithDelta(80.0, $this->published_grade($instance, $student->id, 1), 0.0001);
        $this->assertEqualsWithDelta(40.0, $this->published_grade($instance, $student->id, 2), 0.0001);
    }

    /**
     * The headline RIE-007 case: two gradable iDevices on different pages that share
     * the same page-local index N. objectid routing keeps them distinct; the legacy
     * N-routing collides (both look like itemnumber=2) and loses item 1.
     */
    public function test_collision_same_pagelocal_n_different_pages(): void {
        global $DB;
        [$instance, $student] = $this->create_activity_with_student(
            ['packagefilepath' => 'research/fixtures/elpx/multipage-gradable.elpx']
        );

        // Both iDevices live at page-local DOM index 2 on their own page.
        $this->assertSame('idevice-tf-0001', $this->objectid_for($instance, 1));
        $this->assertSame('idevice-guess-0002', $this->objectid_for($instance, 2));

        // objectid routing: each score reaches its own column.
        $attempt = local\attempts::resolve_attempt_number($instance->id, $student->id, 'sessOK');
        $saved = track::apply_item_scores($instance, $student->id, $attempt, [
            'idevice-tf-0001'    => ['scorepct' => 90.0, 'weighted' => 100.0, 'title' => 'tf'],
            'idevice-guess-0002' => ['scorepct' => 30.0, 'weighted' => 100.0, 'title' => 'guess'],
        ], 'sessOK');
        $this->assertEqualsWithDelta(90.0, $saved[1], 0.0001);
        $this->assertEqualsWithDelta(30.0, $saved[2], 0.0001);
        $this->assertEqualsWithDelta(90.0, $this->published_grade($instance, $student->id, 1), 0.0001);
        $this->assertEqualsWithDelta(30.0, $this->published_grade($instance, $student->id, 2), 0.0001);

        // Contrast: the legacy path sees only N=2 (the collided survivor in
        // suspend_data), so item 1 never receives a grade. This is the bug the
        // objectid map fixes — asserted here so a regression is caught.
        [$instance2, $student2] = $this->create_activity_with_student(
            ['packagefilepath' => 'research/fixtures/elpx/multipage-gradable.elpx']
        );
        $attempt2 = local\attempts::resolve_attempt_number($instance2->id, $student2->id, 'sessLegacy');
        $savedlegacy = track::apply_legacy_peritem($instance2, $student2->id, $attempt2, [
            2 => ['scorepct' => 30.0, 'weighted' => 100.0, 'title' => 'guess'],
        ], 'sessLegacy');
        $this->assertArrayNotHasKey(1, $savedlegacy, 'legacy N-routing cannot reach item 1 under collision');
        $this->assertArrayHasKey(2, $savedlegacy);
        $this->assertNull(
            $this->published_grade($instance2, $student2->id, 1),
            'legacy routing leaves item 1 ungraded (the RIE-007 data loss)'
        );
    }

    /**
     * Backward compatibility: for a single-page package whose iDevices are all
     * gradable, the legacy N-routing fallback still lands each score correctly.
     */
    public function test_legacy_suspenddata_fallback_unchanged(): void {
        [$instance, $student] = $this->create_activity_with_student();

        // The default fixture is single-page with two gradable iDevices, so N==itemnumber.
        $attempt = local\attempts::resolve_attempt_number($instance->id, $student->id, 'sessL');
        $saved = track::apply_legacy_peritem($instance, $student->id, $attempt, [
            1 => ['scorepct' => 70.0, 'weighted' => 100.0, 'title' => 'a'],
            2 => ['scorepct' => 50.0, 'weighted' => 100.0, 'title' => 'b'],
        ], 'sessL');

        $this->assertEqualsWithDelta(70.0, $saved[1], 0.0001);
        $this->assertEqualsWithDelta(50.0, $saved[2], 0.0001);
        $this->assertEqualsWithDelta(70.0, $this->published_grade($instance, $student->id, 1), 0.0001);
        $this->assertEqualsWithDelta(50.0, $this->published_grade($instance, $student->id, 2), 0.0001);
    }

    /**
     * An objectid not registered as a gradable iDevice is ignored (no fatal, no row).
     */
    public function test_unknown_objectid_is_ignored(): void {
        global $DB;
        [$instance, $student] = $this->create_activity_with_student();

        $attempt = local\attempts::resolve_attempt_number($instance->id, $student->id, 'sessU');
        $saved = track::apply_item_scores($instance, $student->id, $attempt, [
            'no-such-objectid' => ['scorepct' => 99.0, 'weighted' => 100.0, 'title' => 'x'],
        ], 'sessU');

        $this->assertSame([], $saved);
        $this->assertSame(0, $DB->count_records('exelearning_attempt', [
            'exelearningid' => $instance->id, 'userid' => $student->id,
        ]));
    }
}
