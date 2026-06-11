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
}
