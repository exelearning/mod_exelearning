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

        // Default model is PERITEM: the overall (itemnumber=0) is hidden for
        // completionpassgrade, while per-iDevice columns 1 and 2 are visible.
        $overall = grade_item::fetch([
            'itemtype'     => 'mod',
            'itemmodule'   => 'exelearning',
            'iteminstance' => $instance->id,
            'itemnumber'   => 0,
            'courseid'     => $instance->course,
        ]);
        $this->assertInstanceOf(grade_item::class, $overall);
        $this->assertTrue((bool) $overall->hidden);

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
                "grade_item itemnumber={$itemnumber} should exist in the default PERITEM model"
            );
        }
    }

    /**
     * Create-from-scratch (issue #13 #1, DEC-0024): an instance can be added with
     * no uploaded package. It is created cleanly with no stored package, no
     * content and no grade items, ready to be authored in the embedded editor.
     */
    public function test_create_instance_without_package(): void {
        global $DB;

        $instance = $this->create_activity(['packagefilepath' => false]);

        $this->assertNotEmpty($instance->id);
        $this->assertSame(1, (int) $instance->revision);

        $cm = get_coursemodule_from_instance('exelearning', $instance->id);
        $context = \context_module::instance($cm->id);

        // No package was stored and nothing was detected.
        $this->assertNull(exelearning_get_stored_package($context->id));
        $this->assertSame(0, $DB->count_records(
            'exelearning_grade_item',
            ['exelearningid' => $instance->id, 'deleted' => 0]
        ));

        // The package URL degrades to null so the editor opens a blank project.
        $this->assertNull(exelearning_get_package_url($instance, $context));
    }

    /**
     * A multi-page package registers one grade item per gradable iDevice, keyed by
     * the iDevice's stable objectid, even when those iDevices live on different
     * pages and share the same page-local DOM index (the RIE-007 / DEC-0017 case).
     */
    public function test_multipage_detects_distinct_objectids_per_page(): void {
        global $DB;

        $instance = $this->create_activity(
            ['packagefilepath' => 'research/fixtures/elpx/multipage-gradable.elpx']
        );

        $rows = $DB->get_records(
            'exelearning_grade_item',
            ['exelearningid' => $instance->id, 'deleted' => 0],
            'itemnumber ASC'
        );
        $this->assertCount(2, $rows);

        // Both gradable iDevices sit at page-local DOM index 2 on their respective
        // pages, yet they map to distinct objectids, distinct pages and stable,
        // sequential itemnumbers (1 and 2).
        $byitem = [];
        foreach ($rows as $r) {
            $byitem[(int) $r->itemnumber] = $r;
        }
        $this->assertSame([1, 2], array_keys($byitem));
        $this->assertSame('idevice-tf-0001', $byitem[1]->objectid);
        $this->assertSame('trueorfalse', $byitem[1]->idevicetype);
        $this->assertSame('idevice-guess-0002', $byitem[2]->objectid);
        $this->assertSame('guess', $byitem[2]->idevicetype);
        $this->assertNotEquals(
            $byitem[1]->pageid,
            $byitem[2]->pageid,
            'the two gradable iDevices must be attributed to different pages'
        );
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
     * grademodel PERITEM: itemnumber=0 hidden, per-iDevice columns present.
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
        $this->assertInstanceOf(grade_item::class, $overall);
        $this->assertTrue((bool) $overall->hidden);

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

    /**
     * The first sync stores a contenthash for each gradable iDevice and reports
     * them all as "added" (a fresh activity has no prior state).
     */
    public function test_sync_persists_contenthash(): void {
        global $DB;

        $instance = $this->create_activity();

        $rows = $DB->get_records(
            'exelearning_grade_item',
            ['exelearningid' => $instance->id, 'deleted' => 0]
        );
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', (string) $row->contenthash);
        }
    }

    /**
     * Re-syncing the same package reports no changes; a stored hash that no
     * longer matches the package (simulating an in-place options edit) is
     * reported as "changed" and the stored hash is refreshed (DEC-0021).
     */
    public function test_sync_delta_detects_edited_options(): void {
        global $DB;

        $instance = $this->create_activity();
        $cm = get_coursemodule_from_instance('exelearning', $instance->id);
        $contextid = \context_module::instance($cm->id)->id;

        // Re-syncing the unchanged package is a no-op delta.
        $delta = exelearning_sync_grade_items($instance->id, $contextid);
        $this->assertSame(
            ['added' => 0, 'removed' => 0, 'changed' => 0],
            $delta
        );

        // Simulate an in-place edit: one stored hash diverges from the package.
        $target = $DB->get_records(
            'exelearning_grade_item',
            ['exelearningid' => $instance->id, 'deleted' => 0],
            'itemnumber ASC',
            '*',
            0,
            1
        );
        $target = reset($target);
        $original = $target->contenthash;
        $DB->set_field('exelearning_grade_item', 'contenthash', 'stalehash', ['id' => $target->id]);

        $delta = exelearning_sync_grade_items($instance->id, $contextid);
        $this->assertSame(1, $delta['changed']);
        $this->assertSame(0, $delta['added']);
        $this->assertSame(0, $delta['removed']);

        // The stored hash is refreshed back to the real content hash.
        $this->assertSame(
            $original,
            $DB->get_field('exelearning_grade_item', 'contenthash', ['id' => $target->id])
        );
    }

    /**
     * activity_has_attempts() reflects the presence of attempt rows.
     */
    public function test_activity_has_attempts(): void {
        global $DB;

        $instance = $this->create_activity();

        $this->assertFalse(
            \mod_exelearning\local\attempts::activity_has_attempts($instance->id)
        );

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

        $this->assertTrue(
            \mod_exelearning\local\attempts::activity_has_attempts($instance->id)
        );
    }

    /**
     * The stale-grades warning is queued only when the gradable set changed AND
     * attempts exist; otherwise nothing is shown.
     */
    public function test_warn_if_grades_stale(): void {
        global $DB;

        $instance = $this->create_activity();
        $cm = get_coursemodule_from_instance('exelearning', $instance->id);

        $nochange = ['added' => 0, 'removed' => 0, 'changed' => 0];
        $changed  = ['added' => 0, 'removed' => 0, 'changed' => 1];

        // No attempts yet: even a real change is silent.
        \core\notification::fetch();
        exelearning_warn_if_grades_stale($instance->id, $changed, $cm->id);
        $this->assertCount(0, \core\notification::fetch());

        // With an attempt present, a change warns; no change stays silent.
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

        \core\notification::fetch();
        exelearning_warn_if_grades_stale($instance->id, $nochange, $cm->id);
        $this->assertCount(0, \core\notification::fetch());

        exelearning_warn_if_grades_stale($instance->id, $changed, $cm->id);
        $this->assertCount(1, \core\notification::fetch());
    }

    /**
     * Gradebook deep-link (issue #13 #4, DEC-0023): exelearning_grade_item_view_url()
     * maps an itemnumber to its iDevice objectid so grade.php can forward the click
     * straight to that iDevice; itemnumber 0 and unknown numbers fall back to the
     * activity front page.
     */
    public function test_grade_item_view_url_deeplinks_by_itemnumber(): void {
        global $DB;

        $instance = $this->create_activity();
        $cm = get_coursemodule_from_instance('exelearning', $instance->id);

        // The overall grade (itemnumber 0) links to the front page, no deep link.
        $overall = exelearning_grade_item_view_url($instance, (int) $cm->id, 0);
        $this->assertArrayNotHasKey('idevice', $overall->params());
        $this->assertSame((string) $cm->id, (string) $overall->params()['id']);

        // A per-iDevice grade item carries that iDevice's stable objectid.
        $objectid = $DB->get_field('exelearning_grade_item', 'objectid', [
            'exelearningid' => $instance->id,
            'itemnumber'    => 1,
            'deleted'       => 0,
        ]);
        $this->assertNotEmpty($objectid);
        $deeplink = exelearning_grade_item_view_url($instance, (int) $cm->id, 1);
        $this->assertSame($objectid, $deeplink->params()['idevice']);

        // An unknown itemnumber degrades gracefully to the front page.
        $unknown = exelearning_grade_item_view_url($instance, (int) $cm->id, 99);
        $this->assertArrayNotHasKey('idevice', $unknown->params());
    }
}
