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

namespace mod_exelearning\local;

use advanced_testcase;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/exelearning/lib.php');

/**
 * Tests for the centralised valid-extraction guard in package_manager::extract_stored().
 *
 * extract_to_storage() returns false on a corrupt/empty archive without throwing,
 * so the content area silently ends up with no servable index.html. extract_stored()
 * is the single extraction engine behind every entry point (form upload, editor save,
 * view self-heal and migration via the lib.php delegator), so it is where the guard
 * belongs: a package that produces no index.html must raise a clear moodle_exception
 * instead of recording an empty shell.
 *
 * @package    mod_exelearning
 * @category   test
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_exelearning\local\package_manager
 */
final class package_manager_extract_test extends advanced_testcase {
    /**
     * Stores a built ZIP in the 'package' filearea at the given itemid.
     *
     * @param int $contextid
     * @param array $entries Map of zip entry name => string content.
     * @param string $filename
     * @param int $itemid Package itemid (default 0; a higher value stages a replacement
     *                    revision that get_stored_package() picks up as the newest).
     * @return void
     */
    private function store_package_zip(int $contextid, array $entries, string $filename, int $itemid = 0): void {
        $stage = make_request_directory();
        $files = [];
        foreach ($entries as $name => $content) {
            $abs = $stage . '/' . $name;
            check_dir_exists(dirname($abs));
            file_put_contents($abs, $content);
            $files[$name] = $abs;
        }
        $zip = make_request_directory() . '/' . $filename;
        get_file_packer('application/zip')->archive_to_pathname($files, $zip);

        get_file_storage()->create_file_from_pathname([
            'contextid' => $contextid,
            'component' => 'mod_exelearning',
            'filearea'  => 'package',
            'itemid'    => $itemid,
            'filepath'  => '/',
            'filename'  => $filename,
        ], $zip);
    }

    /**
     * Stores a raw (non-archive) blob in the 'package' filearea so extraction fails.
     *
     * @param int $contextid
     * @param string $filename
     * @param int $itemid Package itemid (default 0).
     * @return void
     */
    private function store_corrupt_package(int $contextid, string $filename, int $itemid = 0): void {
        $blob = make_request_directory() . '/' . $filename;
        file_put_contents($blob, 'this is not a real zip archive');
        get_file_storage()->create_file_from_pathname([
            'contextid' => $contextid,
            'component' => 'mod_exelearning',
            'filearea'  => 'package',
            'itemid'    => $itemid,
            'filepath'  => '/',
            'filename'  => $filename,
        ], $blob);
    }

    /**
     * Stores the real evaluable .elpx fixture (a valid package) at the given itemid.
     *
     * @param int $contextid
     * @param int $itemid Package itemid.
     * @return void
     */
    private function store_fixture_package(int $contextid, int $itemid): void {
        global $CFG;
        get_file_storage()->create_file_from_pathname([
            'contextid' => $contextid,
            'component' => 'mod_exelearning',
            'filearea'  => 'package',
            'itemid'    => $itemid,
            'filepath'  => '/',
            'filename'  => 'valid.elpx',
        ], $CFG->dirroot . '/mod/exelearning/research/fixtures/elpx/actividad-evaluable.elpx');
    }

