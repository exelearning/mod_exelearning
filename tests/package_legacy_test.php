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

/**
 * Tests for the legacy exeweb package validator (used by editor/save.php).
 *
 * @package    mod_exelearning
 * @category   test
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_exelearning\exelearning_package_legacy
 */
final class package_legacy_test extends advanced_testcase {
    /**
     * With no mandatory/forbidden rules configured, any file list is accepted.
     */
    public function test_validate_file_list_accepts_when_no_rules(): void {
        $this->resetAfterTest();
        set_config('mandatoryfileslist', '', 'exelearning');
        set_config('forbiddenfileslist', '', 'exelearning');

        $list = [
            (object) ['pathname' => 'content.xml', 'is_directory' => false],
            (object) ['pathname' => 'index.html', 'is_directory' => false],
        ];

        $this->assertSame([], exelearning_package_legacy::validate_file_list($list));
    }

    /**
     * A mandatory-file regex must match at least one entry, otherwise the list
     * is rejected.
     */
    public function test_validate_file_list_requires_mandatory_files(): void {
        $this->resetAfterTest();
        set_config('mandatoryfileslist', '#content\.xml$#', 'exelearning');
        set_config('forbiddenfileslist', '', 'exelearning');

        $ok = [(object) ['pathname' => 'a/content.xml', 'is_directory' => false]];
        $this->assertSame([], exelearning_package_legacy::validate_file_list($ok));

        $missing = [(object) ['pathname' => 'a/index.html', 'is_directory' => false]];
        $this->assertArrayHasKey('packagefile', exelearning_package_legacy::validate_file_list($missing));
    }

    /**
     * A forbidden-file regex match rejects the list.
     */
    public function test_validate_file_list_rejects_forbidden_files(): void {
        $this->resetAfterTest();
        set_config('mandatoryfileslist', '', 'exelearning');
        set_config('forbiddenfileslist', '#\.exe$#', 'exelearning');

        $bad = [(object) ['pathname' => 'evil.exe', 'is_directory' => false]];
        $this->assertArrayHasKey('packagefile', exelearning_package_legacy::validate_file_list($bad));

        $good = [(object) ['pathname' => 'safe.html', 'is_directory' => false]];
        $this->assertSame([], exelearning_package_legacy::validate_file_list($good));
    }

    /**
     * A real .elpx package is recognised, validates clean and expands into the
     * content file area; the main entry file is then resolvable.
     */
    public function test_validate_and_expand_real_elpx(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('mandatoryfileslist', '', 'exelearning');
        set_config('forbiddenfileslist', '', 'exelearning');

        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->get_plugin_generator('mod_exelearning')
            ->create_instance(['course' => $course->id]);
        $cm = get_coursemodule_from_instance('exelearning', $instance->id);
        $context = \context_module::instance($cm->id);

        $fs = get_file_storage();
        $file = $fs->create_file_from_pathname([
            'contextid' => $context->id,
            'component' => 'mod_exelearning',
            'filearea'  => 'packagetest',
            'itemid'    => 0,
            'filepath'  => '/',
            'filename'  => 'package.elpx',
        ], $CFG->dirroot . '/mod/exelearning/research/fixtures/elpx/actividad-evaluable.elpx');

        $this->assertTrue(exelearning_package_legacy::is_valid_package_file($file));
        $this->assertSame([], exelearning_package_legacy::validate_package($file));

        $contentlist = exelearning_package_legacy::expand_package($file);
        $this->assertIsArray($contentlist);
        $this->assertNotEmpty($contentlist);

        $mainfile = exelearning_package_legacy::get_mainfile($contentlist, $context->id, $file->get_itemid());
        $this->assertNotFalse($mainfile);
        $this->assertSame('index.html', $mainfile->get_filename());
    }

    /**
     * save_draft_file() moves an uploaded draft into the activity's package area.
     */
    public function test_save_draft_file_stores_package(): void {
        global $CFG, $USER;
        require_once($CFG->libdir . '/resourcelib.php');
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->get_plugin_generator('mod_exelearning')
            ->create_instance(['course' => $course->id]);
        $cm = get_coursemodule_from_instance('exelearning', $instance->id);

        // A draft area holding the fixture .elpx, as the upload form would build.
        $draftid = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_pathname([
            'contextid' => \context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea'  => 'draft',
            'itemid'    => $draftid,
            'filepath'  => '/',
            'filename'  => 'pkg.elpx',
        ], $CFG->dirroot . '/mod/exelearning/research/fixtures/elpx/actividad-evaluable.elpx');

        $data = (object) [
            'coursemodule' => $cm->id,
            'packagefile'  => $draftid,
            'display'      => 0,
            'revision'     => 7,
        ];

        $package = exelearning_package_legacy::save_draft_file($data);

        $this->assertInstanceOf(\stored_file::class, $package);
        $this->assertSame('pkg.elpx', $package->get_filename());
    }

    /**
     * is_valid_package_file() rejects a plain non-archive file.
     */
    public function test_is_valid_package_file_rejects_non_zip(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $file = get_file_storage()->create_file_from_string([
            'contextid' => \context_system::instance()->id,
            'component' => 'mod_exelearning',
            'filearea'  => 'test',
            'itemid'    => 0,
            'filepath'  => '/',
            'filename'  => 'notes.txt',
        ], 'plain text, not a package');

        $this->assertFalse(exelearning_package_legacy::is_valid_package_file($file));
    }

    /**
     * validate_file_list() strips the wrapping directory when the package is
     * nested under a single top-level folder.
     */
    public function test_validate_file_list_strips_wrapping_directory(): void {
        $this->resetAfterTest();
        set_config('mandatoryfileslist', '#content\.xml$#', 'exelearning');
        set_config('forbiddenfileslist', '', 'exelearning');

        $list = [
            (object) ['pathname' => 'pkg/', 'is_directory' => true],
            (object) ['pathname' => 'pkg/content.xml', 'is_directory' => false],
        ];

        // The content.xml is found after the 'pkg/' prefix is stripped.
        $this->assertSame([], exelearning_package_legacy::validate_file_list($list));
    }

    /**
     * validate_package() rejects a file that is not a valid package archive.
     */
    public function test_validate_package_rejects_non_package(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $file = get_file_storage()->create_file_from_string([
            'contextid' => \context_system::instance()->id,
            'component' => 'mod_exelearning',
            'filearea'  => 'test',
            'itemid'    => 0,
            'filepath'  => '/',
            'filename'  => 'bad.txt',
        ], 'not a package');

        $this->assertArrayHasKey('packagefile', exelearning_package_legacy::validate_package($file));
    }
}
