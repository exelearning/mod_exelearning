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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/exelearning/lib.php');
require_once($CFG->libdir . '/gradelib.php');

/**
 * Unit tests for mod_exelearning lib.php (instance lifecycle + grade items).
 *
 * @package    mod_exelearning
 * @category   test
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::exelearning_add_instance
 * @covers     ::exelearning_update_instance
 * @covers     ::exelearning_delete_instance
 * @covers     ::exelearning_sync_grade_items
 */
final class lib_test extends advanced_testcase {
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
     * Adding an instance from the evaluable fixture detects 2 gradable iDevices.
     */
    public function test_add_instance_detects_gradeitems(): void {
        global $DB;

        $instance = $this->create_activity();

        // Two rows in exelearning_grade_item (trueorfalse + guess), none deleted.
        $rows = $DB->get_records(
            'exelearning_grade_item',
            ['exelearningid' => $instance->id, 'deleted' => 0]
        );
        $this->assertCount(2, $rows);

        $types = array_values(array_map(fn($r) => $r->idevicetype, $rows));
        sort($types);
        $this->assertSame(['guess', 'trueorfalse'], $types);

        // The mapped itemnumbers are 1 and 2.
        $itemnumbers = array_values(array_map(fn($r) => (int) $r->itemnumber, $rows));
        sort($itemnumbers);
        $this->assertSame([1, 2], $itemnumbers);

        // Grade items 0, 1 and 2 exist (default model BOTH).
        foreach ([0, 1, 2] as $itemnumber) {
            $gi = grade_item::fetch([
                'itemtype'     => 'mod',
                'itemmodule'   => 'exelearning',
                'iteminstance' => $instance->id,
                'itemnumber'   => $itemnumber,
                'courseid'     => $instance->course,
            ]);
            $this->assertInstanceOf(
                grade_item::class,
                $gi,
                "grade_item itemnumber={$itemnumber} should exist in BOTH model"
            );
        }
    }

    /**
     * grademodel OVERALL: only itemnumber=0 is an active gradebook column.
     */
    public function test_grademodel_overall(): void {
        $instance = $this->create_activity(['grademodel' => EXELEARNING_GRADEMODEL_OVERALL]);

        $overall = grade_item::fetch([
            'itemtype'     => 'mod',
            'itemmodule'   => 'exelearning',
            'iteminstance' => $instance->id,
            'itemnumber'   => 0,
            'courseid'     => $instance->course,
        ]);
        $this->assertInstanceOf(grade_item::class, $overall);

        foreach ([1, 2] as $itemnumber) {
            $gi = grade_item::fetch([
                'itemtype'     => 'mod',
                'itemmodule'   => 'exelearning',
                'iteminstance' => $instance->id,
                'itemnumber'   => $itemnumber,
                'courseid'     => $instance->course,
            ]);
            $this->assertFalse(
                $gi,
                "grade_item itemnumber={$itemnumber} must not exist in OVERALL model"
            );
        }
    }

    /**
     * grademodel PERITEM: itemnumber=0 absent, per-iDevice columns present.
     */
    public function test_grademodel_peritem(): void {
        $instance = $this->create_activity(['grademodel' => EXELEARNING_GRADEMODEL_PERITEM]);

        $overall = grade_item::fetch([
            'itemtype'     => 'mod',
            'itemmodule'   => 'exelearning',
            'iteminstance' => $instance->id,
            'itemnumber'   => 0,
            'courseid'     => $instance->course,
        ]);
        $this->assertFalse($overall, 'overall (itemnumber=0) must not exist in PERITEM model');

        foreach ([1, 2] as $itemnumber) {
            $gi = grade_item::fetch([
                'itemtype'     => 'mod',
                'itemmodule'   => 'exelearning',
                'iteminstance' => $instance->id,
                'itemnumber'   => $itemnumber,
                'courseid'     => $instance->course,
            ]);
            $this->assertInstanceOf(
                grade_item::class,
                $gi,
                "grade_item itemnumber={$itemnumber} should exist in PERITEM model"
            );
        }
    }

    /**
     * Deleting an instance wipes all of its rows across the three tables.
     */
    public function test_delete_instance(): void {
        global $DB;

        $instance = $this->create_activity();

        // Seed an attempt to prove it gets cleaned too.
        $DB->insert_record('exelearning_attempt', (object) [
            'exelearningid' => $instance->id,
            'userid'        => 2,
            'attempt'       => 1,
            'itemnumber'    => 1,
            'rawscore'      => 50,
            'maxscore'      => 100,
            'scaledscore'   => 0.5,
            'status'        => 'completed',
            'sessiontoken'  => 'tok',
            'timecreated'   => time(),
            'timemodified'  => time(),
        ]);

        $this->assertTrue(exelearning_delete_instance($instance->id));

        $this->assertFalse($DB->record_exists('exelearning', ['id' => $instance->id]));
        $this->assertSame(0, $DB->count_records(
            'exelearning_grade_item',
            ['exelearningid' => $instance->id]
        ));
        $this->assertSame(0, $DB->count_records(
            'exelearning_attempt',
            ['exelearningid' => $instance->id]
        ));
    }

    /**
     * Self-heal: clearing grade items and re-syncing re-detects the two iDevices.
     */
    public function test_selfheal_extract_and_sync(): void {
        global $DB;

        $instance = $this->create_activity();
        $cm = get_coursemodule_from_instance('exelearning', $instance->id);
        $contextid = \context_module::instance($cm->id)->id;

        // Wipe the mapping rows as if the activity lost its detection.
        $DB->delete_records('exelearning_grade_item', ['exelearningid' => $instance->id]);
        $this->assertSame(0, $DB->count_records(
            'exelearning_grade_item',
            ['exelearningid' => $instance->id]
        ));

        // Re-run detection.
        exelearning_sync_grade_items($instance->id, $contextid);

        $rows = $DB->get_records(
            'exelearning_grade_item',
            ['exelearningid' => $instance->id, 'deleted' => 0]
        );
        $this->assertCount(2, $rows);
    }
}
