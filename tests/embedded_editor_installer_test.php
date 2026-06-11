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
use mod_exelearning\local\embedded_editor_installer;
use mod_exelearning\local\embedded_editor_source_resolver;

/**
 * Tests for the embedded editor installer.
 *
 * @package    mod_exelearning
 * @category   test
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_exelearning\local\embedded_editor_installer
 */
final class embedded_editor_installer_test extends advanced_testcase {
    /**
     * The GitHub Releases API asset digest is parsed for the static editor ZIP.
     */
    public function test_extract_asset_sha256_from_release_api(): void {
        $installer = new embedded_editor_installer();
        $json = json_encode([
            'assets' => [
                [
                    'name' => 'other.zip',
                    'digest' => 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
                ],
                [
                    'name' => 'exelearning-static-v4.0.0.zip',
                    'digest' => 'sha256:cf7be75ad356dc0f69f847ada8fc6a0677eb426db93e07405e0decdc9a040e2e',
                ],
            ],
        ]);

        $this->assertSame(
            'cf7be75ad356dc0f69f847ada8fc6a0677eb426db93e07405e0decdc9a040e2e',
            $installer->extract_asset_sha256_from_release_api($json, 'exelearning-static-v4.0.0.zip')
        );
    }

    /**
     * Missing or malformed GitHub digests are not silently accepted.
     */
    public function test_extract_asset_sha256_requires_valid_digest(): void {
        $installer = new embedded_editor_installer();

        $this->assertNull($installer->extract_asset_sha256_from_release_api('not-json', 'asset.zip'));
        $this->assertNull($installer->extract_asset_sha256_from_release_api(
            json_encode(['assets' => [['name' => 'asset.zip']]]),
            'asset.zip'
        ));
        $this->assertNull($installer->extract_asset_sha256_from_release_api(
            json_encode(['assets' => [['name' => 'asset.zip', 'digest' => 'sha256:not-a-hash']]]),
            'asset.zip'
        ));
    }

    /**
     * Downloaded ZIP bytes must match the release digest before extraction.
     */
    public function test_verify_file_sha256_rejects_mismatch(): void {
        $installer = new embedded_editor_installer();
        $file = make_temp_directory('mod_exelearning') . '/digest-test.zip';
        file_put_contents($file, 'zip bytes');

        $installer->verify_file_sha256($file, hash('sha256', 'zip bytes'));

        $this->expectException(\moodle_exception::class);
        $installer->verify_file_sha256(
            $file,
            '0000000000000000000000000000000000000000000000000000000000000000'
        );
    }

    /**
     * Build a minimal valid static-editor ZIP (index.html + one asset dir).
     *
     * @return string Absolute path to the created ZIP.
     */
    private function make_editor_zip(): string {
        $zippath = make_temp_directory('mod_exelearning') . '/editor-' . random_string(8) . '.zip';
        $zip = new \ZipArchive();
        $zip->open($zippath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('index.html', '<!doctype html><title>editor</title>');
        $zip->addFromString('app/main.js', 'console.log(1);');
        $zip->close();
        return $zippath;
    }

    /**
     * A local ZIP installs into moodledata, becomes the active source and then
     * uninstalls cleanly — exercising the full offline install pipeline
     * (validate -> extract -> normalize -> validate contents -> safe install).
     */
    public function test_install_from_local_zip_then_uninstall(): void {
        $this->resetAfterTest();
        $installer = new embedded_editor_installer();

        $result = $installer->install_from_local_zip($this->make_editor_zip(), '9.9.9');

        $this->assertSame('9.9.9', $result['version']);
        $this->assertSame(
            embedded_editor_source_resolver::SOURCE_MOODLEDATA,
            embedded_editor_source_resolver::get_active_source()
        );
        $this->assertSame('9.9.9', embedded_editor_source_resolver::get_moodledata_version());

        $installer->uninstall();

        $this->assertNull(embedded_editor_source_resolver::get_moodledata_version());
        $this->assertNotSame(
            embedded_editor_source_resolver::SOURCE_MOODLEDATA,
            embedded_editor_source_resolver::get_active_source()
        );
    }

    /**
     * A file without the ZIP magic bytes is rejected before any extraction.
     */
    public function test_validate_zip_rejects_non_zip(): void {
        $installer = new embedded_editor_installer();
        $file = make_temp_directory('mod_exelearning') . '/not-a-zip-' . random_string(6) . '.bin';
        file_put_contents($file, 'this is plainly not a zip archive');

        $this->expectException(\moodle_exception::class);
        $installer->validate_zip($file);
    }

    /**
     * The GitHub asset filename and download URL are derived from the version.
     */
    public function test_asset_filename_and_url(): void {
        $installer = new embedded_editor_installer();

        $this->assertSame('exelearning-static-v4.0.0.zip', $installer->get_asset_filename('4.0.0'));
        $this->assertSame(
            'https://github.com/exelearning/exelearning/releases/download/v4.0.0/exelearning-static-v4.0.0.zip',
            $installer->get_asset_url('4.0.0')
        );
    }

    /**
     * normalize_extraction() finds index.html at the extraction root, one level
     * down or two levels down, and rejects a layout that has none.
     */
    public function test_normalize_extraction_handles_nesting(): void {
        $installer = new embedded_editor_installer();

        // Pattern 1: index.html directly at the extraction root.
        $root = make_temp_directory('mod_exelearning/norm-root-' . random_string(6));
        file_put_contents($root . '/index.html', 'x');
        $this->assertSame($root, $installer->normalize_extraction($root));

        // Pattern 2: a single wrapper directory holding index.html.
        $wrap = make_temp_directory('mod_exelearning/norm-wrap-' . random_string(6));
        make_writable_directory($wrap . '/inner');
        file_put_contents($wrap . '/inner/index.html', 'x');
        $this->assertSame($wrap . '/inner', $installer->normalize_extraction($wrap));

        // Pattern 3: a double-nested wrapper.
        $deep = make_temp_directory('mod_exelearning/norm-deep-' . random_string(6));
        make_writable_directory($deep . '/a/b');
        file_put_contents($deep . '/a/b/index.html', 'x');
        $this->assertSame($deep . '/a/b', $installer->normalize_extraction($deep));

        // No index.html anywhere is rejected.
        $bad = make_temp_directory('mod_exelearning/norm-bad-' . random_string(6));
        make_writable_directory($bad . '/x');
        file_put_contents($bad . '/x/readme.txt', 'x');
        $this->expectException(\moodle_exception::class);
        $installer->normalize_extraction($bad);
    }

    /**
     * validate_editor_contents() accepts a valid editor directory.
     */
    public function test_validate_editor_contents_accepts_valid_layout(): void {
        $installer = new embedded_editor_installer();
        $dir = make_temp_directory('mod_exelearning/vc-ok-' . random_string(6));
        make_writable_directory($dir . '/app');
        file_put_contents($dir . '/index.html', 'x');

        $installer->validate_editor_contents($dir);
        // Reaching here without an exception means the layout passed.
        $this->expectNotToPerformAssertions();
    }

    /**
     * validate_editor_contents() rejects a directory missing an asset folder.
     */
    public function test_validate_editor_contents_rejects_invalid_layout(): void {
        $installer = new embedded_editor_installer();
        $dir = make_temp_directory('mod_exelearning/vc-bad-' . random_string(6));
        file_put_contents($dir . '/index.html', 'x');

        $this->expectException(\moodle_exception::class);
        $installer->validate_editor_contents($dir);
    }
}