    /**
     * Builds an empty no-package target and returns its context id and revision.
     *
     * @return array{0:int,1:int} The module context id and the instance revision.
     */
    private function create_target(): array {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        /** @var \mod_exelearning_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_exelearning');
        $instance = $generator->create_instance(['course' => $course->id, 'packagefilepath' => false]);
        $cm = get_coursemodule_from_instance('exelearning', $instance->id);
        return [(int) \context_module::instance($cm->id)->id, (int) $instance->revision];
    }

    /**
     * A corrupt (non-archive) stored package makes extract_stored() throw a clear
     * exception instead of leaving an empty content area.
     */
    public function test_extract_stored_throws_on_corrupt_package(): void {
        [$contextid, $revision] = $this->create_target();
        $this->store_corrupt_package($contextid, 'broken.elpx');

        try {
            package_manager::extract_stored($contextid, $revision);
            $this->fail('A corrupt package must throw');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('migrateextractfailed', $e->errorcode);
        }
        // The packer logs a developer-only debugging message for the unreadable zip.
        $this->assertDebuggingCalled();
    }

    /**
     * An archive that extracts but carries no index.html is rejected too.
     */
    public function test_extract_stored_throws_when_index_html_missing(): void {
        [$contextid, $revision] = $this->create_target();
        $this->store_package_zip($contextid, ['content.xml' => '<ode/>'], 'noindex.elpx');

        try {
            package_manager::extract_stored($contextid, $revision);
            $this->fail('A package without index.html must throw');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('migrateextractfailed', $e->errorcode);
        }
    }

    /**
     * A valid package (with index.html) extracts without throwing and lands the
     * mainfile in the content filearea.
     */
    public function test_extract_stored_accepts_valid_package(): void {
        global $CFG;
        [$contextid, $revision] = $this->create_target();
        $fixture = $CFG->dirroot . '/mod/exelearning/research/fixtures/elpx/actividad-evaluable.elpx';
        get_file_storage()->create_file_from_pathname([
            'contextid' => $contextid,
            'component' => 'mod_exelearning',
            'filearea'  => 'package',
            'itemid'    => 0,
            'filepath'  => '/',
            'filename'  => 'valid.elpx',
        ], $fixture);

        package_manager::extract_stored($contextid, $revision);

        $this->assertNotEmpty(get_file_storage()->get_file(
            $contextid,
            'mod_exelearning',
            'content',
            $revision,
            '/',
            'index.html'
        ));
    }

    /**
     * The lib.php delegator routes through the same guard, so migration still raises
     * a clear error on a corrupt package.
     */
    public function test_delegator_propagates_guard(): void {
        [$contextid, $revision] = $this->create_target();
        $this->store_corrupt_package($contextid, 'broken.elpx');

        try {
            exelearning_extract_stored_package($contextid, $revision);
            $this->fail('The delegator must propagate the guard');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('migrateextractfailed', $e->errorcode);
        }
        $this->assertDebuggingCalled();
    }

    /**
     * A corrupt replacement (non-archive) must NOT destroy the previously extracted,
     * still-valid content: extract_stored() scopes its wipe to the target revision and
     * rolls back its own partial revision on failure, leaving the prior revision intact
     * (issue 73).
     */
    public function test_corrupt_replacement_preserves_previous_content(): void {
        [$contextid] = $this->create_target();
        $fs = get_file_storage();

        // Revision 1: a valid package, extracted and servable.
        $this->store_fixture_package($contextid, 0);
        package_manager::extract_stored($contextid, 1);
        $this->assertNotFalse($fs->get_file($contextid, 'mod_exelearning', 'content', 1, '/', 'index.html'));

        // Revision 2: a corrupt replacement as the newest package.
        $this->store_corrupt_package($contextid, 'broken.elpx', 2);
        try {
            package_manager::extract_stored($contextid, 2);
            $this->fail('A corrupt replacement must throw');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('migrateextractfailed', $e->errorcode);
        }
        $this->assertDebuggingCalled();

        // Previous revision preserved; failed revision rolled back to nothing.
        $this->assertNotFalse($fs->get_file($contextid, 'mod_exelearning', 'content', 1, '/', 'index.html'));
        $this->assertEmpty($fs->get_area_files($contextid, 'mod_exelearning', 'content', 2, 'id', false));
    }

    /**
     * A replacement that extracts but carries no index.html is rejected too, again
     * without destroying the previous revision (issue 73).
     */
    public function test_missing_index_replacement_preserves_previous_content(): void {
        [$contextid] = $this->create_target();
        $fs = get_file_storage();

        $this->store_fixture_package($contextid, 0);
        package_manager::extract_stored($contextid, 1);
        $this->assertNotFalse($fs->get_file($contextid, 'mod_exelearning', 'content', 1, '/', 'index.html'));

        // Revision 2: a structurally valid ZIP but with no servable index.html.
        $this->store_package_zip($contextid, ['content.xml' => '<ode/>'], 'noindex.elpx', 2);
        try {
            package_manager::extract_stored($contextid, 2);
            $this->fail('A package without index.html must throw');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('migrateextractfailed', $e->errorcode);
        }

        $this->assertNotFalse($fs->get_file($contextid, 'mod_exelearning', 'content', 1, '/', 'index.html'));
        $this->assertEmpty($fs->get_area_files($contextid, 'mod_exelearning', 'content', 2, 'id', false));
    }

    /**
     * A successful extraction is non-destructive to sibling revisions: the engine keeps
     * both revisions and leaves pruning to the orchestrator, which only prunes after the
     * DB pointer has moved (issue 73).
     */
    public function test_successful_extraction_keeps_sibling_revisions(): void {
        [$contextid] = $this->create_target();
        $fs = get_file_storage();

        $this->store_fixture_package($contextid, 0);
        package_manager::extract_stored($contextid, 1);

        $this->store_fixture_package($contextid, 2);
        package_manager::extract_stored($contextid, 2);

        $this->assertNotFalse($fs->get_file($contextid, 'mod_exelearning', 'content', 1, '/', 'index.html'));
        $this->assertNotFalse($fs->get_file($contextid, 'mod_exelearning', 'content', 2, '/', 'index.html'));
    }

    /**
     * Re-extracting the same revision (the view.php self-heal path) is idempotent: it
     * clears only that revision and rebuilds it, never throwing on a valid package.
     */
    public function test_reextract_same_revision_is_idempotent(): void {
        [$contextid] = $this->create_target();
        $fs = get_file_storage();

        $this->store_fixture_package($contextid, 0);
        package_manager::extract_stored($contextid, 1);
        package_manager::extract_stored($contextid, 1);

        $this->assertNotFalse($fs->get_file($contextid, 'mod_exelearning', 'content', 1, '/', 'index.html'));
    }

    /**
     * The editor orchestration (store_and_activate_revision) must NOT advance the stored
     * revision nor destroy the previous package + content when the staged package is
     * corrupt: it throws before moving the pointer (issue 73).
     */
    public function test_store_and_activate_corrupt_preserves_old_package_and_content(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        /** @var \mod_exelearning_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_exelearning');
        $instance = $generator->create_instance(['course' => $course->id]);
        $cm = get_coursemodule_from_instance('exelearning', $instance->id);
        $contextid = (int) \context_module::instance($cm->id)->id;
        $fs = get_file_storage();

        // Baseline: revision 1 with extracted content and a stored package.
        $this->assertNotFalse($fs->get_file($contextid, 'mod_exelearning', 'content', 1, '/', 'index.html'));
        $oldpackage = package_manager::get_stored_package($contextid);
        $this->assertNotNull($oldpackage);
        $olditemid = (int) $oldpackage->get_itemid();
        $oldname = $oldpackage->get_filename();

        // Stage a corrupt replacement at the next revision, as editor/save.php would.
        $this->store_corrupt_package($contextid, 'broken.elpx', 2);
        $exelearning = $DB->get_record('exelearning', ['id' => $instance->id], '*', MUST_EXIST);
        try {
            package_manager::store_and_activate_revision($contextid, $exelearning, 2);
            $this->fail('A corrupt activation must throw');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('migrateextractfailed', $e->errorcode);
        }
        $this->assertDebuggingCalled();

        // Pointer unchanged; previous package + content intact; staged content rolled back.
        $this->assertSame(1, (int) $DB->get_field('exelearning', 'revision', ['id' => $instance->id]));
        $this->assertNotFalse($fs->get_file($contextid, 'mod_exelearning', 'content', 1, '/', 'index.html'));
        $this->assertNotFalse($fs->get_file($contextid, 'mod_exelearning', 'package', $olditemid, '/', $oldname));
        $this->assertEmpty($fs->get_area_files($contextid, 'mod_exelearning', 'content', 2, 'id', false));
    }

    /**
     * A valid editor activation swaps to the new revision, serves the new content and
     * prunes the superseded content + package revisions (issue 73).
     */
    public function test_store_and_activate_valid_swaps_and_prunes(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        /** @var \mod_exelearning_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_exelearning');
        $instance = $generator->create_instance(['course' => $course->id]);
        $cm = get_coursemodule_from_instance('exelearning', $instance->id);
        $contextid = (int) \context_module::instance($cm->id)->id;
        $fs = get_file_storage();

        // Stage a valid replacement at revision 2.
        $this->store_fixture_package($contextid, 2);
        $exelearning = $DB->get_record('exelearning', ['id' => $instance->id], '*', MUST_EXIST);
        package_manager::store_and_activate_revision($contextid, $exelearning, 2);

        $this->assertSame(2, (int) $DB->get_field('exelearning', 'revision', ['id' => $instance->id]));
        $this->assertNotFalse($fs->get_file($contextid, 'mod_exelearning', 'content', 2, '/', 'index.html'));
        $this->assertFalse($fs->get_file($contextid, 'mod_exelearning', 'content', 1, '/', 'index.html'));

        // Only the activated package revision remains.
        $itemids = [];
        foreach ($fs->get_area_files($contextid, 'mod_exelearning', 'package', false, 'itemid', false) as $file) {
            $itemids[(int) $file->get_itemid()] = true;
        }
        $this->assertSame([2 => true], $itemids);
    }
}
