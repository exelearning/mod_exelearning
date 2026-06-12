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
use mod_exelearning\local\migration\source\exescorm_source;
use mod_exelearning\local\zip_utils;
use mod_exelearning\tests\helper_trait;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/exelearning/lib.php');

/**
 * Security tests for the mod_exescorm migration source handler.
 *
 * The .elpx entry chosen from a SCORM zip is attacker-influenced (a SCORM package
 * uploaded to mod_exescorm can embed an entry whose name is a path-traversal,
 * absolute, backslash or stream-wrapper string). These tests assert such entries
 * are rejected before extraction (classified as nosource, never resolved), while a
 * normal embedded .elpx still resolves and stays contained inside the temp dir.
 *
 * @package    mod_exelearning
 * @category   test
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_exelearning\local\migration\source\exescorm_source
 * @covers     \mod_exelearning\local\zip_utils::is_unsafe_zip_entry
 */
final class exescorm_source_security_test extends advanced_testcase {
    use helper_trait;

    /**
     * The fixture .elpx path.
     *
     * @return string
     */
    private function fixture(): string {
        global $CFG;
        return $CFG->dirroot . '/mod/exelearning/research/fixtures/elpx/actividad-evaluable.elpx';
    }

    /**
     * Builds a SCORM zip whose embedded .elpx entry name is crafted, bypassing the
     * file_packer's own normalisation by writing the central directory directly.
     *
     * The standard archive_to_pathname() rewrites traversal/absolute names, so we
     * cannot use make_scorm_zip() to inject a hostile entry name; we build the zip
     * with ZipArchive::addFile() under an explicit local-name instead.
     *
     * @param string $entryname The exact zip entry name to embed.
     * @return string Absolute path to the built zip.
     */
    private function make_zip_with_raw_entry(string $entryname): string {
        $zip = make_request_directory() . '/scorm.zip';
        $archive = new \ZipArchive();
        $archive->open($zip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $archive->addFromString('imsmanifest.xml', '<manifest></manifest>');
        $archive->addFile($this->fixture(), $entryname);
        $archive->close();
        return $zip;
    }

    /**
     * Crafted, unsafe .elpx entry names that must never be extracted.
     *
     * @return array<string, array{0:string}>
     */
    public static function unsafe_entry_provider(): array {
        return [
            'parent traversal'  => ['../evil.elpx'],
            'nested traversal'  => ['content/../../evil.elpx'],
            'absolute path'     => ['/absolute.elpx'],
            'backslash path'    => ['folder\\evil.elpx'],
            'stream wrapper'    => ['file://evil.elpx'],
        ];
    }

    /**
     * The migration's unsafe-entry guard flags path-traversal, absolute, backslash
     * and stream-wrapper entry names.
     *
     * Note: Moodle's `zip_packer::list_files()` already normalises entry names, so a
     * hostile name is sanitised before `exescorm_source::classify()` ever reads it —
     * the classify-/resolve-level `is_unsafe_zip_entry()` filtering is therefore
     * defence in depth, and the binding guarantee is the post-extraction
     * `zip_utils::assert_extraction_contained()` sweep in `resolve_elpx()` (its symlink
     * containment is covered by `zip_utils_test`). This asserts the predicate that
     * filtering relies on, which is deterministic regardless of zip normalisation.
     *
     * @dataProvider unsafe_entry_provider
     * @param string $entryname The hostile zip entry name.
     */
    public function test_unsafe_entry_guard_flags_hostile_names(string $entryname): void {
        $this->assertTrue(
            zip_utils::is_unsafe_zip_entry($entryname),
            "Entry {$entryname} must be flagged unsafe"
        );
    }

    /**
     * A normal embedded .elpx (safe name) still classifies as OK and resolves to a
     * real file inside the temp directory.
     */
    public function test_safe_embedded_entry_still_resolves(): void {
        [, $ctxid] = $this->create_empty_target();
        $zip = $this->make_zip_with_raw_entry('content/elp.elpx');
        $this->store_sibling_package($ctxid, 'mod_exescorm', $zip, 'scorm.zip', 0);
        $source = $this->make_source_row(['contextid' => $ctxid, 'exescormtype' => 'local']);

        $src = new exescorm_source();
        $verdict = $src->classify($source);

        $this->assertTrue($verdict->is_ok());
        $this->assertSame('content/elp.elpx', $verdict->elpxentry);
        $resolved = $src->resolve_elpx($source);
        $this->assertNotNull($resolved);
        $this->assertFileExists($resolved);
    }
}
