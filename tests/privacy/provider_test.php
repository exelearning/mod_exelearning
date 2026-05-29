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

namespace mod_exelearning\privacy;

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\tests\provider_testcase;
use mod_exelearning\local\attempts;

/**
 * Privacy provider tests for mod_exelearning.
 *
 * @package    mod_exelearning
 * @category   test
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_exelearning\privacy\provider
 */
final class provider_test extends provider_testcase {
    /** @var \stdClass exelearning instance. */
    protected $instance;

    /** @var \context_module the module context. */
    protected $context;

    /** @var \stdClass student with attempts. */
    protected $student1;

    /** @var \stdClass second student with attempts. */
    protected $student2;

    /** @var string component name. */
    protected $component = 'mod_exelearning';

    /**
     * Create a course, an exelearning instance, two students and seed attempts.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        /** @var \mod_exelearning_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_exelearning');
        $this->instance = $generator->create_instance(['course' => $course->id]);

        $cm = get_coursemodule_from_instance('exelearning', $this->instance->id);
        $this->context = \context_module::instance($cm->id);

        $this->student1 = $this->getDataGenerator()->create_user();
        $this->student2 = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->student1->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($this->student2->id, $course->id, 'student');

        foreach ([$this->student1, $this->student2] as $student) {
            attempts::record_item($this->instance->id, $student->id, 1, 0, 5, 10, 'completed', 't0');
            attempts::record_item($this->instance->id, $student->id, 1, 1, 7, 10, 'completed', 't0');
        }
    }

    /**
     * The module context is returned for a user that has attempts.
     */
    public function test_get_contexts_for_userid(): void {
        $contextlist = provider::get_contexts_for_userid($this->student1->id);
        $this->assertCount(1, $contextlist);
        $this->assertEquals($this->context->id, $contextlist->get_contextids()[0]);

        // A user with no attempts has no contexts.
        $stranger = $this->getDataGenerator()->create_user();
        $empty = provider::get_contexts_for_userid($stranger->id);
        $this->assertCount(0, $empty);
    }

    /**
     * Both students with attempts are listed for the module context.
     */
    public function test_get_users_in_context(): void {
        $userlist = new userlist($this->context, $this->component);
        provider::get_users_in_context($userlist);

        $userids = $userlist->get_userids();
        $this->assertCount(2, $userids);
        $this->assertContains((int) $this->student1->id, array_map('intval', $userids));
        $this->assertContains((int) $this->student2->id, array_map('intval', $userids));
    }

    /**
     * Exported data contains the user's attempts in the module context.
     */
    public function test_export_user_data(): void {
        $this->export_context_data_for_user($this->student1->id, $this->context, $this->component);

        $writer = writer::with_context($this->context);
        $this->assertTrue($writer->has_any_data());

        $data = $writer->get_data([]);
        $this->assertNotEmpty($data->attempts);
        $this->assertCount(2, $data->attempts);
    }

    /**
     * Deleting a single user's data leaves the other student untouched.
     */
    public function test_delete_data_for_user(): void {
        global $DB;

        $approved = new approved_contextlist($this->student1, $this->component, [$this->context->id]);
        provider::delete_data_for_user($approved);

        $this->assertSame(0, $DB->count_records(
            'exelearning_attempt',
            ['exelearningid' => $this->instance->id, 'userid' => $this->student1->id]
        ));
        $this->assertSame(2, $DB->count_records(
            'exelearning_attempt',
            ['exelearningid' => $this->instance->id, 'userid' => $this->student2->id]
        ));
    }

    /**
     * Deleting all data in the context wipes every user's attempts.
     */
    public function test_delete_data_for_all_users_in_context(): void {
        global $DB;

        provider::delete_data_for_all_users_in_context($this->context);

        $this->assertSame(0, $DB->count_records(
            'exelearning_attempt',
            ['exelearningid' => $this->instance->id]
        ));
    }

    /**
     * Deleting a set of approved users removes only those users' attempts.
     */
    public function test_delete_data_for_users(): void {
        global $DB;

        $approved = new approved_userlist($this->context, $this->component, [$this->student1->id]);
        provider::delete_data_for_users($approved);

        $this->assertSame(0, $DB->count_records(
            'exelearning_attempt',
            ['exelearningid' => $this->instance->id, 'userid' => $this->student1->id]
        ));
        $this->assertSame(2, $DB->count_records(
            'exelearning_attempt',
            ['exelearningid' => $this->instance->id, 'userid' => $this->student2->id]
        ));
    }
}
