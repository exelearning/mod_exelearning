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
use mod_exelearning\local\styles_service;

/**
 * Tests for the styles service safety guards and slug/upload helpers.
 *
 * @package    mod_exelearning
 * @category   test
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_exelearning\local\styles_service
 */
final class styles_service_test extends advanced_testcase {
    /**
     * is_unsafe_zip_entry() rejects traversal, absolute, scheme and backslash
     * entries (the shared zip-slip guard reused by the editor installer too).
     */
    public function test_is_unsafe_zip_entry(): void {
        // Safe relative paths inside the archive.
        $this->assertFalse(styles_service::is_unsafe_zip_entry('index.html'));
        $this->assertFalse(styles_service::is_unsafe_zip_entry('app/main.js'));
        $this->assertFalse(styles_service::is_unsafe_zip_entry('files/icons/a.svg'));

        // Unsafe: empty, parent traversal, absolute, backslash and URL schemes.
        $this->assertTrue(styles_service::is_unsafe_zip_entry(''));
        $this->assertTrue(styles_service::is_unsafe_zip_entry('../escape.txt'));
        $this->assertTrue(styles_service::is_unsafe_zip_entry('a/../../b'));
        $this->assertTrue(styles_service::is_unsafe_zip_entry('/etc/passwd'));
        $this->assertTrue(styles_service::is_unsafe_zip_entry('a\\b.txt'));
        $this->assertTrue(styles_service::is_unsafe_zip_entry('file:///etc/passwd'));
    }

    /**
     * is_allowed_filename() enforces the extension allow-list and permits
     * directory entries.
     */
    public function test_is_allowed_filename(): void {
        $this->assertTrue(styles_service::is_allowed_filename('style.css'));
        $this->assertTrue(styles_service::is_allowed_filename('app.js'));
        // The extension match is case-insensitive.
        $this->assertTrue(styles_service::is_allowed_filename('icon.PNG'));
        // A directory entry (trailing slash) is allowed.
        $this->assertTrue(styles_service::is_allowed_filename('fonts/'));

        // Disallowed extensions and extensionless files are blocked.
        $this->assertFalse(styles_service::is_allowed_filename('evil.php'));
        $this->assertFalse(styles_service::is_allowed_filename('nested.zip'));
        $this->assertFalse(styles_service::is_allowed_filename('noextension'));
    }

    /**
     * normalize_slug() lowercases, collapses unsafe characters to hyphens and
     * falls back to a safe default when nothing usable remains.
     */
    public function test_normalize_slug(): void {
        $this->assertSame('my-theme', styles_service::normalize_slug('My Theme!'));
        $this->assertSame('foo-bar-2', styles_service::normalize_slug('  Foo_Bar 2  '));
        $this->assertSame('already-good', styles_service::normalize_slug('already-good'));
        // Nothing usable falls back to the default slug.
        $this->assertSame('style', styles_service::normalize_slug('!!!'));
        $this->assertSame('style', styles_service::normalize_slug('   '));
    }

    /**
     * get_max_zip_size() returns the built-in default unless an admin override
     * is configured.
     */
    public function test_get_max_zip_size_default_and_override(): void {
        $this->resetAfterTest();

        $this->assertSame(styles_service::DEFAULT_MAX_ZIP_SIZE, styles_service::get_max_zip_size());

        set_config('styles_max_zip_size', 1234, 'exelearning');
        $this->assertSame(1234, styles_service::get_max_zip_size());
    }
}
