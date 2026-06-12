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
use context_module;
use context_course;
use mod_exelearning\event\attempt_deleted;
use mod_exelearning\event\report_viewed;
use mod_exelearning\event\course_module_instance_list_viewed;

/**
 * Tests for the mod_exelearning events added for traceability (P2).
 *
 * @package    mod_exelearning
 * @category   test
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_exelearning\event\attempt_deleted
 * @covers     \mod_exelearning\event\report_viewed
 * @covers     \mod_exelearning\event\course_module_instance_list_viewed
 */
final class events_test extends advanced_testcase {
    /** @var \stdClass */
    protected $course;
    /** @var \stdClass */
    protected $instance;
    /** @var \stdClass */
    protected $cm;
    /** @var \stdClass */
    protected $student;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->course = $this->getDataGenerator()->create_course();
        $this->instance = $this->getDataGenerator()
            ->get_plugin_generator('mod_exelearning')
            ->create_instance(['course' => $this->course->id]);
        $this->cm = get_coursemodule_from_instance('exelearning', $this->instance->id, 0, false, MUST_EXIST);
        $this->student = $this->getDataGenerator()->create_user();
    }

    public function test_attempt_deleted_event(): void {
        $context = context_module::instance($this->cm->id);
        $event = attempt_deleted::create([
            'context'       => $context,
            'objectid'      => $this->instance->id,
            'relateduserid' => $this->student->id,
            'other'         => ['attemptid' => 3],
        ]);

        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();

        $this->assertCount(1, $events);
        $this->assertInstanceOf(attempt_deleted::class, $events[0]);
        $this->assertSame('d', $events[0]->crud);
        $this->assertSame(attempt_deleted::LEVEL_TEACHING, $events[0]->edulevel);
        $this->assertSame($context->id, $events[0]->contextid);
        $this->assertSame($this->student->id, $events[0]->relateduserid);
        $this->assertStringContainsString('3', $events[0]->get_description());
        $this->assertInstanceOf(\moodle_url::class, $events[0]->get_url());
    }

    public function test_attempt_deleted_requires_attemptid(): void {
        $context = context_module::instance($this->cm->id);
        $this->expectException(\coding_exception::class);
        attempt_deleted::create([
            'context'       => $context,
            'objectid'      => $this->instance->id,
            'relateduserid' => $this->student->id,
        ])->trigger();
    }

    public function test_report_viewed_event(): void {
        $context = context_module::instance($this->cm->id);
        $event = report_viewed::create([
            'context'  => $context,
            'objectid' => $this->instance->id,
        ]);

        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();

        $this->assertCount(1, $events);
        $this->assertInstanceOf(report_viewed::class, $events[0]);
        $this->assertSame('r', $events[0]->crud);
        $this->assertSame(report_viewed::LEVEL_TEACHING, $events[0]->edulevel);
        $this->assertStringContainsString('report', strtolower($events[0]->get_description()));
        $this->assertInstanceOf(\moodle_url::class, $events[0]->get_url());
    }

    public function test_instance_list_viewed_event(): void {
        $context = context_course::instance($this->course->id);
        $event = course_module_instance_list_viewed::create(['context' => $context]);

        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();

        $this->assertCount(1, $events);
        $this->assertInstanceOf(course_module_instance_list_viewed::class, $events[0]);
        $this->assertSame('r', $events[0]->crud);
    }

    /**
     * The restore objectid mappings are declared for each event.
     */
    public function test_event_objectid_mappings(): void {
        $this->assertIsArray(attempt_deleted::get_objectid_mapping());
        $this->assertIsArray(report_viewed::get_objectid_mapping());
        $this->assertIsArray(\mod_exelearning\event\course_module_viewed::get_objectid_mapping());
    }
}
