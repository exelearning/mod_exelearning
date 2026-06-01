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
}
