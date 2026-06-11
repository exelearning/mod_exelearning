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
use mod_exelearning\local\embedded_editor_source_resolver as resolver;

/**
 * Tests for the embedded editor source resolver (precedence moodledata -> bundled -> none).
 *
 * @package    mod_exelearning
 * @category   test
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_exelearning\local\embedded_editor_source_resolver
 */
final class embedded_editor_source_resolver_test extends advanced_testcase {
    /**
     * Build a minimal valid static editor layout (index.html + one asset dir).
     *
     * @param string $dir Absolute path to populate.
     * @return void
     */
    private function make_valid_editor(string $dir): void {
        make_writable_directory($dir);
        make_writable_directory($dir . '/app');
        file_put_contents($dir . '/index.html', '<!doctype html><title>editor</title>');
    }

    /**
     * validate_editor_dir() requires both index.html and an expected asset dir.
     */
    public function test_validate_editor_dir_requires_index_and_asset_dir(): void {
        $base = make_temp_directory('mod_exelearning/resolver-' . uniqid());

        // A non-existent directory is invalid.
        $this->assertFalse(resolver::validate_editor_dir($base . '/nope'));

        // An index.html with no asset dir alongside it is invalid.
        $noassets = $base . '/noassets';
        make_writable_directory($noassets);
        file_put_contents($noassets . '/index.html', 'x');
        $this->assertFalse(resolver::validate_editor_dir($noassets));

        // An asset dir present but no index.html is invalid.
        $noindex = $base . '/noindex';
        make_writable_directory($noindex . '/libs');
        $this->assertFalse(resolver::validate_editor_dir($noindex));

        // An index.html plus one of app/libs/files is valid.
        $valid = $base . '/valid';
        $this->make_valid_editor($valid);
        $this->assertTrue(resolver::validate_editor_dir($valid));

        remove_dir($base);
    }

    /**
     * An admin-installed editor in moodledata takes precedence over the bundled copy.
     */
    public function test_get_active_source_prefers_moodledata(): void {
        $this->resetAfterTest();

        $moodledatadir = resolver::get_moodledata_dir();
        $this->make_valid_editor($moodledatadir);

        $this->assertSame(resolver::SOURCE_MOODLEDATA, resolver::get_active_source());
        $this->assertSame($moodledatadir, resolver::get_active_dir());
        $this->assertTrue(resolver::has_local_source());
        $this->assertSame($moodledatadir . '/index.html', resolver::get_index_source());

        remove_dir($moodledatadir);
    }

    /**
     * get_status() reports the resolved paths and a clean fresh-site state.
     */
    public function test_get_status_reports_paths_and_availability(): void {
        $this->resetAfterTest();

        $status = resolver::get_status();

        $this->assertContains($status->active_source, [
            resolver::SOURCE_MOODLEDATA,
            resolver::SOURCE_BUNDLED,
            resolver::SOURCE_NONE,
        ]);
        // A fresh site has no admin-installed editor recorded.
        $this->assertFalse($status->moodledata_available);
        $this->assertNull($status->moodledata_version);
        $this->assertSame(resolver::get_moodledata_dir(), $status->moodledata_dir);
        $this->assertSame(resolver::get_bundled_dir(), $status->bundled_dir);
    }
}
