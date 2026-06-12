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
     * Stores a built ZIP in the 'package' filearea at itemid 0.
     *
     * @param int $contextid
     * @param array<string,string> $entries Map of zip entry name => string content.
     * @param string $filename
     * @return void
     */
    private function store_package_zip(int $contextid, array $entries, string $filename): void {
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
            'itemid'    => 0,
            'filepath'  => '/',
            'filename'  => $filename,
        ], $zip);
    }

    /**
     * Stores a raw (non-archive) blob in the 'package' filearea so extraction fails.
     *
     * @param int $contextid
     * @param string $filename
     * @return void
     */
    private function store_corrupt_package(int $contextid, string $filename): void {
        $blob = make_request_directory() . '/' . $filename;
        file_put_contents($blob, 'this is not a real zip archive');
        get_file_storage()->create_file_from_pathname([
            'contextid' => $contextid,
            'component' => 'mod_exelearning',
            'filearea'  => 'package',
            'itemid'    => 0,
            'filepath'  => '/',
            'filename'  => $filename,
        ], $blob);
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
}
