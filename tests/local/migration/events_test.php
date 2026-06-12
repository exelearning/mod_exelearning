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

namespace mod_exelearning\local\migration;

use advanced_testcase;
use mod_exelearning\event\activity_migrated;
use mod_exelearning\event\activity_skipped;
use mod_exelearning\event\migration_failed;
use mod_exelearning\event\migration_started;
use mod_exelearning\tests\helper_trait;

/**
 * Unit tests for the migration events (issue #13 #3, DEC-0050).
 *
 * @package    mod_exelearning
 * @category   test
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_exelearning\event\migration_started
 * @covers     \mod_exelearning\event\activity_migrated
 * @covers     \mod_exelearning\event\activity_skipped
 * @covers     \mod_exelearning\event\migration_failed
 */
final class events_test extends advanced_testcase {
    use helper_trait;

    /**
     * migration_started carries the source component and total, and triggers cleanly.
     */
    public function test_migration_started_triggers(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $sink = $this->redirectEvents();
        migration_started::create([
            'context' => \context_system::instance(),
            'other'   => ['sourcecomponent' => 'mod_exeweb', 'total' => 7],
        ])->trigger();
        $events = $sink->get_events();

        $this->assertCount(1, $events);
        $this->assertInstanceOf(migration_started::class, $events[0]);
        $this->assertSame(7, $events[0]->other['total']);
    }

    /**
     * activity_migrated points at the created module and carries the source identity.
     */
    public function test_activity_migrated_triggers(): void {
        [$instance, $ctxid] = $this->create_empty_target();

        $sink = $this->redirectEvents();
        activity_migrated::create([
            'context'  => \context::instance_by_id($ctxid),
            'objectid' => (int) $instance->id,
            'other'    => ['sourcecomponent' => 'mod_exeweb', 'sourcecmid' => 42],
        ])->trigger();
        $events = $sink->get_events();

        $this->assertCount(1, $events);
        $this->assertInstanceOf(activity_migrated::class, $events[0]);
        $this->assertSame(42, $events[0]->other['sourcecmid']);
    }

    /**
     * activity_skipped carries the skip reason.
     */
    public function test_activity_skipped_triggers(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();

        $sink = $this->redirectEvents();
        activity_skipped::create([
            'context' => \context_course::instance($course->id),
            'other'   => [
                'sourcecomponent' => 'mod_exescorm',
                'sourcecmid'      => 9,
                'reason'          => migration_result::STATUS_UNSUPPORTED,
            ],
        ])->trigger();
        $events = $sink->get_events();

        $this->assertCount(1, $events);
        $this->assertSame(migration_result::STATUS_UNSUPPORTED, $events[0]->other['reason']);
    }

    /**
     * migration_failed carries the error message.
     */
    public function test_migration_failed_triggers(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();

        $sink = $this->redirectEvents();
        migration_failed::create([
            'context' => \context_course::instance($course->id),
            'other'   => ['sourcecomponent' => 'mod_exeweb', 'sourcecmid' => 3, 'error' => 'boom'],
        ])->trigger();
        $events = $sink->get_events();

        $this->assertCount(1, $events);
        $this->assertSame('boom', $events[0]->other['error']);
    }

    /**
     * Each event rejects missing required `other` keys.
     */
    public function test_events_validate_required_other_keys(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->expectException(\coding_exception::class);
        migration_started::create([
            'context' => \context_system::instance(),
            'other'   => ['sourcecomponent' => 'mod_exeweb'],
        ]);
    }
}
