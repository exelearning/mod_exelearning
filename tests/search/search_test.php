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

namespace mod_exelearning\search;

use advanced_testcase;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/search/tests/fixtures/testable_core_search.php');

/**
 * Unit tests for mod_exelearning global search integration.
 *
 * Verifies the activity search area: enable/disable toggling, the document
 * recordset (global and per-context), document field population from the intro,
 * the file-indexing contract for the extracted package, and the access checks
 * inherited from \core_search\base_activity. Mirrors mod_book search tests.
 *
 * @package    mod_exelearning
 * @category   test
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_exelearning\search\activity
 */
final class search_test extends advanced_testcase {
    /** @var string The activity search area id. */
    protected $areaid = null;

    /**
     * Enable global search and prepare the testable engine before each test.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        set_config('enableglobalsearch', true);

        $this->areaid = \core_search\manager::generate_areaid('mod_exelearning', 'activity');

        // Use the mock search engine: the real engine is not required to test the area.
        \testable_core_search::instance();
    }

    /**
     * The area is auto-discovered and enabled once global search is on, and the
     * admin can toggle it off and on again.
     *
     * @return void
     */
    public function test_search_enabled(): void {
        $searcharea = \core_search\manager::get_search_area($this->areaid);
        $this->assertInstanceOf('\mod_exelearning\search\activity', $searcharea);

        [$componentname, $varname] = $searcharea->get_config_var_name();

        // Enabled by default once global search is enabled.
        $this->assertTrue($searcharea->is_enabled());

        set_config($varname . '_enabled', 0, $componentname);
        $this->assertFalse($searcharea->is_enabled());

        set_config($varname . '_enabled', 1, $componentname);
        $this->assertTrue($searcharea->is_enabled());
    }

    /**
     * Indexing produces one document per activity, with intro mapped to the
     * document content, and the recordset honours the timestamp and the context
     * restriction (module and course).
     *
     * @return void
     */
    public function test_activities_indexing(): void {
        global $DB;

        $searcharea = \core_search\manager::get_search_area($this->areaid);
        $this->assertInstanceOf('\mod_exelearning\search\activity', $searcharea);

        $course1 = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_exelearning');
        $exe1 = $generator->create_instance([
            'course' => $course1->id,
            'name'   => 'Findable activity one',
            'intro'  => 'Searchable intro about volcanoes',
            'introformat' => FORMAT_HTML,
        ]);
        $exe2 = $generator->create_instance([
            'course' => $course1->id,
            'name'   => 'Findable activity two',
            'intro'  => 'Searchable intro about earthquakes',
            'introformat' => FORMAT_HTML,
        ]);

        // All records since epoch.
        $recordset = $searcharea->get_recordset_by_timestamp(0);
        $this->assertTrue($recordset->valid());
        $nrecords = 0;
        foreach ($recordset as $record) {
            $this->assertInstanceOf('stdClass', $record);
            $doc = $searcharea->get_document($record);
            $this->assertInstanceOf('\core_search\document', $doc);

            // Static caches: a second get_document() must not add DB reads.
            $dbreads = $DB->perf_get_reads();
            $doc = $searcharea->get_document($record);
            $this->assertEquals($dbreads, $DB->perf_get_reads());
            $this->assertInstanceOf('\core_search\document', $doc);
            $nrecords++;
        }
        // If the loop above failed, the recordset would be closed on shutdown.
        $recordset->close();
        $this->assertEquals(2, $nrecords);

        // The +2 prevents race conditions on the timestamp boundary.
        $recordset = $searcharea->get_recordset_by_timestamp(time() + 2);
        $this->assertFalse($recordset->valid());
        $recordset->close();

        // Another course with one activity.
        $course2 = $this->getDataGenerator()->create_course();
        $generator->create_instance(['course' => $course2->id]);

        // Per module context.
        $recordset = $searcharea->get_document_recordset(
            0,
            \context_module::instance($exe1->cmid)
        );
        $this->assertEquals(1, iterator_count($recordset));
        $recordset->close();

        // Per course context: only the two activities in course1.
        $recordset = $searcharea->get_document_recordset(
            0,
            \context_course::instance($course1->id)
        );
        $this->assertEquals(2, iterator_count($recordset));
        $recordset->close();
    }

    /**
     * The generated document carries the expected title/content/context fields so
     * the activity intro becomes findable.
     *
     * @return void
     */
    public function test_document_contents(): void {
        global $DB;

        $searcharea = \core_search\manager::get_search_area($this->areaid);

        $course = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_exelearning');
        $exe = $generator->create_instance([
            'course' => $course->id,
            'name'   => 'Volcano lesson',
            'intro'  => 'Intro about pyroclastic flows',
            'introformat' => FORMAT_HTML,
        ]);

        $record = $DB->get_record('exelearning', ['id' => $exe->id], '*', MUST_EXIST);
        $doc = $searcharea->get_document($record);
        $this->assertInstanceOf('\core_search\document', $doc);

        $context = \context_module::instance($exe->cmid);
        $this->assertEquals('Volcano lesson', $doc->get('title'));
        $this->assertStringContainsString('pyroclastic flows', $doc->get('content'));
        $this->assertEquals($context->id, $doc->get('contextid'));
        $this->assertEquals($course->id, $doc->get('courseid'));
    }

    /**
     * File indexing is enabled and covers the extracted package, so the eXeLearning
     * content stored in the `content` file area is attached to the document.
     *
     * @return void
     */
    public function test_file_indexing(): void {
        global $DB;

        $searcharea = \core_search\manager::get_search_area($this->areaid);

        $this->assertTrue($searcharea->uses_file_indexing());
        $this->assertEqualsCanonicalizing(
            ['intro', 'content'],
            $searcharea->get_search_fileareas()
        );

        $course = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_exelearning');
        // The default fixture is a real ELPX, extracted into the `content` area.
        $exe = $generator->create_instance(['course' => $course->id]);

        $record = $DB->get_record('exelearning', ['id' => $exe->id], '*', MUST_EXIST);
        $doc = $searcharea->get_document($record);
        $searcharea->attach_files($doc);

        // The extracted package yields indexable files attached to the document.
        $this->assertNotEmpty($doc->get_files());
    }

    /**
     * Access is granted to enrolled users for a visible activity, denied for a
     * hidden one, and reported as deleted for a missing instance.
     *
     * @return void
     */
    public function test_check_access(): void {
        $searcharea = \core_search\manager::get_search_area($this->areaid);

        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');

        $generator = $this->getDataGenerator()->get_plugin_generator('mod_exelearning');
        $visible = $generator->create_instance(['course' => $course->id]);
        $hidden = $generator->create_instance(['course' => $course->id, 'visible' => 0]);

        $this->setAdminUser();
        $this->assertEquals(
            \core_search\manager::ACCESS_GRANTED,
            $searcharea->check_access($visible->id)
        );
        $this->assertEquals(
            \core_search\manager::ACCESS_GRANTED,
            $searcharea->check_access($hidden->id)
        );

        $this->setUser($user);
        $this->assertEquals(
            \core_search\manager::ACCESS_GRANTED,
            $searcharea->check_access($visible->id)
        );
        $this->assertEquals(
            \core_search\manager::ACCESS_DENIED,
            $searcharea->check_access($hidden->id)
        );

        $this->assertEquals(
            \core_search\manager::ACCESS_DELETED,
            $searcharea->check_access($hidden->id + 1000)
        );
    }
}
